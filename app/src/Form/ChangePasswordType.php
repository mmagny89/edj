<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Choix d'un nouveau mot de passe. Deux usages, une seule regle de robustesse :
 * le changement depuis son compte, qui demande le mot de passe actuel, et la
 * reinitialisation par lien, ou la possession du lien tient lieu de preuve
 * (option with_current_password a false).
 */
final class ChangePasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['with_current_password']) {
            $builder->add('currentPassword', PasswordType::class, [
                'label' => 'Mot de passe actuel',
                'attr' => ['autocomplete' => 'current-password'],
                'constraints' => [new Assert\NotBlank(message: 'Saisissez votre mot de passe actuel.')],
            ]);
        }

        $builder
            ->add('newPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'invalid_message' => 'Les deux saisies du nouveau mot de passe diffèrent.',
                'first_options' => [
                    'label' => 'Nouveau mot de passe',
                    'help' => '12 caractères au minimum. Une phrase de plusieurs mots vaut mieux qu\'un mot compliqué.',
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'second_options' => [
                    'label' => 'Confirmation du nouveau mot de passe',
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'constraints' => [
                    new Assert\NotBlank(message: 'Choisissez un nouveau mot de passe.'),
                    new Assert\Length(min: 12, minMessage: 'Le mot de passe doit faire au moins {{ limit }} caractères.', max: 4096),
                    new Assert\PasswordStrength(minScore: Assert\PasswordStrength::STRENGTH_MEDIUM, message: 'Ce mot de passe est trop facile à deviner.'),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['with_current_password' => true]);
        $resolver->setAllowedTypes('with_current_password', 'bool');
    }
}
