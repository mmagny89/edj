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
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Nomme EventFormType et non EventType : App\Enum\EventType porte deja ce
 * nom, et deux classes homonymes dans le meme fichier obligeraient a aliaser
 * l'une des deux a chaque usage.
 */
final class EventFormType extends AbstractType
{
    /**
     * Lieu de la quasi-totalite des rendez-vous. Pose d'office quand le champ
     * est laisse vide, et surchargeable au cas par cas — un festival a Sens,
     * par exemple.
     */
    private const DEFAULT_LOCATION = 'Maison des Associations, Joigny';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Titre',
                'required' => false,
                'attr' => ['placeholder' => 'Nuit du Jeu de printemps'],
                'help' => 'Laissé vide, le titre habituel du type choisi est repris : « Soirée jeux du mardi », « Après-midi ludique ».',
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
                'required' => false,
                'attr' => ['placeholder' => '18h30 - 00h, Soirée, Journée…'],
                'help' => "Affiché tel quel. Laissé vide, l'horaire habituel du type choisi est repris.",
            ])
            ->add('location', TextType::class, [
                'label' => 'Lieu',
                'required' => false,
                'attr' => ['placeholder' => self::DEFAULT_LOCATION],
                'help' => 'Laissé vide, le lieu habituel est repris.',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['rows' => 3],
                'help' => "Une phrase, affichée sous la date dans le calendrier.",
            ])
            // PRE_SUBMIT et non POST_SUBMIT : les valeurs par defaut doivent
            // etre en place avant la validation, sinon un titre laisse vide
            // ferait echouer la contrainte NotBlank de l'entite au lieu
            // d'etre complete.
            ->addEventListener(FormEvents::PRE_SUBMIT, $this->applyDefaults(...));
    }

    /**
     * Complete les champs laisses vides par les valeurs habituelles du type
     * choisi. La saisie courante — une soiree du mardi de plus — se reduit
     * ainsi au type et a la date.
     */
    private function applyDefaults(FormEvent $event): void
    {
        $data = $event->getData();

        if (!\is_array($data)) {
            return;
        }

        $type = EventTypeEnum::tryFrom((string) ($data['type'] ?? ''));

        if (null === $type) {
            return;
        }

        if ('' === trim((string) ($data['title'] ?? ''))) {
            $data['title'] = $type->defaultTitle() ?? '';
        }

        if ('' === trim((string) ($data['timeLabel'] ?? ''))) {
            $data['timeLabel'] = $type->defaultTimeLabel() ?? '';
        }

        if ('' === trim((string) ($data['location'] ?? ''))) {
            $data['location'] = self::DEFAULT_LOCATION;
        }

        $event->setData($data);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Event::class,
        ]);
    }
}
