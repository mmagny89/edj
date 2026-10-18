<?php

namespace App\Tests\Import;

use App\Import\ImportException;
use App\Import\XlsxReader;
use PHPUnit\Framework\TestCase;

/**
 * Le lecteur de classeurs : ce qu'il lit, et surtout ce qu'il refuse. Le fichier
 * vient de l'exterieur et porte des donnees personnelles.
 */
final class XlsxReaderTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    /**
     * @param list<array<int, string|int|float|null>> $rows
     * @param array<string, string|null>              $overrides
     */
    private function build(array $rows, array $overrides = []): string
    {
        return $this->files[] = XlsxFixture::build($rows, $overrides);
    }

    public function testItReadsTextNumbersAndKeepsTheirTypes(): void
    {
        $rows = (new XlsxReader())->read($this->build([['NOM', 'DATE'], ['Durand', 45930], ['Lefèvre', 1.5]]));

        self::assertSame('NOM', $rows[0][0]);
        self::assertSame('Durand', $rows[1][0]);
        // Un nombre reste un nombre : c'est ce qui fait reconnaitre une date d'Excel.
        self::assertSame(45930, $rows[1][1]);
        self::assertSame(1.5, $rows[2][1]);
    }

    /**
     * Excel n'ecrit pas les cellules vides : les colonnes se lisent par leur
     * reference, pas par leur rang dans la ligne.
     */
    public function testColumnsAreReadByReferenceWhenCellsAreMissing(): void
    {
        $rows = (new XlsxReader())->read($this->build([[0 => 'A', 3 => 'D', 27 => 'AB']]));

        self::assertSame([0 => 'A', 3 => 'D', 27 => 'AB'], $rows[0]);
    }

    public function testEmptyCellsAreAbsent(): void
    {
        $rows = (new XlsxReader())->read($this->build([['x', null, 'z']]));

        self::assertSame([0 => 'x', 2 => 'z'], $rows[0]);
    }

    public function testOnlyTheFirstSheetIsRead(): void
    {
        $workbook = '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="A" sheetId="1" r:id="rId1"/><sheet name="B" sheetId="2" r:id="rId9"/></sheets></workbook>';
        $rows = (new XlsxReader())->read($this->build([['premiere']], ['xl/workbook.xml' => $workbook]));

        self::assertSame('premiere', $rows[0][0]);
    }

    public function testRichTextAndFormulaStringsAreReadWhole(): void
    {
        $sheet = '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="str"><f>A1</f><v>résultat</v></c><c r="C1" t="inlineStr"><is><t>en ligne</t></is></c><c r="D1" t="e"><v>#DIV/0!</v></c></row></sheetData></worksheet>';
        $strings = '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><r><t>Mar</t></r><r><t>tin</t></r></si></sst>';

        $rows = (new XlsxReader())->read($this->build([], ['xl/worksheets/sheet1.xml' => $sheet, 'xl/sharedStrings.xml' => $strings]));

        self::assertSame('Martin', $rows[0][0], 'Un texte mis en forme est recolle.');
        self::assertSame('résultat', $rows[0][1]);
        self::assertSame('en ligne', $rows[0][2]);
        self::assertArrayNotHasKey(3, $rows[0], 'Une erreur de formule n\'est pas une valeur.');
    }

    // ------------------------------------------------------------------
    // Ce qu'il refuse
    // ------------------------------------------------------------------

    public function testAMissingFileIsRefused(): void
    {
        $this->expectException(ImportException::class);

        (new XlsxReader())->read('/nulle/part.xlsx');
    }

    public function testSomethingThatIsNotAZipIsRefused(): void
    {
        $path = $this->files[] = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($path, 'ceci est un fichier texte');

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('classeur Excel');

        (new XlsxReader())->read($path);
    }

    public function testAZipThatIsNotAWorkbookIsRefused(): void
    {
        $this->expectException(ImportException::class);

        (new XlsxReader())->read($this->build([['x']], ['xl/workbook.xml' => null]));
    }

    public function testAnOversizedFileIsRefusedBeforeBeingRead(): void
    {
        $path = $this->files[] = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($path, str_repeat('0', XlsxReader::MAX_FILE_BYTES + 1));

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('5 Mo');

        (new XlsxReader())->read($path);
    }

    /**
     * Une bombe de decompression : quelques ko d'archive, des dizaines de Mo une fois
     * ouverte. La taille declaree est lue avant de decompresser quoi que ce soit.
     */
    public function testADecompressionBombIsRefused(): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('démesuré');

        (new XlsxReader())->read($this->build([['x']], ['xl/worksheets/sheet1.xml' => str_repeat('0', XlsxReader::MAX_PART_BYTES + 1)]));
    }

    /**
     * Un document XML qui declare des entites sert a lire des fichiers locaux ou a
     * gonfler en memoire ; un classeur Excel n'en declare jamais.
     */
    public function testAnXmlEntityDeclarationIsRefused(): void
    {
        $hostile = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY lire SYSTEM "file:///etc/passwd">]><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>&lire;</t></is></c></row></sheetData></worksheet>';

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('interdite');

        (new XlsxReader())->read($this->build([['x']], ['xl/worksheets/sheet1.xml' => $hostile]));
    }

    public function testTooManyRowsAreRefused(): void
    {
        $rows = array_fill(0, XlsxReader::MAX_ROWS + 1, ['x']);

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('lignes');

        (new XlsxReader())->read($this->build($rows));
    }

    public function testTooManyColumnsAreRefused(): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('colonnes');

        (new XlsxReader())->read($this->build([[XlsxReader::MAX_COLUMNS => 'x']]));
    }

    /**
     * Le chemin de la feuille vient du fichier : il ne doit pas pouvoir sortir de
     * l'archive.
     */
    public function testASheetPathEscapingTheArchiveIsRefused(): void
    {
        $rels = '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="worksheet" Target="../../etc/passwd"/></Relationships>';

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('chemin interdit');

        (new XlsxReader())->read($this->build([['x']], ['xl/_rels/workbook.xml.rels' => $rels]));
    }

    public function testMalformedXmlIsRefusedWithoutAPhpWarning(): void
    {
        $this->expectException(ImportException::class);

        (new XlsxReader())->read($this->build([['x']], ['xl/worksheets/sheet1.xml' => '<worksheet><sheetData><row></worksheet>']));
    }
}
