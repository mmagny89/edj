<?php
namespace App\Form;

use App\Entity\Consumption;
use App\Entity\Member;
use App\Entity\Consumable;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ConsumptionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('member', EntityType::class, [
                'class' => Member::class,
                'choice_label' => 'memberNumber',
                'label' => 'Numéro d\'adhérent',
                'placeholder' => 'Sélectionnez un membre',
                'required' => false,
            ])
            ->add('consumable', EntityType::class, [
                'class' => Consumable::class,
                'choice_label' => 'name',
                'label' => 'Consommable',
                'placeholder' => 'Sélectionnez un consommable',
            ])
            ->add('quantity', IntegerType::class, [
                'label' => 'Quantité',
                'attr' => ['min' => 1],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Consumption::class,
        ]);
    }
}
