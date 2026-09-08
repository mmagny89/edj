<?php

namespace App\Form;

use App\Dto\SeasonGenerationRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class SeasonGenerationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('from', DateType::class, [
                'label' => 'À partir du',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('to', DateType::class, [
                'label' => "Jusqu'au",
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('weekly', CheckboxType::class, [
                'label' => 'Les soirées du mardi',
                'required' => false,
            ])
            ->add('monthly', CheckboxType::class, [
                'label' => 'Les après-midi du 3e samedi',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SeasonGenerationRequest::class,
        ]);
    }
}
