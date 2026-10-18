<?php

namespace App\Form;

use App\Entity\MembershipApplication;
use App\Enum\MembershipType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;

/**
 * Saisie, par un administrateur, d'une personne qui a rempli une fiche papier.
 *
 * Les champs sont ceux du bulletin en ligne, a l'exception de la signature —
 * elle est sur le papier — et du piege a robots, inutile ici. Deux cases
 * s'ajoutent : l'attestation que la fiche papier existe, signee et complete
 * (elle porte l'acceptation des statuts et du reglement), et le choix d'envoyer
 * ou non le courriel de confirmation.
 */
final class PaperMembershipType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('submittedAt', DateType::class, [
                'label' => 'Date de la fiche papier',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'help' => 'Le jour où l\'adhérent a remis sa fiche.',
            ])
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
                'help' => 'Pour le courriel de confirmation, et pour la liste Philibert si l\'autorisation est donnée.',
            ])
            ->add('membershipType', EnumType::class, [
                'label' => "Formule d'adhésion",
                'class' => MembershipType::class,
                'choice_label' => fn (MembershipType $type) => $type->paperFormLabel(),
                'expanded' => true,
            ])
            ->add('principalMemberName', TextType::class, [
                'label' => "Prénom et nom de l'adhérent principal",
                'required' => false,
                'help' => 'Pour une adhésion famille : la personne qui porte la cotisation.',
            ])
            ->add('philibertConsent', CheckboxType::class, [
                'label' => "Autorise la communication de son adresse e-mail à la boutique Philibert (programme de fidélité).",
                'required' => false,
            ])
            ->add('imageRightsRefused', CheckboxType::class, [
                'label' => "Refuse l'utilisation de photos de lui ou de sa famille prises lors des activités.",
                'required' => false,
            ])
            ->add('guardianLastName', TextType::class, ['label' => 'Nom du responsable légal', 'required' => false])
            ->add('guardianFirstName', TextType::class, ['label' => 'Prénom du responsable légal', 'required' => false])
            ->add('guardianEmail', EmailType::class, ['label' => 'Adresse e-mail du responsable légal', 'required' => false])
            ->add('paperConfirmed', CheckboxType::class, [
                'label' => "J'atteste avoir la fiche papier, remplie et signée, avec l'acceptation des statuts et du règlement intérieur.",
                'mapped' => false,
                'constraints' => [new IsTrue(message: "Sans fiche papier signée, l'adhésion ne peut pas être enregistrée ici.")],
            ])
            ->add('sendConfirmation', CheckboxType::class, [
                'label' => "Envoyer le courriel de confirmation à l'adhérent.",
                'mapped' => false,
                'required' => false,
                'data' => true,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => MembershipApplication::class]);
    }
}
