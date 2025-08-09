<?php
namespace App\Form;

use App\Entity\Consumable;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;

class ConsumableType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Nom'])
            ->add('price', NumberType::class, ['label' => 'Prix'])
            ->add('stock', NumberType::class, ['label' => 'Stock', 'required' => false])
            ->add('type', ChoiceType::class, [
                'label' => 'Type',
                'choices' => [
                    'Boisson' => 'boisson',
                    'Snack' => 'snack',
                    'Nourriture' => 'nourriture',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Consumable::class,
        ]);
    }
}
