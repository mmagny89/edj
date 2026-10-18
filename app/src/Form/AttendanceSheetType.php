<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

final class AttendanceSheetType extends AbstractType
{
    public const KINDS = [
        'Assemblée générale ordinaire' => 'Assemblée générale ordinaire',
        'Assemblée générale extraordinaire' => 'Assemblée générale extraordinaire',
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('kind', ChoiceType::class, [
                'label' => 'Assemblée',
                'choices' => self::KINDS,
                'constraints' => [new Assert\Choice(choices: array_values(self::KINDS))],
            ])
            ->add('date', DateType::class, [
                'label' => 'Date de l\'assemblée',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'constraints' => [new Assert\NotNull(message: 'Indiquez la date.')],
            ])
            ->add('place', TextType::class, [
                'label' => 'Lieu',
                'required' => false,
                'constraints' => [new Assert\Length(max: 120), new Assert\Regex(pattern: '/^[^\p{C}<>]*$/u', message: 'Ce champ contient des caractères non autorisés.')],
            ])
            ->add('season', IntegerType::class, [
                'label' => 'Saison',
                'help' => 'Le millésime : la saison 2027 va du 1er octobre 2026 au 30 septembre 2027.',
                'attr' => ['inputmode' => 'numeric', 'min' => 2000, 'max' => 2100],
                'constraints' => [new Assert\Range(min: 2000, max: 2100)],
            ])
            ->add('withMinors', CheckboxType::class, [
                'label' => 'Inclure les mineurs',
                'required' => false,
            ])
            ->add('withConsumers', CheckboxType::class, [
                'label' => 'Inclure les adhérents consommateurs',
                'help' => 'Par défaut, seuls les adhérents annuels figurent sur la feuille.',
                'required' => false,
            ]);
    }
}
