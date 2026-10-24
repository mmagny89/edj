<?php

namespace App\Form;

use App\Dto\MailingDraft;
use App\Service\MailingTemplate;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Rediger un message aux adherents d'une saison.
 *
 * Trois boutons, trois gestes, dans l'ordre ou on les fait : voir a qui il part
 * (apercu), se l'envoyer (essai), puis l'envoyer. « send » existe toujours dans
 * le formulaire mais n'est montre qu'apres un apercu : c'est l'apercu qui fournit
 * le nombre attendu (`expected`) et le jeton a usage unique (`nonce`).
 */
final class MailingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('season', ChoiceType::class, [
                'label' => 'Saison',
                'choices' => array_combine(
                    array_map(static fn (int $s) => MailingTemplate::seasonLabel($s).($s === $options['current_season'] ? ' (en cours)' : ''), $options['seasons']),
                    $options['seasons'],
                ),
                'help' => 'Les adhérents dont le bulletin validé date de cette saison.',
            ])
            ->add('includeAnnual', CheckboxType::class, [
                'label' => 'Adhérents à l\'année',
                'required' => false,
            ])
            ->add('includeConsumers', CheckboxType::class, [
                'label' => 'Consommateurs (adhésion gratuite, 1 € par évènement)',
                'required' => false,
            ])
            ->add('subject', TextType::class, [
                'label' => 'Objet',
                'attr' => ['maxlength' => 150],
            ])
            ->add('body', TextareaType::class, [
                'label' => 'Message',
                'attr' => ['rows' => 14],
                'help' => 'Texte simple : une ligne vide sépare les paragraphes, une adresse http(s) devient un lien.',
            ])
            ->add('expected', HiddenType::class, ['required' => false])
            ->add('nonce', HiddenType::class, ['mapped' => false, 'required' => false])
            ->add('preview', SubmitType::class, ['label' => 'Aperçu et destinataires'])
            ->add('test', SubmitType::class, ['label' => 'M\'envoyer un essai'])
            ->add('send', SubmitType::class, ['label' => 'Envoyer']);

        // Un champ cache est du texte, la propriete un entier : sans cette
        // conversion, le nombre annonce par l'apercu ne rentre pas dans le brouillon.
        $builder->get('expected')->addModelTransformer(new CallbackTransformer(
            static fn (?int $count): string => null === $count ? '' : (string) $count,
            static fn (?string $text): ?int => null === $text || '' === $text ? null : (int) $text,
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MailingDraft::class,
            'seasons' => [],
            'current_season' => 0,
        ]);
        $resolver->setAllowedTypes('seasons', 'int[]');
        $resolver->setAllowedTypes('current_season', 'int');
    }
}
