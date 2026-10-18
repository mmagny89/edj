<?php

namespace App\Form\Guard;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Champ cache portant le jeton de FormGuard : emis a l'affichage, verifie a
 * l'envoi. L'erreur remonte au formulaire (error_bubbling), qui l'affiche avec
 * les autres erreurs non rattachees a un champ.
 */
final class GuardType extends AbstractType
{
    public function __construct(private readonly FormGuard $guard)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
                $event->setData($this->guard->issue());
            })
            ->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
                if (null !== $message = $this->guard->check((string) $event->getForm()->getData())) {
                    $event->getForm()->addError(new FormError($message));
                }
            });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'mapped' => false,
            'error_bubbling' => true,
            'label' => false,
        ]);
    }

    public function getParent(): string
    {
        return HiddenType::class;
    }
}
