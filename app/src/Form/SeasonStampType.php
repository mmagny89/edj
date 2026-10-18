<?php

namespace App\Form;

use App\Entity\SeasonStamp;
use App\Enum\StampColor;
use App\Enum\StampSymbol;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class SeasonStampType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('year', IntegerType::class, [
                'label' => 'Saison',
                'help' => "Le millésime de l'année couverte : une adhésion prise à partir du 1er octobre 2026 vaut pour la saison 2027.",
                'attr' => ['inputmode' => 'numeric', 'min' => 2000, 'max' => 2100],
            ])
            ->add('symbol', EnumType::class, [
                'class' => StampSymbol::class,
                'label' => 'Symbole',
                'choice_label' => static fn (StampSymbol $symbol) => $symbol->glyph().' '.$symbol->label(),
            ])
            ->add('color', EnumType::class, [
                'class' => StampColor::class,
                'label' => 'Couleur',
                'choice_label' => static fn (StampColor $color) => $color->label(),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SeasonStamp::class]);
    }
}
