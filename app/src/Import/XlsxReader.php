<?php

namespace App\Import;

/**
 * Lit la premiere feuille d'un classeur Excel (.xlsx), sans dependance.
 *
 * Un classeur est une archive zip de fichiers XML. Pour importer une liste
 * d'adherents il suffit d'en lire les valeurs — pas les styles, pas les formules
 * (leur resultat est deja dans le fichier), pas les graphiques. Une bibliotheque
 * complete saurait tout cela, et traine avec elle beaucoup de code qui n'a rien a
 * faire ici : le fichier vient de l'exterieur et porte des donnees personnelles.
 *
 * Ce lecteur est donc volontairement etroit, et se protege de ce qu'un fichier
 * hostile peut contenir :
 *  - une archive trop grosse, ou qui se decompresse en quelque chose de trop gros
 *    (la taille declaree est verifiee avant de lire) ;
 *  - un XML qui declare des entites (attaque par expansion, lecture de fichiers
 *    locaux) : tout document avec DOCTYPE est refuse, le reseau est coupe ;
 *  - une feuille demesuree : lignes et colonnes sont plafonnees.
 *
 * Les valeurs numeriques restent des nombres, les textes des chaines : c'est ce
 * qui permet de reconnaitre une date d'Excel, stockee comme un nombre de jours.
 */
final class XlsxReader
{
    public const MAX_FILE_BYTES = 5_000_000;
    public const MAX_PART_BYTES = 20_000_000;
    public const MAX_ROWS = 5000;
    public const MAX_COLUMNS = 80;

    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const NS_PKG_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /**
     * @return list<array<int, string|int|float>> une ligne par ligne du fichier ;
     *                                            colonne => valeur, les cellules vides sont absentes
     *
     * @throws ImportException
     */
    public function read(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ImportException('Le fichier est introuvable.');
        }
        if (filesize($path) > self::MAX_FILE_BYTES) {
            throw new ImportException('Le fichier dépasse 5 Mo : une liste d\'adhérents n\'en demande pas autant.');
        }

        $zip = new \ZipArchive();
        if (true !== $zip->open($path, \ZipArchive::RDONLY)) {
            throw new ImportException('Ce fichier n\'est pas un classeur Excel (.xlsx) valide.');
        }

