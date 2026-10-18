<?php

namespace App\Import;

/**
 * Le compte rendu d'une analyse : ce qui sera importe, ce qui est ecarte et
 * pourquoi, ce qui bloque. Il se lit avant d'importer, jamais apres.
 */
final class ImportReport
{
    /** @var list<ImportRow> */
    public array $rows = [];

    /** @var list<array{line: int, reason: string}> deja presents, ou doublons du fichier */
    public array $skipped = [];

    /** @var list<array{line: int, message: string}> lignes inutilisables : a corriger dans le fichier */
    public array $errors = [];

    /** @var list<string> colonnes reconnues mais volontairement non reprises */
    public array $ignoredColumns = [];

    /** @var list<string> colonnes que l'analyse n'a pas su interpreter */
    public array $unknownColumns = [];

    /** Lignes vides, ou simples notes du tableur (legende, numerotation). */
    public int $blankLines = 0;

    public function importable(): int
    {
        return \count($this->rows);
    }

    public function warningCount(): int
    {
        return array_sum(array_map(static fn (ImportRow $r) => \count($r->warnings), $this->rows));
    }

    public function hasErrors(): bool
    {
        return [] !== $this->errors;
    }
}
