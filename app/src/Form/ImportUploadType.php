<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Le fichier a importer, et le sens de sa colonne IMAGE.
 *
 * Aucune reponse n'est preselectionnee pour la colonne des photos : voir ImportOptions.
 */
final class ImportUploadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('file', FileType::class, [
                'label' => 'Fichier Excel (.xlsx)',
                'help' => 'Une feuille, une ligne d\'en-têtes (NOM, PRENOM, NAISSANCE, EMAIL…). 5 Mo au plus.',
                'attr' => ['accept' => '.xlsx'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Choisissez un fichier.'),
                    new Assert\File(
                        maxSize: '2M',
                        extensions: ['xlsx'],
                        extensionsMessage: 'Le fichier doit être un classeur Excel (.xlsx).',
                        maxSizeMessage: 'Le fichier dépasse {{ limit }} : une liste d\'adhérents n\'en demande pas autant.',
                    ),
                ],
            ])
            ->add('imageMeaning', ChoiceType::class, [
                'label' => 'Dans la colonne IMAGE, un « 1 » veut dire…',
                'help' => 'À vous de le dire : se tromper publierait la photo de quelqu\'un qui l\'avait refusée.',
                'expanded' => true,
                'placeholder' => false,
                'choices' => [
                    'la personne refuse les photos' => 'refus',
                    'la personne accepte les photos' => 'accord',
                ],
                'constraints' => [new Assert\NotBlank(message: 'Précisez le sens de la colonne IMAGE.')],
            ]);
    }
}
