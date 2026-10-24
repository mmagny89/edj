<?php

namespace App\Tests\Import;

use App\Entity\Member;
use App\Entity\MembershipApplication;
use App\Enum\MembershipSource;
use App\Enum\MembershipType;
use App\Enum\PaymentMethod;
use App\Import\ImportException;
use App\Import\ImportOptions;
use App\Import\MembershipImporter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * L'import d'une liste d'adherents depuis un tableur.
 *
 * Le fichier de ces tests reprend les colonnes de celui de l'association — dont celles
 * que l'import laisse de cote — avec des personnes inventees, en @import.example.test.
 */
final class MembershipImporterTest extends KernelTestCase
{
    private const HEADER = ['DATE ADHESION', 'NOM', 'PRENOM', 'SEXE', 'NAISSANCE', 'AGE', 'ADRESSE', 'CP', 'VILLE', 'JOIGNY/CCJ', 'EMAIL', 'TELEPHONE', 'TEL. WHATSAPP', 'PROFIL FB', 'TYPE', 'IMAGE', 'WHATSAPP', 'FACEBOOK', 'NEWSLETTER', 'PHILIBERT', '', 'COTISATION', '', 'PAIEMENT'];

    private EntityManagerInterface $entityManager;

    private MembershipImporter $importer;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->importer = static::getContainer()->get(MembershipImporter::class);
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function purge(): void
    {
        // Par source autant que par adresse : un adherent importe sans e-mail n'a
        // aucune adresse a laquelle se reconnaitre.
        $this->entityManager->createQuery('DELETE FROM '.MembershipApplication::class.' a WHERE a.email LIKE :d OR a.source = :import')
            ->setParameter('d', '%@import.example.test')
            ->setParameter('import', MembershipSource::Import)
            ->execute();
        $this->entityManager->createQuery('DELETE FROM '.Member::class.' m WHERE NOT EXISTS (SELECT 1 FROM '.MembershipApplication::class.' a WHERE a.member = m)')->execute();
        $this->entityManager->clear();
    }

    private static function serial(string $date): int
    {
        return (int) (new \DateTimeImmutable('1899-12-30'))->diff(new \DateTimeImmutable($date))->days;
    }

    /**
     * Une ligne de fichier : on ne precise que ce qui change du cas nominal.
     *
     * @param array<string, string|int|float|null> $values
     *
     * @return array<int, string|int|float|null>
     */
    private function row(array $values = []): array
    {
        static $counter = 0;
        ++$counter;
        $values += [
            'DATE ADHESION' => self::serial('now'),
            'NOM' => 'Durand'.$counter,
            'PRENOM' => 'Camille',
            'SEXE' => 'F',
            'NAISSANCE' => self::serial('1990-04-02'),
            'ADRESSE' => '3 rue des Jeux',
            'CP' => 89300,
            'VILLE' => 'Joigny',
            'JOIGNY/CCJ' => 1,
            'EMAIL' => 'camille'.$counter.'@import.example.test',
            'TELEPHONE' => '06 12 34 56 78',
            'TYPE' => 'IND',
            'IMAGE' => 0,
            'WHATSAPP' => 1,
            'FACEBOOK' => 1,
            'NEWSLETTER' => 1,
            'PHILIBERT' => 1,
            'COTISATION' => 10,
            'PAIEMENT' => 'Chèque',
        ];

        $row = [];
        foreach (self::HEADER as $index => $title) {
            if ('' !== $title && \array_key_exists($title, $values)) {
                $row[$index] = $values[$title];
            }
        }

        return $row;
    }

    /**
     * @param list<array<int, string|int|float|null>> $rows
     */
    private function file(array $rows, bool $withHeader = true): string
    {
        $all = $withHeader ? [array_values(self::HEADER) === [] ? [] : array_combine(array_keys(self::HEADER), array_map(static fn ($t) => '' === $t ? null : $t, self::HEADER)), ...$rows] : $rows;

        return $this->files[] = XlsxFixture::build($all);
    }