        try {
            $this->checkArchive($zip);

            $sheetPath = $this->firstSheetPath($zip);
            $shared = $this->sharedStrings($zip);

            return $this->rows($this->part($zip, $sheetPath), $shared);
        } finally {
            $zip->close();
        }
    }

    private function checkArchive(\ZipArchive $zip): void
    {
        if ($zip->numFiles > 400) {
            throw new ImportException('Ce classeur contient trop de fichiers pour être celui d\'une simple liste.');
        }

        $total = 0;
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $stat = $zip->statIndex($i);
            if (false === $stat) {
                continue;
            }
            if ($stat['size'] > self::MAX_PART_BYTES) {
                throw new ImportException('Ce classeur se décompresse en un fichier démesuré : il est refusé.');
            }
            $total += $stat['size'];
        }
        if ($total > 4 * self::MAX_PART_BYTES) {
            throw new ImportException('Ce classeur se décompresse en un volume démesuré : il est refusé.');
        }
        if (false === $zip->locateName('xl/workbook.xml')) {
            throw new ImportException('Ce fichier n\'est pas un classeur Excel (.xlsx) : il manque son classeur.');
        }
    }

    private function part(\ZipArchive $zip, string $name): \SimpleXMLElement
    {
        $content = $zip->getFromName($name);
        if (false === $content) {
            throw new ImportException(sprintf('Le classeur est incomplet : « %s » manque.', $name));
        }

        // Une declaration de type de document sert a definir des entites : un
        // classeur Excel n'en a jamais.
        if (preg_match('/<!(DOCTYPE|ENTITY)/i', $content)) {
            throw new ImportException('Ce classeur contient une déclaration XML interdite : il est refusé.');
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($content, \SimpleXMLElement::class, \LIBXML_NONET | \LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (false === $xml) {
            throw new ImportException(sprintf('Le classeur est abîmé : « %s » est illisible.', $name));
        }

        return $xml;
    }

    private function firstSheetPath(\ZipArchive $zip): string
    {
        $workbook = $this->part($zip, 'xl/workbook.xml');
        $workbook->registerXPathNamespace('m', self::NS_MAIN);
        $sheets = $workbook->xpath('//m:sheets/m:sheet');
        if (!$sheets) {
            throw new ImportException('Ce classeur ne contient aucune feuille.');
        }

        $relationId = (string) $sheets[0]->attributes(self::NS_REL)['id'];

        $relations = $this->part($zip, 'xl/_rels/workbook.xml.rels');
        $relations->registerXPathNamespace('r', self::NS_PKG_REL);
        foreach ($relations->xpath('//r:Relationship') ?: [] as $relation) {
            if ((string) $relation['Id'] === $relationId) {
                $target = (string) $relation['Target'];
                $target = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;

                // Un chemin relatif ne sort pas de l'archive.
                if (str_contains($target, '..')) {
                    throw new ImportException('Le classeur désigne un chemin interdit : il est refusé.');
                }

                return $target;
            }
        }

        throw new ImportException('Le classeur est abîmé : sa première feuille est introuvable.');
    }

    /**
     * @return list<string>
     */
    private function sharedStrings(\ZipArchive $zip): array
    {
        if (false === $zip->locateName('xl/sharedStrings.xml')) {
            return [];
        }

        $xml = $this->part($zip, 'xl/sharedStrings.xml');
        $xml->registerXPathNamespace('m', self::NS_MAIN);

        $strings = [];
        foreach ($xml->xpath('//m:si') ?: [] as $item) {
            $item->registerXPathNamespace('m', self::NS_MAIN);
            // Un texte peut etre decoupe en morceaux de mise en forme : on les
            // recolle.
            $strings[] = implode('', array_map('strval', $item->xpath('.//m:t') ?: []));
        }

        return $strings;
    }

    /**
     * @param list<string> $shared
     *
     * @return list<array<int, string|int|float>>
     */
    private function rows(\SimpleXMLElement $sheet, array $shared): array
    {
        $sheet->registerXPathNamespace('m', self::NS_MAIN);

        $rows = [];
        $count = 0;
        foreach ($sheet->xpath('//m:sheetData/m:row') ?: [] as $row) {
            if (++$count > self::MAX_ROWS) {
                throw new ImportException(sprintf('Le fichier dépasse %d lignes : coupez-le en plusieurs.', self::MAX_ROWS));
            }

            $cells = [];
            $position = 0;
            foreach ($row->c as $cell) {
                // Une cellule peut porter sa reference (« C7 ») ; sinon elle suit la precedente.
                $index = isset($cell['r']) ? $this->columnIndex((string) $cell['r']) : $position;
                $position = $index + 1;

                if ($index >= self::MAX_COLUMNS) {
                    throw new ImportException(sprintf('Le fichier dépasse %d colonnes.', self::MAX_COLUMNS));
                }

                $value = $this->value($cell, $shared);
                if (null !== $value && '' !== $value) {
                    $cells[$index] = $value;
                }
            }

            $rows[] = $cells;
        }

        return $rows;
    }

    /**
     * @param list<string> $shared
     */
    private function value(\SimpleXMLElement $cell, array $shared): string|int|float|null
    {
        $type = (string) ($cell['t'] ?? '');

        if ('inlineStr' === $type) {
            return trim(implode('', array_map('strval', $cell->xpath('.//*[local-name()="t"]') ?: [])));
        }

        if (!isset($cell->v)) {
            return null;
        }
        $raw = (string) $cell->v;

        return match ($type) {
            's' => isset($shared[(int) $raw]) ? trim($shared[(int) $raw]) : null,
            // Resultat texte d'une formule.
            'str' => trim($raw),
            'b' => '1' === $raw ? 1 : 0,
            // Erreur (#DIV/0!, #REF!...) : une valeur qui n'en est pas une.
            'e' => null,
            default => is_numeric($raw) ? (str_contains($raw, '.') || str_contains(strtolower($raw), 'e') ? (float) $raw : (int) $raw) : trim($raw),
        };
    }

    private function columnIndex(string $reference): int
    {
        if (!preg_match('/^([A-Z]+)/', strtoupper($reference), $m)) {
            return 0;
        }

        $index = 0;
        foreach (str_split($m[1]) as $letter) {
            $index = $index * 26 + (\ord($letter) - 64);
        }

        return $index - 1;
    }
}
