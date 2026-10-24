<?php

namespace App\Import;

use App\Entity\MembershipApplication;
use App\Enum\MembershipType;
use App\Enum\PaymentMethod;
use App\Repository\MemberRepository;
use App\Repository\MembershipApplicationRepository;
use App\Service\MemberDirectory;
use App\Service\MembershipSeasons;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Reprend dans le site une liste d'adherents tenue dans un tableur.
 *
 * Deux temps, qui ne se melangent jamais : analyser — lire, comprendre, signaler,
 * sans rien ecrire — puis appliquer. On ne decouvre pas une erreur d'interpretation
 * apres coup, dans six cents fiches.
 *
 * Ce que l'analyse decide, et pourquoi :
 *  - Les colonnes se reconnaissent par leur en-tete, pas par leur rang : un tableur
 *    se reorganise, un en-tete reste.
 *  - Ce que la fiche n'a pas de raison de porter n'est pas repris : sexe, profils de
 *    reseaux sociaux, abonnement a une lettre d'information, numero WhatsApp. Ne pas
 *    collecter une donnee est la meilleure facon de ne pas avoir a la proteger. Ces
 *    colonnes sont nommees dans le compte rendu, pour qu'on sache qu'elles ont ete
 *    laissees de cote, et non oubliees.
 *  - Le sens de la colonne des photos est le choix de l'administrateur (voir
 *    ImportOptions).
 *  - Une ligne qui ne peut pas devenir une fiche valide — pas d'adresse e-mail, une
 *    date illisible — est une erreur, listee avec son numero de ligne. Les regles de
 *    formule du bulletin public (adherent principal obligatoire en famille,
 *    responsable legal d'un mineur) ne le sont pas : le fichier decrit des gens deja
 *    inscrits sur papier, qu'on ne peut pas refuser. Elles deviennent des
 *    avertissements a completer.
 *  - Relancer un import est sans danger : une personne deja inscrite pour la saison est
 *    ecartee, pas dupliquee.
 */
final class MembershipImporter
{
    /** En-tete normalise => champ. */
    private const COLUMNS = [
        'date adhesion' => 'date', 'date d adhesion' => 'date', 'adhesion' => 'date',
        'nom' => 'lastName',
        'prenom' => 'firstName',
        'naissance' => 'birthDate', 'date de naissance' => 'birthDate',
        'adresse' => 'address',
        'cp' => 'postalCode', 'code postal' => 'postalCode',
        'ville' => 'city',
        'email' => 'email', 'e-mail' => 'email', 'mail' => 'email', 'courriel' => 'email',
        'telephone' => 'phone', 'tel' => 'phone', 'tel.' => 'phone',
        'type' => 'type', 'formule' => 'type',
        'image' => 'image', 'droit a l image' => 'image',
        'philibert' => 'philibert',
        'cotisation' => 'amount', 'montant' => 'amount',
        'paiement' => 'payment', 'moyen de paiement' => 'payment', 'reglement' => 'payment',
    ];

    /** Colonnes connues et laissees de cote : donnees sans usage dans la fiche. */
    private const IGNORED = [
        'sexe' => 'SEXE', 'age' => 'AGE', 'joigny/ccj' => 'JOIGNY/CCJ',
        'tel. whatsapp' => 'TEL. WHATSAPP', 'profil fb' => 'PROFIL FB',
        'whatsapp' => 'WHATSAPP', 'facebook' => 'FACEBOOK', 'newsletter' => 'NEWSLETTER',
    ];

    private const REQUIRED = ['lastName', 'firstName', 'birthDate', 'email', 'postalCode', 'city', 'date', 'type'];

    private const LABELS = [
        'date' => 'DATE ADHESION', 'lastName' => 'NOM', 'firstName' => 'PRENOM', 'birthDate' => 'NAISSANCE',
        'email' => 'EMAIL', 'postalCode' => 'CP', 'city' => 'VILLE', 'type' => 'TYPE',
    ];


    public function __construct(
        private readonly XlsxReader $reader,
        private readonly ValidatorInterface $validator,
        private readonly MemberRepository $members,
        private readonly MembershipApplicationRepository $applications,
        private readonly MemberDirectory $directory,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Lit le fichier et dit ce qu'il donnerait. N'ecrit rien — pas meme un numero
     * d'adherent.
     *
     * @throws ImportException
     */
    public function analyze(string $path, ImportOptions $options): ImportReport
    {
        $sheet = $this->reader->read($path);
        $report = new ImportReport();

        [$headerLine, $columns] = $this->findColumns($sheet, $report);

        $seen = [];
        foreach ($sheet as $index => $cells) {
            if ($index <= $headerLine) {
                continue;
            }
            $line = $index + 1;

            $raw = [];
            foreach ($columns as $column => $field) {
                $raw[$field] = $cells[$column] ?? null;
            }

            if (self::blank($raw['lastName'] ?? null) && self::blank($raw['firstName'] ?? null)) {
                ++$report->blankLines;

                continue;
            }

            // Un nom seul, sans rien d'autre : une legende, une note du tableur.
            if ((self::blank($raw['lastName'] ?? null) xor self::blank($raw['firstName'] ?? null))
                && self::blank($raw['email'] ?? null) && self::blank($raw['birthDate'] ?? null)) {
                ++$report->blankLines;

                continue;
            }

            $this->analyzeLine($line, $raw, $options, $report, $seen);
        }

        return $report;
    }

    /**
     * Ecrit ce que l'analyse a retenu. Tout ou rien : une erreur en cours de route
     * annule l'ensemble.
     *
     * Les lignes passent dans l'ordre du fichier par date : les numeros d'adherent
     * suivent l'anciennete, pas l'ordre d'un tri accidentel du tableur.
     *
     * @return int le nombre de bulletins crees
     */
    public function apply(ImportReport $report, ImportOptions $options): int
    {
        $rows = $report->rows;
        usort($rows, static fn (ImportRow $a, ImportRow $b) => [$a->application->getSubmittedAt(), $a->line] <=> [$b->application->getSubmittedAt(), $b->line]);

        return $this->entityManager->wrapInTransaction(function () use ($rows, $options): int {
            $created = 0;
            foreach ($rows as $row) {
                $application = $row->application;

                // Le rapprochement se fait par la base : le membre cree a la ligne
                // precedente doit y etre pour que la ligne suivante, la meme personne
                // une autre saison, le retrouve — d'ou un flush par ligne.
                $this->directory->attach($application);
                $this->entityManager->persist($application);

                if ($row->validated) {
                    $application->validate($row->payment, $options->adminId, $application->getSubmittedAt());
                }

                $this->entityManager->flush();
                ++$created;
            }

            return $created;
        });
    }

    // ------------------------------------------------------------------

    /**
     * @param list<array<int, string|int|float>> $sheet
     *
     * @return array{int, array<int, string>} [ligne d'en-tete, colonne => champ]
     */
    private function findColumns(array $sheet, ImportReport $report): array
    {
        $headerLine = null;
        foreach (\array_slice($sheet, 0, 15, true) as $index => $cells) {
            $normalized = array_map(self::normalize(...), array_map('strval', $cells));
            if (\in_array('nom', $normalized, true) && \in_array('prenom', $normalized, true)) {
                $headerLine = $index;

                break;
            }
        }
        if (null === $headerLine) {
            throw new ImportException('Je ne trouve pas la ligne d\'en-têtes : elle doit contenir au moins les colonnes NOM et PRENOM, dans les quinze premières lignes.');
        }

        $columns = [];
        foreach ($sheet[$headerLine] as $column => $title) {
            $key = self::normalize((string) $title);
            if ('' === $key) {
                continue;
            }
            if (isset(self::COLUMNS[$key])) {
                // Si deux colonnes portent le meme sens, la premiere gagne.
                if (!\in_array(self::COLUMNS[$key], $columns, true)) {
                    $columns[$column] = self::COLUMNS[$key];
                }
            } elseif (isset(self::IGNORED[$key])) {
                $report->ignoredColumns[] = self::IGNORED[$key];
            } else {
                $report->unknownColumns[] = trim((string) $title);
            }
        }

        $missing = array_diff(self::REQUIRED, $columns);
        if ([] !== $missing) {
            // Les en-tetes lus, tels que le fichier les porte : quand l'un est
            // annonce manquant alors qu'il s'affiche, c'est ici qu'on voit
            // ce que le programme a reellement recu.
            $read = array_map(static fn (string|int|float $title) => '« '.trim((string) $title).' »', array_filter($sheet[$headerLine], static fn ($t) => '' !== trim((string) $t)));

            throw new ImportException(
                'Colonne(s) manquante(s) : '.implode(', ', array_map(static fn (string $f) => self::LABELS[$f], $missing)).'.'
                .' En-têtes lus : '.implode(', ', $read).'.'
            );
        }

        return [$headerLine, $columns];
    }

    /**
     * @param array<string, string|int|float|null> $raw
     * @param array<string, true>                  $seen
     */
    private function analyzeLine(int $line, array $raw, ImportOptions $options, ImportReport $report, array &$seen): void
    {
        $errors = [];
        $warnings = [];

        $lastName = self::text($raw['lastName'] ?? null);
        $firstName = self::text($raw['firstName'] ?? null);
        if ('' === $lastName || '' === $firstName) {
            $report->errors[] = ['line' => $line, 'message' => 'nom ou prénom manquant'];

            return;
        }

        $submittedAt = self::date($raw['date'] ?? null);
        if (null === $submittedAt || $submittedAt < new \DateTimeImmutable('2000-01-01') || $submittedAt > new \DateTimeImmutable('tomorrow')) {
            $errors[] = 'date d\'adhésion manquante ou invalide';
        }

        // Une date absente n'empeche pas l'import : beaucoup de fiches papier ne la
        // portent pas, et la refuser, c'est refuser la personne. Elle se renseigne
        // ensuite sur la fiche. Une date presente mais illisible, ou impossible,
        // reste une erreur : c'est une faute de frappe a corriger, pas une absence.
        $birthDate = null;
        if (self::blank($raw['birthDate'] ?? null)) {
            $warnings[] = 'pas de date de naissance : la personne est reprise, mais retrouvée par son nom seul (deux homonymes se confondraient), et sans âge dans les statistiques';
        } else {
            $birthDate = self::date($raw['birthDate']);
            if (null === $birthDate || $birthDate < new \DateTimeImmutable('1900-01-01') || $birthDate >= new \DateTimeImmutable('today')) {
                $errors[] = 'date de naissance illisible ou impossible';
            }
        }

        // Pas d'adresse n'est pas une erreur : beaucoup d'adherents d'un fichier papier
        // n'en ont pas. La personne est importee, sans confirmation ni lien d'acces.
        // Une adresse qui existe mais n'en est pas une, en revanche, est une faute de
        // frappe a corriger.
        $email = self::optional($raw['email'] ?? null);
        if ('' === $email) {
            $warnings[] = 'pas d\'adresse e-mail : ni confirmation ni lien d\'accès pour cette personne';
        } elseif (false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            // Comme un telephone illisible : la fiche est reprise sans l'adresse,
            // qui se corrige ensuite sur la fiche. Rejeter la personne pour une
            // faute de frappe dans un courriel serait plus grave que de ne pas
            // en avoir.
            $warnings[] = sprintf('adresse e-mail illisible (« %s »), non reprise', $email);
            $email = '';
        }

        $postalCode = self::text($raw['postalCode'] ?? null);
        if (is_numeric($postalCode)) {
            $postalCode = str_pad((string) (int) $postalCode, 5, '0', \STR_PAD_LEFT);
        }
        if (!preg_match('/^\d{5}$/', $postalCode)) {
            $errors[] = 'code postal invalide';
        }

        $city = self::text($raw['city'] ?? null);
        if (!preg_match('/\p{L}/u', $city)) {
            $errors[] = 'ville manquante ou invalide';
        }

        $type = self::type(self::text($raw['type'] ?? null));
        if (null === $type) {
            $errors[] = 'formule inconnue (attendu : IND, FAM ou CONS)';
        }

        // « -- » est ce qu'un tableur met quand il n'y a pas de numero : on ne le
        // signale pas. Un numero qui contient des chiffres mais ne ressemble a rien
        // est signale, et non repris.
        $phone = self::optional($raw['phone'] ?? null);
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if ('' !== $digits && (\strlen($digits) < 6 || !preg_match('/^[0-9 .+()-]{6,}$/', $phone))) {
            $warnings[] = 'téléphone illisible, non repris';
            $phone = '';
        } elseif ('' === $digits) {
            $phone = '';
        }

        $paymentLabel = self::text($raw['payment'] ?? null);
        $payment = self::payment($paymentLabel);
        if ('' !== $paymentLabel && '--' !== $paymentLabel && null === $payment) {
            $warnings[] = sprintf('moyen de règlement inconnu (« %s »), non repris', $paymentLabel);
        }

        if ([] !== $errors) {
            $report->errors[] = ['line' => $line, 'message' => implode(' ; ', $errors)];

            return;
        }

        // La source est posee des l'analyse : les regles du bulletin en ligne — dont
        // l'adresse e-mail obligatoire — ne s'appliquent pas a une personne reprise
        // d'un fichier.
        $application = (new MembershipApplication())
            ->enterFromImport($options->adminId)
            ->setLastName($lastName)
            ->setFirstName($firstName)
            ->setBirthDate($birthDate)
            ->setAddress('' !== self::optional($raw['address'] ?? null) ? self::optional($raw['address']) : null)
            ->setPostalCode($postalCode)
            ->setCity($city)
            ->setPhone('' !== $phone ? $phone : null)
            ->setEmail('' !== $email ? $email : null)
            ->setMembershipType($type)
            ->setImageRightsRefused(self::flag($raw['image'] ?? null) === $options->imageMeansRefusal)
            // Un fichier existant porte des gens inscrits sur papier : statuts et
            // reglement ont ete signes alors.
            ->setStatutesAccepted(true)
            ->setRulesAccepted(true)
            ->setSignaturePlace('Import')
            ->setSignatureName($firstName.' '.$lastName)
            ->setSubmittedAt($submittedAt);

        $wantsPhilibert = self::flag($raw['philibert'] ?? null);
        if ($wantsPhilibert && MembershipType::Consumer === $type) {
            $warnings[] = 'Philibert coché pour un consommateur : non repris (la formule n\'y donne pas droit)';
        } else {
            $application->setPhilibertConsent($wantsPhilibert);
        }

        // Les regles du bulletin : une erreur de fond bloque, une regle de formule
        // devient un avertissement a completer.
        foreach ($this->validator->validate($application) as $violation) {
            $errors[] = $violation->getPropertyPath().' : '.$violation->getMessage();
        }
        // Les regles de formule ne bloquent pas une fiche reprise d'un fichier (la
        // validation de l'entite les ignore pour une source « import ») : elles
        // deviennent des avertissements a completer sur la fiche.
        foreach ($application->formulaIssues() as $message) {
            $warnings[] = $message;
        }
        if ([] !== $errors) {
            $report->errors[] = ['line' => $line, 'message' => implode(' ; ', $errors)];

            return;
        }

        $amount = $raw['amount'] ?? null;
        $due = $application->amountDue() ?? 0;
        if (is_numeric($amount) && (int) round((float) $amount) !== $due && '--' !== $amount) {
            $warnings[] = sprintf('cotisation de %s € dans le fichier, %d € attendus', rtrim(rtrim(number_format((float) $amount, 2, ',', ''), '0'), ','), $due);
        }

        // Deja inscrit pour la saison ? Ecarte, pas duplique : relancer est sans danger.
        $season = MembershipSeasons::of($submittedAt);
        $key = MemberDirectory::identityKey($lastName, $firstName, $birthDate);
        if (isset($seen[$key.'|'.$season])) {
            $report->skipped[] = ['line' => $line, 'reason' => sprintf('doublon dans le fichier (même personne, saison %d)', $season)];

            return;
        }
        $seen[$key.'|'.$season] = true;

        $existing = $this->members->findOneByIdentityKey($key);
        if (null !== $existing && null !== $this->applications->findInSeason($existing, $season)) {
            $report->skipped[] = ['line' => $line, 'reason' => sprintf('déjà enregistré pour la saison %d (%s)', $season, $existing->getNumber())];

            return;
        }

        // Valide si la cotisation est reglee — un moyen de reglement est connu —, ou
        // si rien n'etait du. Sinon l'adhesion attend son encaissement.
        $validated = null !== $payment || 0 === $due;
        if (!$validated && is_numeric($amount) && (float) $amount > 0) {
            $warnings[] = 'cotisation sans moyen de règlement : adhésion en attente';
        }

        $report->rows[] = new ImportRow($line, $application, $validated, $payment, $warnings);
    }

    // ------------------------------------------------------------------

    /**
     * Ramene un en-tete ou une valeur a une forme comparable : minuscules, sans
     * accents, espaces simples.
     *
     * Les caracteres invisibles qu'Excel et Google Sheets laissent dans une
     * cellule sont traites ici, et non a la lecture du fichier : espace
     * insecable et autres espaces Unicode (que trim() et \s ne voient pas sans
     * /u), espace de largeur nulle, marque d'ordre des octets, accents
     * decomposes (e suivi de U+0301). Sans cela, un en-tete bien present etait
     * annonce « manquant » alors qu'il s'affichait correctement a l'ecran.
     */
    private static function normalize(string $value): string
    {
        $value = (string) preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $value);
        $value = \Normalizer::normalize($value, \Normalizer::FORM_D) ?: $value;
        $value = (string) preg_replace('/\p{Mn}+/u', '', $value);
        $value = mb_strtolower($value);
        $value = strtr($value, ['’' => ' ', "'" => ' ']);

        return trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $value));
    }

    private static function blank(string|int|float|null $value): bool
    {
        return null === $value || '' === trim((string) $value);
    }

    private static function text(string|int|float|null $value): string
    {
        return null === $value ? '' : trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
    }

    /**
     * Un champ facultatif : le texte, ou le vide quand le tableur y a mis un
     * remplissage (« -- », « néant », « n/a ») qui veut dire « pas de valeur ».
     * Une adresse e-mail « -- » n'est pas une faute de frappe, c'est une absence.
     */
    private static function optional(string|int|float|null $value): string
    {
        $text = self::text($value);

        return \in_array(self::normalize($text), ['-', '--', '---', '.', '?', 'x', 'n/a', 'na', 'neant', 'aucun', 'aucune', 'non', 'sans', 'inconnu'], true) ? '' : $text;
    }

    private static function flag(string|int|float|null $value): bool
    {
        return \in_array(mb_strtolower(trim((string) $value)), ['1', 'oui', 'o', 'x', 'vrai', 'true', 'yes'], true);
    }

    private static function type(string $value): ?MembershipType
    {
        return match (self::normalize($value)) {
            'ind', 'individuelle', 'individuel' => MembershipType::Individual,
            'fam', 'famille' => MembershipType::Family,
            // « conso » reste lu : des fichiers l'ecrivent encore ainsi.
            'cons', 'conso', 'consommateur' => MembershipType::Consumer,
            default => null,
        };
    }

    private static function payment(string $value): ?PaymentMethod
    {
        return match (self::normalize($value)) {
            'liquide', 'especes', 'espece' => PaymentMethod::Cash,
            'cheque', 'chq' => PaymentMethod::Cheque,
            'virement', 'vir' => PaymentMethod::Transfer,
            default => null,
        };
    }

    /**
     * Une date de tableur : un nombre de jours depuis le 30 decembre 1899 — c'est
     * ainsi qu'Excel et Numbers les ecrivent —, ou un texte jour/mois/annee.
     */
    private static function date(string|int|float|null $value): ?\DateTimeImmutable
    {
        if (null === $value || '' === trim((string) $value)) {
            return null;
        }

        if (\is_int($value) || \is_float($value) || is_numeric($value)) {
            $days = (int) floor((float) $value);

            return $days > 0 && $days < 80000 ? (new \DateTimeImmutable('1899-12-30'))->modify(sprintf('+%d days', $days)) : null;
        }

        foreach (['!d/m/Y', '!d-m-Y', '!d.m.Y', '!Y-m-d'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, trim($value));
            if (false !== $date && 0 === ($date::getLastErrors()['warning_count'] ?? 0)) {
                return $date;
            }
        }

        return null;
    }
}
