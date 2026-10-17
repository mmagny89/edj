<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Creation d'un compte d'administration : une adresse et un role. Le mot de
 * passe n'est pas saisi — il est genere, communique une seule fois, et change
 * par la personne a sa premiere connexion.
 */
final class UserCreationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'Adresse e-mail',
                'help' => "Elle sert d'identifiant de connexion.",
                'constraints' => [
                    new Assert\NotBlank(message: "L'adresse e-mail est obligatoire."),
                    new Assert\Email(message: "Cette adresse e-mail n'est pas valide."),
                    new Assert\Length(max: 180),
                ],
            ])
            ->add('role', ChoiceType::class, [
                'label' => 'Rôle',
                'choices' => [
                    'Contributeur — gère le calendrier des évènements' => User::ROLE_CONTRIBUTOR,
                    'Super-administrateur — tout, dont les adhésions et les comptes' => User::ROLE_SUPER_ADMIN,
                ],
                'expanded' => true,
                'data' => User::ROLE_CONTRIBUTOR,
                'constraints' => [new Assert\NotBlank()],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
