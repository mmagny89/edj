<?php

namespace App\Tests\Import;

/**
 * Fabrique de petits classeurs .xlsx pour les tests : une archive zip de quelques
 * fichiers XML, assez fidele a ce qu'ecrit Excel pour que le lecteur s'y retrouve.
 */
final class XlsxFixture
{
    /**
     * @param list<array<int, string|int|float|null>> $rows        ligne => colonne => valeur ; une chaine devient un texte partage
     * @param array<string, string>                   $overrides   contenu a remplacer, par nom de fichier de l'archive
     */
    public static function build(array $rows, array $overrides = [], ?string $path = null): string
    {
        $path ??= tempnam(sys_get_temp_dir(), 'xlsx');

        $shared = [];
        $sheetRows = '';
        foreach ($rows as $number => $row) {
            $cells = '';
            foreach ($row as $column => $value) {
                if (null === $value) {
                    continue;
                }
                $reference = self::letters($column).($number + 1);
                if (\is_string($value)) {
                    $shared[] = $value;
                    $cells .= sprintf('<c r="%s" t="s"><v>%d</v></c>', $reference, \count($shared) - 1);
                } else {
                    $cells .= sprintf('<c r="%s"><v>%s</v></c>', $reference, $value);
                }
            }
            $sheetRows .= sprintf('<row r="%d">%s</row>', $number + 1, $cells);
        }

        $strings = '';
        foreach ($shared as $text) {
            $strings .= '<si><t>'.htmlspecialchars($text, \ENT_XML1).'</t></si>';
        }

        $files = [
            '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
            'xl/workbook.xml' => '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="2026" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
            'xl/sharedStrings.xml' => '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.$strings.'</sst>',
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$sheetRows.'</sheetData></worksheet>',
        ];

        foreach ($overrides as $name => $content) {
            if (null === $content) {
                unset($files[$name]);
            } else {
                $files[$name] = $content;
            }
        }

        @unlink($path);
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    private static function letters(int $index): string
    {
        $letters = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letters = \chr(65 + ($n - 1) % 26).$letters;
        }

        return $letters;
    }
}
