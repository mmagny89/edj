<?php

namespace App\Form;

use App\Entity\Member;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Demande d'acces a l'espace adherent : le numero, et rien d'autre. Demander
 * en plus l'adresse e-mail n'ajouterait aucune securite — le lien part de
 * toute facon a l'adresse du bulletin — et ferait echouer la demande de qui a
 * change d'adresse depuis.
 */
final class MemberAccessRequestType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('number', TextType::class, [
            'label' => "Numéro d'adhérent",
            'help' => 'Il figure sur votre carte et dans le courriel reçu à votre adhésion, sous la forme '.Member::PREFIX.'0001.',
            'attr' => ['autocomplete' => 'off', 'placeholder' => Member::PREFIX.'0001', 'inputmode' => 'text'],
            'constraints' => [
                new Assert\NotBlank(message: 'Saisissez votre numéro d\'adhérent.'),
                new Assert\Regex(
                    pattern: '/^'.Member::PREFIX.'\d{1,10}$/i',
                    message: 'Un numéro d\'adhérent s\'écrit '.Member::PREFIX.'0001.',
                ),
            ],
        ]);
    }
}
