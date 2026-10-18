<?php

namespace App\Import;

/**
 * Un fichier d'import inutilisable : illisible, trop gros, hostile. Le message est
 * destine a la personne qui a depose le fichier — il dit quoi corriger.
 */
final class ImportException extends \RuntimeException
{
}
