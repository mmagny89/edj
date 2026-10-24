<?php

namespace App\Form;

use App\Entity\MembershipApplication;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Correction, par le bureau, d'une fiche deja enregistree : une faute dans un
 * fichier importe, une adresse qui a change, un telephone oublie.
 *
 * Ni la formule (elle a son propre parcours : passage a l'annuel, renouvellement),
 * ni la date de la fiche (elle fixe la saison, donc la carte et le numero de
 * rang), ni la signature : une correction ne change pas l'acte d'adhesion, elle
 * en corrige la description.
 */
final class EditMembershipType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('lastName', TextType::class, ['label' => 'Nom'])
            ->add('firstName', TextType::class, ['label' => 'Prénom'])
            ->add('birthDate', DateType::class, [
                'label' => 'Date de naissance',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('address', TextType::class, ['label' => 'Adresse', 'required' => false])
            ->add('postalCode', TextType::class, ['label' => 'Code postal', 'attr' => ['inputmode' => 'numeric', 'maxlength' => 5]])
            ->add('city', TextType::class, ['label' => 'Ville'])
            ->add('phone', TelType::class, ['label' => 'Téléphone', 'required' => false])
            ->add('email', EmailType::class, [
                'label' => 'Adresse e-mail',
                'required' => false,
                'help' => 'Laisser vide si la personne n\'en a pas : elle n\'aura ni confirmation ni lien d\'accès.',
            ])
            ->add('principalMemberName', TextType::class, [
                'label' => "Prénom et nom de l'adhérent principal",
                'required' => false,
                'help' => 'Pour une adhésion famille : la personne qui porte la cotisation.',
            ])
            ->add('guardianLastName', TextType::class, ['label' => 'Nom du responsable légal', 'required' => false])
            ->add('guardianFirstName', TextType::class, ['label' => 'Prénom du responsable légal', 'required' => false])
            ->add('guardianEmail', EmailType::class, ['label' => 'Adresse e-mail du responsable légal', 'required' => false])
            ->add('philibertConsent', CheckboxType::class, [
                'label' => "Autorise la communication de son adresse e-mail à la boutique Philibert (programme de fidélité).",
                'required' => false,
            ])
            ->add('imageRightsRefused', CheckboxType::class, [
                'label' => "Refuse l'utilisation de photos de lui ou de sa famille prises lors des activités.",
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => MembershipApplication::class]);
    }
}
