<?php

namespace App\Form;

use App\Entity\EventContribution;
use App\Form\Guard\GuardType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Blank;

/**
 * « Je ramene… » : le petit formulaire public d'une nuit ou d'un week-end du
 * jeu. Un prenom et un objet ; pas de compte, pas d'adresse.
 */
final class EventContributionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Votre prénom',
                'attr' => ['maxlength' => 60, 'autocomplete' => 'given-name'],
            ])
            ->add('item', TextType::class, [
                'label' => 'Ce que vous apportez',
                'attr' => ['maxlength' => 120, 'placeholder' => 'Catan, un gâteau au chocolat, des boissons…'],
            ])
            // Jeton horodate et signe, puis piege a robots : voir
            // MembershipApplicationType, dont c'est la meme protection.
            ->add('guard', GuardType::class)
            ->add('website', TextType::class, [
                'label' => 'Ne pas remplir ce champ',
                'required' => false,
                'mapped' => false,
                'constraints' => [new Blank(message: 'Envoi refusé.')],
                'attr' => ['autocomplete' => 'off', 'tabindex' => '-1'],
                'row_attr' => ['class' => 'form-honeypot', 'aria-hidden' => 'true'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EventContribution::class,
            'csrf_protection' => true,
        ]);
    }
}
