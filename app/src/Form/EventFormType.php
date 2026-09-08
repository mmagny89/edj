<?php

namespace App\Form;

use App\Entity\Event;
use App\Enum\EventType as EventTypeEnum;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Nomme EventFormType et non EventType : App\Enum\EventType porte deja ce
 * nom, et deux classes homonymes dans le meme fichier obligeraient a aliaser
 * l'une des deux a chaque usage.
 */
final class EventFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Titre',
                'attr' => ['placeholder' => 'Nuit du Jeu de printemps'],
            ])
            ->add('type', EnumType::class, [
                'label' => "Type d'événement",
                'class' => EventTypeEnum::class,
                'choice_label' => fn (EventTypeEnum $type) => $type->formLabel(),
                'help' => 'Les mardis et les 3e samedis sont déjà calculés automatiquement pour toute la saison : ne les saisissez ici que pour une exception ou une date ajoutée.',
            ])
            ->add('startsAt', DateType::class, [
                'label' => 'Date',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('endsAt', DateType::class, [
                'label' => 'Date de fin',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'help' => "Uniquement si l'événement dure plusieurs jours, comme le week-end du Jeu.",
            ])
            ->add('timeLabel', TextType::class, [
                'label' => 'Horaire',
                'attr' => ['placeholder' => '18h30 - 00h, Soirée, Journée…'],
                'help' => "Affiché tel quel sur le site.",
            ])
            ->add('location', TextType::class, [
                'label' => 'Lieu',
                'required' => false,
                'attr' => ['placeholder' => 'Maison des Associations, Joigny'],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['rows' => 3],
                'help' => "Une phrase, affichée sous la date dans le calendrier.",
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Event::class,
        ]);
    }
}
