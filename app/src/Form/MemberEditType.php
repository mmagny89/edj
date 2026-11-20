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
use Symfony\Component\Validator\Constraints as Assert;

/**
 * « Modifier mon adhesion » : l'adherent corrige sa fiche (identite,
 * coordonnees, responsable legal, autorisations) et, s'il est consommateur,
 * passe a une formule annuelle.
 *
 * Les champs de formule n'existent que pour un consommateur de la saison en
 * cours (option « allow_upgrade »). Les regles de fond de la formule sont
 * celles du bulletin, groupe « upgrade » de l'entite ; les coordonnees se
 * valident ici, groupe « edit » : on ne rejoue ni la signature ni les
 * statuts, deja donnes au depot du bulletin. Le reglement n'est pas demande :
 * c'est le bureau qui encaisse.
 */
final class MemberEditType extends AbstractType
{
    private const GROUP = 'edit';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $g = [self::GROUP];
        $builder
            ->add('lastName', TextType::class, [
                'label' => 'Nom',
                'attr' => ['autocomplete' => 'family-name'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Le nom est obligatoire.', groups: $g),
                    new Assert\Length(max: 100, groups: $g),
                ],
            ])
            ->add('firstName', TextType::class, [
                'label' => 'Prénom',
                'attr' => ['autocomplete' => 'given-name'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Le prénom est obligatoire.', groups: $g),
                    new Assert\Length(max: 100, groups: $g),
                ],
            ])
            ->add('birthDate', DateType::class, [
                'label' => 'Date de naissance',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'constraints' => [
                    new Assert\LessThan('today', message: 'La date de naissance doit être passée.', groups: $g),
                    new Assert\GreaterThan('-120 years', message: 'Cette date de naissance ne semble pas correcte.', groups: $g),
                ],
            ])
            ->add('address', TextType::class, [
                'label' => 'Adresse',
                'required' => false,
                'attr' => ['autocomplete' => 'street-address'],
                'constraints' => [new Assert\Length(max: 255, groups: $g)],
            ])
            ->add('postalCode', TextType::class, [
                'label' => 'Code postal',
                'attr' => ['inputmode' => 'numeric', 'autocomplete' => 'postal-code', 'maxlength' => 5],
                'constraints' => [
                    new Assert\NotBlank(message: 'Le code postal est obligatoire.', groups: $g),
                    new Assert\Regex(pattern: '/^\d{5}$/', message: 'Le code postal doit comporter cinq chiffres.', groups: $g),
                ],
            ])
            ->add('city', TextType::class, [
                'label' => 'Ville',
                'attr' => ['autocomplete' => 'address-level2'],
                'constraints' => [
                    new Assert\NotBlank(message: 'La ville est obligatoire.', groups: $g),
                    new Assert\Length(max: 100, groups: $g),
                ],
            ])
            ->add('phone', TelType::class, [
                'label' => 'Téléphone',
                'required' => false,
                'attr' => ['autocomplete' => 'tel'],
                'constraints' => [
                    new Assert\Length(max: 30, groups: $g),
                    new Assert\Regex(pattern: '/^[0-9 .+()-]{6,}$/', message: 'Ce numéro de téléphone ne semble pas valide.', groups: $g),
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Adresse e-mail',
                'attr' => ['autocomplete' => 'email'],
                'constraints' => [
                    new Assert\NotBlank(message: "L'adresse e-mail est obligatoire.", groups: $g),
                    new Assert\Email(message: "Cette adresse e-mail n'est pas valide.", groups: $g),
                    new Assert\Length(max: 180, groups: $g),
                ],
            ]);

        $builder
            ->add('guardianLastName', TextType::class, [
                'label' => 'Nom du responsable légal',
                'required' => false,
                'constraints' => [new Assert\Length(max: 100, groups: $g)],
            ])
            ->add('guardianFirstName', TextType::class, [
                'label' => 'Prénom du responsable légal',
                'required' => false,
                'constraints' => [new Assert\Length(max: 100, groups: $g)],
            ])
            ->add('guardianEmail', EmailType::class, [
                'label' => 'Adresse e-mail du responsable légal',
                'required' => false,
                'constraints' => [
                    new Assert\Email(message: "Cette adresse e-mail n'est pas valide.", groups: $g),
                    new Assert\Length(max: 180, groups: $g),
                ],
            ])
            ->add('philibertConsent', CheckboxType::class, [
                'label' => "J'autorise la communication de mon adresse e-mail à la boutique Philibert dans le cadre de son programme de fidélité.",
                'required' => false,
            ])
            ->add('imageRightsRefused', CheckboxType::class, [
                'label' => "Je refuse l'utilisation de photos de moi et/ou des membres de ma famille prises lors des activités d'Envie de Jouer.",
                'required' => false,
            ]);

        if ($options['allow_upgrade']) {
            $builder
                ->add('membershipType', EnumType::class, [
                    'label' => 'Formule',
                    'class' => MembershipType::class,
                    // Consommateur : celle qu'on garde si l'on ne passe pas a l'annuel.
                    'choices' => [MembershipType::Consumer, MembershipType::Individual, MembershipType::Family],
                    'choice_label' => static fn (MembershipType $type) => $type->label(),
                    'expanded' => true,
                ])
                ->add('principalMemberName', TextType::class, [
                    'label' => 'Prénom et nom du membre de votre famille qui a déjà adhéré',
                    'required' => false,
                    'help' => "À remplir uniquement pour l'adhésion famille : la personne qui a déjà pris l'adhésion à 10 €.",
                ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MembershipApplication::class,
            'validation_groups' => ['upgrade', self::GROUP],
            'csrf_protection' => true,
            'allow_upgrade' => false,
        ]);
        $resolver->setAllowedTypes('allow_upgrade', 'bool');
    }
}
