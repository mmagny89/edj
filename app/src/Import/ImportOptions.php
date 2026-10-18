<?php

namespace App\Import;

/**
 * Ce que l'administrateur decide avant d'importer.
 *
 * Le sens de la colonne « IMAGE » est son choix, jamais une valeur par defaut : un
 * « 1 » veut dire « accepte les photos » dans un tableur et « refuse » dans un
 * autre. Se tromper dans un sens publie la photo de quelqu'un qui l'avait refusee —
 * un tort qu'on ne rattrape pas — ; dans l'autre, prive de photo qui l'avait
 * acceptee. Le fichier n'est donc jamais analyse sans cette reponse.
 */
final class ImportOptions
{
    public function __construct(
        /** Vrai : un « 1 » dans la colonne IMAGE signifie « refuse les photos ». */
        public readonly bool $imageMeansRefusal,
        public readonly ?int $adminId = null,
    ) {
    }
}
