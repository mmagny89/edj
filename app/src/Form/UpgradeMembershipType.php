<?php

namespace App\Form;

use App\Entity\MembershipApplication;
use App\Enum\MembershipType;
use App\Enum\PaymentMethod;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Passage d'un adherent consommateur a une adhesion annuelle, par un administrateur.
 *
 * Trois choix : la formule annuelle, l'adherent principal si c'est la formule
 * famille, et — si la cotisation est encaissee sur le moment — le moyen de
 * reglement. Les regles de fond (un mineur ne prend pas l'individuelle, un adherent
 * famille ne se designe pas lui-meme) sont celles du bulletin : elles se verifient
 * sur l'entite, pas ici.
 */
final class UpgradeMembershipType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('membershipType', EnumType::class, [
                'label' => 'Nouvelle formule',
                'class' => MembershipType::class,
                // La formule consommateur est celle qu'on quitte : la proposer
                // n'aurait pas de sens.
                'choices' => [MembershipType::Individual, MembershipType::Family],
                'choice_label' => static fn (MembershipType $type) => $type->paperFormLabel(),
                'expanded' => true,
            ])
            ->add('principalMemberName', TextType::class, [
                'label' => "Prénom et nom de l'adhérent principal",
                'required' => false,
                'help' => "Pour la formule famille : la personne du foyer qui a déjà l'adhésion individuelle à 10 €.",
            ])
            ->add('paymentMethod', EnumType::class, [
                'label' => 'Cotisation encaissée',
                'class' => PaymentMethod::class,
                'mapped' => false,
                'required' => false,
                'placeholder' => 'Pas encore encaissée',
                'choice_label' => static fn (PaymentMethod $method) => $method->label(),
                'help' => "Choisissez un moyen si la cotisation est réglée maintenant : l'adhésion est alors validée. Sinon, elle reste en attente jusqu'à l'encaissement.",
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MembershipApplication::class,
            // Les regles de formule seulement : voir MembershipApplication.
            'validation_groups' => ['upgrade'],
        ]);
    }
}
