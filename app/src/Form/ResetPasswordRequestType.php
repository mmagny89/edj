<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Demande de reinitialisation du mot de passe : l'adresse e-mail du compte, et
 * rien d'autre. Le formulaire ne dit jamais si l'adresse correspond a un compte.
 */
final class ResetPasswordRequestType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
            'label' => 'Adresse e-mail du compte',
            'attr' => ['autocomplete' => 'username'],
            'constraints' => [
                new Assert\NotBlank(message: 'Saisissez l\'adresse e-mail de votre compte.'),
                new Assert\Email(message: 'Cette adresse e-mail n\'est pas valide.'),
                new Assert\Length(max: 180),
            ],
        ]);
    }
}