    private function options(bool $imageMeansRefusal = true): ImportOptions
    {
        return new ImportOptions($imageMeansRefusal, 7);
    }

    private function sequence(): int
    {
        return (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT last_value FROM member_number_seq');
    }

    // ------------------------------------------------------------------
    // Le cas nominal
    // ------------------------------------------------------------------

    public function testARowBecomesAValidatedBulletinUnderANewNumber(): void
    {
        $report = $this->importer->analyze($this->file([$this->row()]), $this->options());
        self::assertSame(1, $report->importable());
        self::assertFalse($report->hasErrors());

        self::assertSame(1, $this->importer->apply($report, $this->options()));

        $this->entityManager->clear();
        $saved = $this->entityManager->getRepository(MembershipApplication::class)->findOneBy(['city' => 'Joigny', 'membershipType' => MembershipType::Individual, 'source' => MembershipSource::Import]);
        self::assertNotNull($saved);
        self::assertMatchesRegularExpression('/^EDJ-\d{4,}$/', (string) $saved->getMemberNumber());
        self::assertTrue($saved->isValidated());
        self::assertSame(PaymentMethod::Cheque, $saved->getPaymentMethod());
        self::assertSame(7, $saved->getEnteredById());
        self::assertTrue($saved->isImported());
        self::assertSame('Import', $saved->getSource()->label());
    }

    /**
     * L'adhesion date de ce que dit le fichier, pas du jour de l'import : c'est la
     * date d'origine qui fixe la saison.
     */
    public function testTheAdhesionKeepsTheDateOfTheFileAndItsSeason(): void
    {
        $report = $this->importer->analyze($this->file([$this->row(['DATE ADHESION' => self::serial('2025-11-04')])]), $this->options());
        $this->importer->apply($report, $this->options());

        $this->entityManager->clear();
        $saved = $this->entityManager->getRepository(MembershipApplication::class)->findOneBy(['source' => MembershipSource::Import]);
        self::assertSame('2025-11-04', $saved->getSubmittedAt()->format('Y-m-d'));
        self::assertSame('2025-11-04', $saved->getStatusChangedAt()->format('Y-m-d'));
    }

    /**
     * Analyser n'ecrit rien — pas meme un numero d'adherent : la sequence n'avance pas.
     */
    public function testAnalysingWritesNothingNotEvenANumber(): void
    {
        $before = $this->sequence();
        $members = $this->entityManager->getRepository(Member::class)->count([]);

        $this->importer->analyze($this->file([$this->row(), $this->row(), $this->row()]), $this->options());

        self::assertSame($before, $this->sequence());
        self::assertSame($members, $this->entityManager->getRepository(Member::class)->count([]));
        self::assertSame(0, $this->entityManager->getRepository(MembershipApplication::class)->count(['source' => MembershipSource::Import]));
    }

    // ------------------------------------------------------------------
    // Le sens de la colonne IMAGE : le choix de l'administrateur
    // ------------------------------------------------------------------

    /**
     * @return iterable<string, array{bool, int, bool}>
     */
    public static function imageMeanings(): iterable
    {
        yield '1 = refus, valeur 1' => [true, 1, true];
        yield '1 = refus, valeur 0' => [true, 0, false];
        yield '1 = accord, valeur 1' => [false, 1, false];
        yield '1 = accord, valeur 0' => [false, 0, true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('imageMeanings')]
    public function testTheImageColumnFollowsTheChosenMeaning(bool $oneMeansRefusal, int $value, bool $expectedRefused): void
    {
        $report = $this->importer->analyze($this->file([$this->row(['IMAGE' => $value])]), $this->options($oneMeansRefusal));

        self::assertSame($expectedRefused, $report->rows[0]->application->isImageRightsRefused());
    }

    // ------------------------------------------------------------------
    // Ce qui est laisse de cote
    // ------------------------------------------------------------------

    public function testColumnsWithoutUseInTheFicheAreNamedAndLeftOut(): void
    {
        $report = $this->importer->analyze($this->file([$this->row()]), $this->options());

        foreach (['SEXE', 'WHATSAPP', 'FACEBOOK', 'NEWSLETTER', 'TEL. WHATSAPP', 'PROFIL FB', 'JOIGNY/CCJ', 'AGE'] as $column) {
            self::assertContains($column, $report->ignoredColumns);
        }
    }

    public function testAnUnknownColumnIsReportedNotSilentlyDropped(): void
    {
        $header = array_combine(array_keys(self::HEADER), array_map(static fn ($t) => '' === $t ? null : $t, self::HEADER));
        $header[30] = 'GROUPE SANGUIN';
        $path = $this->files[] = XlsxFixture::build([$header, $this->row()]);

        $report = $this->importer->analyze($path, $this->options());

        self::assertContains('GROUPE SANGUIN', $report->unknownColumns);
    }

    public function testHeadersAreRecognisedWhateverTheirCaseAndAccents(): void
    {
        $rows = [
            [0 => 'Date d\'adhésion', 1 => 'Nom', 2 => 'Prénom', 3 => 'Date de naissance', 4 => 'Code postal', 5 => 'Ville', 6 => 'E-mail', 7 => 'Formule'],
            [0 => self::serial('now'), 1 => 'Dupont', 2 => 'Zoé', 3 => self::serial('1985-03-02'), 4 => 89300, 5 => 'Joigny', 6 => 'zoe@import.example.test', 7 => 'individuelle'],
        ];

        $report = $this->importer->analyze($this->files[] = XlsxFixture::build($rows), $this->options());

        self::assertSame(1, $report->importable());
        self::assertSame('Zoé', $report->rows[0]->application->getFirstName());
    }

    /**
     * Excel et Google Sheets laissent derriere eux des caracteres qu'un oeil ne
     * voit pas : espace insecable (U+00A0), accents decomposes (e + U+0301),
     * espace de largeur nulle, espace en fin de cellule. Un en-tete present
     * etait alors annonce « manquant ».
     *
     * @return iterable<string, array{string, string}>
     */
    public static function invisibleHeaderNoise(): iterable
    {
        yield 'espace insecable en fin' => ["NAISSANCE\u{00A0}", 'birthDate'];
        yield 'espace insecable au milieu' => ["DATE\u{00A0}ADHESION", 'date'];
        yield 'accent decompose' => ["DATE ADHE\u{0301}SION", 'date'];
        yield 'espace de largeur nulle' => ["NAIS\u{200B}SANCE", 'birthDate'];
        yield 'marque d\'ordre des octets' => ["\u{FEFF}NAISSANCE", 'birthDate'];
        yield 'espaces doubles et fin' => ['  DATE   ADHESION  ', 'date'];
    }

    #[DataProvider('invisibleHeaderNoise')]
    public function testHeadersSurviveInvisibleCharacters(string $title, string $field): void
    {
        $header = ['NOM', 'PRENOM', 'NAISSANCE', 'CP', 'VILLE', 'EMAIL', 'TYPE', 'DATE ADHESION'];
        $row = ['Dupont', 'Zoé', self::serial('1985-03-02'), 89300, 'Joigny', 'bruit@import.example.test', 'individuelle', self::serial('now')];
        $target = 'birthDate' === $field ? 2 : 7;
        $header[$target] = $title;

        $report = $this->importer->analyze($this->files[] = XlsxFixture::build([$header, $row]), $this->options());

        self::assertSame(1, $report->importable());
    }

    // ------------------------------------------------------------------
    // Ce qui bloque, et ce qui avertit
    // ------------------------------------------------------------------

    /**
     * @return iterable<string, array{array<string, string|int|float|null>, string}>
     */
    public static function blocking(): iterable
    {
        yield 'date d\'adhesion absente' => [['DATE ADHESION' => null], 'date d\'adhésion'];
        yield 'naissance dans le futur' => [['NAISSANCE' => 90000 - 1000], 'naissance'];
        yield 'naissance illisible' => [['NAISSANCE' => 'hier'], 'naissance'];
        yield 'formule inconnue' => [['TYPE' => 'VIP'], 'formule'];
        yield 'ville numerique' => [['VILLE' => 0], 'ville'];
        yield 'code postal trop long' => [['CP' => 893000], 'code postal'];
        yield 'balise dans le nom' => [['NOM' => '<script>x</script>'], 'caractères'];
    }

    /**
     * @param array<string, string|int|float|null> $values
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('blocking')]
    public function testAnUnusableRowIsAnErrorWithItsLineNumber(array $values, string $expected): void
    {
        $report = $this->importer->analyze($this->file([$this->row($values)]), $this->options());

        self::assertSame(0, $report->importable());
        self::assertCount(1, $report->errors);
        // Ligne 1 du tableur : l'en-tete ; la ligne en cause est la 2.
        self::assertSame(2, $report->errors[0]['line']);
        self::assertStringContainsString($expected, $report->errors[0]['message']);
    }

    /**
     * Le fichier decrit des gens deja inscrits sur papier : on ne les refuse pas pour
     * une regle du bulletin en ligne. On avertit, pour completer ensuite.
     */
    /**
     * Une faute de frappe dans un courriel ne fait pas rejeter la personne : elle
     * est reprise sans l'adresse, avec un avertissement.
     */
    public function testAnUnreadableEmailIsAWarningAndTheFicheIsKept(): void
    {
        $report = $this->importer->analyze($this->file([$this->row(['EMAIL' => 'pas-un-courriel'])]), $this->options());

        self::assertSame(1, $report->importable());
        self::assertSame([], $report->errors);
        self::assertNull($report->rows[0]->application->getEmail());
        self::assertStringContainsString('e-mail illisible', implode(' ', $report->rows[0]->warnings));
    }

    /**
     * Les lignes valides s'importent meme quand d'autres sont rejetees.
     */
    public function testValidLinesImportEvenWhenOthersAreRejected(): void
    {
        $report = $this->importer->analyze(
            $this->file([$this->row(), $this->row(['NAISSANCE' => 'hier']), $this->row()]),
            $this->options(),
        );

        self::assertSame(2, $report->importable());
        self::assertCount(1, $report->errors);
        self::assertSame(2, $this->importer->apply($report, $this->options()));
    }

    public function testFormulaRulesAreWarningsNotErrors(): void
    {
        $report = $this->importer->analyze($this->file([
            $this->row(['TYPE' => 'FAM']),
            $this->row(['NAISSANCE' => self::serial('-10 years'), 'TYPE' => 'IND']),
        ]), $this->options());

        self::assertSame(2, $report->importable());
        self::assertSame([], $report->errors);
        self::assertStringContainsString('adhérent principal', $report->rows[0]->warnings[0]);
        self::assertNotEmpty(array_filter($report->rows[1]->warnings, static fn (string $w) => str_contains($w, 'mineur')));
    }

    /**
     * Beaucoup d'adherents d'un fichier papier n'ont pas d'adresse e-mail. Les
     * refuser, c'etait refuser un tiers du fichier : on les importe, avec un
     * avertissement.
     */
    public function testAMemberWithoutAnEmailIsImportedWithAWarning(): void
    {
        $report = $this->importer->analyze($this->file([$this->row(['EMAIL' => null])]), $this->options());

        self::assertSame(1, $report->importable());
        self::assertSame([], $report->errors);
        self::assertNull($report->rows[0]->application->getEmail());
        self::assertStringContainsString('pas d\'adresse e-mail', $report->rows[0]->warnings[0]);

        $this->importer->apply($report, $this->options());
        $this->entityManager->clear();
        self::assertSame(1, $this->entityManager->getRepository(MembershipApplication::class)->count(['email' => null, 'source' => MembershipSource::Import]));
    }

    /**
     * « -- » dans une colonne e-mail veut dire « pas d'adresse », pas « adresse mal
     * ecrite » : c'est ce que mettent les tableurs, et le fichier reel en est plein.
     */
    public function testAPlaceholderInTheEmailColumnMeansNoAddress(): void
    {
        foreach (['--', 'néant', 'n/a', '-'] as $placeholder) {
            $report = $this->importer->analyze($this->file([$this->row(['EMAIL' => $placeholder])]), $this->options());

            self::assertSame([], $report->errors, $placeholder);
            self::assertNull($report->rows[0]->application->getEmail(), $placeholder);
        }
    }

    public function testPhilibertForAConsumerIsDroppedWithAWarning(): void
    {
        $report = $this->importer->analyze($this->file([$this->row(['TYPE' => 'CONSO', 'PHILIBERT' => 1, 'COTISATION' => 0, 'PAIEMENT' => null])]), $this->options());

        self::assertFalse($report->rows[0]->application->isPhilibertConsent());
        self::assertNotEmpty(array_filter($report->rows[0]->warnings, static fn (string $w) => str_contains($w, 'consommateur')));
    }

    public function testAPlaceholderPhoneIsDroppedSilentlyAndAGarbledOneWithAWarning(): void
    {
        $report = $this->importer->analyze($this->file([$this->row(['TELEPHONE' => '--']), $this->row(['TELEPHONE' => '12'])]), $this->options());

        self::assertNull($report->rows[0]->application->getPhone());
        self::assertSame([], array_filter($report->rows[0]->warnings, static fn (string $w) => str_contains($w, 'téléphone')));
        self::assertNull($report->rows[1]->application->getPhone());
        self::assertNotEmpty(array_filter($report->rows[1]->warnings, static fn (string $w) => str_contains($w, 'téléphone')));
    }

    /**
     * Un tableur perd le zero initial d'un code postal : 8930 pour 08930. On le
     * rend, plutot que de refuser la ligne.
     */
    public function testALeadingZeroLostByTheSpreadsheetIsRestored(): void
    {
        $report = $this->importer->analyze($this->file([$this->row(['CP' => 8930])]), $this->options());

        self::assertSame('08930', $report->rows[0]->application->getPostalCode());
    }

    public function testAnAmountThatDoesNotMatchTheFormulaIsFlagged(): void
    {
        $report = $this->importer->analyze($this->file([$this->row(['COTISATION' => 15])]), $this->options());

        self::assertNotEmpty(array_filter($report->rows[0]->warnings, static fn (string $w) => str_contains($w, '15 €')));
    }

    // ------------------------------------------------------------------
    // L'etat de l'adhesion
    // ------------------------------------------------------------------

    public function testNoPaymentMeansPendingWhenSomethingIsDue(): void
    {
        $report = $this->importer->analyze($this->file([$this->row(['PAIEMENT' => null])]), $this->options());

        self::assertFalse($report->rows[0]->validated);
    }

    public function testNothingDueIsValidatedWithoutPayment(): void
    {
        $report = $this->importer->analyze($this->file([$this->row(['TYPE' => 'CONSO', 'PHILIBERT' => 0, 'COTISATION' => 0, 'PAIEMENT' => null])]), $this->options());

        self::assertTrue($report->rows[0]->validated);
        self::assertNull($report->rows[0]->payment);
    }

    /**
     * @return iterable<string, array{string, PaymentMethod}>
     */
    public static function payments(): iterable
    {
        yield 'liquide' => ['Liquide', PaymentMethod::Cash];
        yield 'especes' => ['Espèces', PaymentMethod::Cash];
        yield 'cheque' => ['Chèque', PaymentMethod::Cheque];
        yield 'virement' => ['VIREMENT', PaymentMethod::Transfer];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('payments')]
    public function testPaymentLabelsAreRecognised(string $label, PaymentMethod $expected): void
    {
        $report = $this->importer->analyze($this->file([$this->row(['PAIEMENT' => $label])]), $this->options());

        self::assertSame($expected, $report->rows[0]->payment);
    }

    // ------------------------------------------------------------------
    // Relancer, doublons, plusieurs saisons
    // ------------------------------------------------------------------

    /**
     * Relancer un import est sans danger : une personne deja inscrite pour la saison
     * est ecartee, pas dupliquee.
     */
    public function testRunningTheSameFileTwiceCreatesNothingTheSecondTime(): void
    {
        $path = $this->file([$this->row(), $this->row()]);
        $this->importer->apply($this->importer->analyze($path, $this->options()), $this->options());
        $this->entityManager->clear();

        $second = $this->importer->analyze($path, $this->options());

        self::assertSame(0, $second->importable());
        self::assertCount(2, $second->skipped);
        self::assertStringContainsString('déjà enregistré', $second->skipped[0]['reason']);
        self::assertSame(2, $this->entityManager->getRepository(MembershipApplication::class)->count(['source' => MembershipSource::Import]));
    }

    public function testTheSamePersonTwiceInTheSameSeasonIsADuplicateOfTheFile(): void
    {
        $same = ['NOM' => 'Martin', 'PRENOM' => 'Louis'];
        $report = $this->importer->analyze($this->file([$this->row($same), $this->row($same)]), $this->options());

        self::assertSame(1, $report->importable());
        self::assertStringContainsString('doublon', $report->skipped[0]['reason']);
    }

    /**
     * Une personne qui adhere deux saisons de suite garde le meme numero : la
     * seconde ligne retrouve le membre que la premiere vient de creer.
     */
    public function testTheSamePersonOnTwoSeasonsKeepsOneNumber(): void
    {
        $same = ['NOM' => 'Bernard', 'PRENOM' => 'Alice'];
        $report = $this->importer->analyze($this->file([
            $this->row($same + ['DATE ADHESION' => self::serial('2024-11-05')]),
            $this->row($same + ['DATE ADHESION' => self::serial('2025-11-05')]),
        ]), $this->options());

        self::assertSame(2, $report->importable());
        $this->importer->apply($report, $this->options());

        $this->entityManager->clear();
        $numbers = array_map(static fn (MembershipApplication $a) => $a->getMemberNumber(), $this->entityManager->getRepository(MembershipApplication::class)->findBy(['lastName' => 'Bernard']));
        self::assertCount(2, $numbers);
        self::assertCount(1, array_unique($numbers));
    }

    // ------------------------------------------------------------------
    // Le fichier lui-meme
    // ------------------------------------------------------------------

    public function testBlankLinesAndSpreadsheetNotesAreIgnored(): void
    {
        $report = $this->importer->analyze($this->file([
            [],
            [1 => 'Ville : Joigny (1) - CCJ (2) - autre (3)'],
            $this->row(),
            [0 => 47, 16 => 0, 17 => 0],
        ]), $this->options());

        self::assertSame(1, $report->importable());
        self::assertSame(3, $report->blankLines);
        self::assertSame([], $report->errors);
    }

    public function testAFileWithoutHeadersIsRefusedWithAClearMessage(): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('en-têtes');

        $this->importer->analyze($this->files[] = XlsxFixture::build([['a', 'b'], ['c', 'd']]), $this->options());
    }

    public function testAMissingRequiredColumnIsNamed(): void
    {
        $rows = [[0 => 'NOM', 1 => 'PRENOM', 2 => 'NAISSANCE'], [0 => 'Durand', 1 => 'Zoé', 2 => 40000]];

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('EMAIL');

        $this->importer->analyze($this->files[] = XlsxFixture::build($rows), $this->options());
    }

    /**
     * Les personnes importees apparaissent dans les statistiques comme les autres.
     */
    public function testImportedMembersAreCountedLikeAnyOther(): void
    {
        $this->importer->apply($this->importer->analyze($this->file([$this->row(), $this->row()]), $this->options()), $this->options());
        $this->entityManager->clear();

        $stats = static::getContainer()->get(\App\Service\MembershipStatistics::class)->forSeason(\App\Service\MembershipSeasons::of(new \DateTimeImmutable()));

        self::assertGreaterThanOrEqual(2, $stats['active']);
    }
}
