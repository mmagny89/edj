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
use Symfony\Component\Validator\Constraints\Blank;

final class MembershipApplicationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('lastName', TextType::class, [
                'label' => 'Nom',
                'attr' => ['autocomplete' => 'family-name'],
            ])
            ->add('firstName', TextType::class, [
                'label' => 'Prénom',
                'attr' => ['autocomplete' => 'given-name'],
            ])
            ->add('birthDate', DateType::class, [
                'label' => 'Date de naissance',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'help' => "L'adhésion est gratuite pour les moins de 8 ans.",
            ])
            ->add('address', TextType::class, [
                'label' => 'Adresse',
                'required' => false,
                'attr' => ['autocomplete' => 'street-address'],
            ])
            ->add('postalCode', TextType::class, [
                'label' => 'Code postal',
                'attr' => ['inputmode' => 'numeric', 'autocomplete' => 'postal-code', 'maxlength' => 5],
            ])
            ->add('city', TextType::class, [
                'label' => 'Ville',
                'attr' => ['autocomplete' => 'address-level2'],
            ])
            ->add('phone', TelType::class, [
                'label' => 'Téléphone',
                'required' => false,
                'attr' => ['autocomplete' => 'tel'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Adresse e-mail',
                'help' => "C'est par là que nous vous répondrons.",
                'attr' => ['autocomplete' => 'email'],
            ])
            ->add('membershipType', EnumType::class, [
                'label' => "Formule d'adhésion",
                'class' => MembershipType::class,
                'expanded' => true,
                'placeholder' => false,
                'choice_label' => fn (MembershipType $type) => $type->label(),
                'choice_attr' => fn (MembershipType $type) => ['data-price' => $type->priceLabel()],
            ])
            ->add('principalMemberName', TextType::class, [
                'label' => "Prénom et nom de l'adhérent principal",
                'required' => false,
                'help' => "À remplir uniquement pour une adhésion famille.",
            ])
            ->add('philibertConsent', CheckboxType::class, [
                'label' => "J'autorise la communication de mon adresse e-mail à la boutique Philibert dans le cadre de son programme de fidélité (10 % de réduction sur la boutique en ligne).",
                'required' => false,
            ])
            ->add('imageRightsRefused', CheckboxType::class, [
                'label' => "Je refuse l'utilisation de photos de moi et/ou des membres de ma famille prises lors des activités d'Envie de Jouer (supports numériques et imprimés).",
                'required' => false,
            ])
            ->add('statutesAccepted', CheckboxType::class, [
                'label' => "Je déclare avoir lu et accepté les statuts d'Envie de Jouer.",
            ])
            ->add('rulesAccepted', CheckboxType::class, [
                'label' => "Je déclare avoir lu et accepté le règlement intérieur d'Envie de Jouer.",
            ])
            ->add('guardianLastName', TextType::class, [
                'label' => 'Nom du responsable légal',
                'required' => false,
            ])
            ->add('guardianFirstName', TextType::class, [
                'label' => 'Prénom du responsable légal',
                'required' => false,
            ])
            ->add('guardianEmail', EmailType::class, [
                'label' => 'Adresse e-mail du responsable légal',
                'required' => false,
            ])
            ->add('signaturePlace', TextType::class, [
                'label' => 'Fait à',
                'attr' => ['placeholder' => 'Joigny'],
            ])
            ->add('signatureName', TextType::class, [
                'label' => 'Signature : saisissez vos prénom et nom',
                'help' => "Pour un adhérent de moins de 18 ans, c'est le responsable légal qui signe.",
            ])
            // Piege a robots : champ invisible et sans autocompletion, qu'un
            // visiteur ne peut pas remplir et qu'un automate remplit presque
            // toujours. Non mappe sur l'entite : il ne doit jamais etre
            // enregistre.
            ->add('website', TextType::class, [
                'label' => 'Ne pas remplir ce champ',
                'required' => false,
                'mapped' => false,
                'constraints' => [new Blank(message: 'Envoi refusé.')],
                'attr' => [
                    'autocomplete' => 'off',
                    'tabindex' => '-1',
                ],
                'row_attr' => ['class' => 'form-honeypot', 'aria-hidden' => 'true'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MembershipApplication::class,
            // Aucun csrf_token_id ici : le projet utilise la protection CSRF
            // sans etat de Symfony (config/packages/csrf.yaml, token_id
            // "submit"), ou le jeton est double-soumis dans un cookie par le
            // controleur Stimulus csrf_protection. Imposer un identifiant
            // propre a ce formulaire le ferait retomber sur un jeton en
            // session, donc sur une session ouverte pour chaque visiteur
            // anonyme qui affiche simplement la page.
            'csrf_protection' => true,
        ]);
    }
}
