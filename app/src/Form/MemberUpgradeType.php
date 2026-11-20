<?php

namespace App\Form;

use App\Entity\MembershipApplication;
use App\Enum\MembershipType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Passage d'un adherent consommateur a une adhesion annuelle, par l'adherent
 * lui-meme, depuis son espace.
 *
 * Deux choix seulement : la formule annuelle et, pour la formule famille,
 * l'adherent principal. Les regles de fond (un mineur ne prend pas
 * l'individuelle, on ne se designe pas soi-meme) sont celles du bulletin et se
 * verifient sur l'entite, dans le groupe « upgrade ». Le reglement n'est pas
 * demande ici : c'est le bureau qui encaisse.
 */
final class MemberUpgradeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('membershipType', EnumType::class, [
                'label' => 'Formule annuelle',
                'class' => MembershipType::class,
                'choices' => [MembershipType::Individual, MembershipType::Family],
                'choice_label' => static fn (MembershipType $type) => $type->label().' — '.$type->yourPrice(),
                'choice_attr' => static fn (MembershipType $type) => ['data-price' => $type->priceLabel()],
                'expanded' => true,
            ])
            ->add('principalMemberName', TextType::class, [
                'label' => 'Prénom et nom du membre de votre famille qui a déjà adhéré',
                'required' => false,
                'help' => "À remplir uniquement pour l'adhésion famille : la personne qui a déjà pris l'adhésion à 10 €.",
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MembershipApplication::class,
            'validation_groups' => ['upgrade'],
            'csrf_protection' => true,
        ]);
    }
}
