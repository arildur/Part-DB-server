<?php

declare(strict_types=1);

namespace App\Form\Part;

use App\Entity\Parts\PartConnection;
use App\Entity\Parts\PartConnectionStatus;
use App\Entity\Parts\PartKind;
use App\Form\Type\PartSelectType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A connection seen from its manufacturing part: the meta part can only be picked for a new connection, and the position
 * belongs to the meta part's order, so it is not a field.
 */
class PartMetaConnectionType extends AbstractType
{
    public function __construct(private readonly Security $security)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $connection = $event->getData();
            $saved = $connection instanceof PartConnection && $connection->getID() !== null;
            //A disabled field ignores what is submitted and keeps the stored value, so a saved connection of a meta part
            //the user may not edit stays as it is, whatever comes back for it
            $readOnly = $saved && !$this->security->isGranted('edit', $connection);

            $form = $event->getForm();
            $form
                ->add('status', EnumType::class, [
                    'class' => PartConnectionStatus::class,
                    'label' => 'part_connection.edit.status',
                    'choice_label' => static fn (PartConnectionStatus $status): string => $status->getTranslationKey(),
                    'disabled' => $readOnly,
                ])
                ->add('note', TextType::class, [
                    'label' => 'part_connection.edit.note',
                    'required' => false,
                    'empty_data' => '',
                    'trim' => false,
                    'disabled' => $readOnly,
                ]);

            //The ends of a saved connection are fixed, so only a new one gets a picker
            if (!$saved) {
                $form->add('meta_part', PartSelectType::class, [
                    'label' => 'part_connection.edit.meta_part',
                    'kind' => PartKind::META,
                ]);
            }
        });

        //A text field can not hold a line break, so the browser sends a note back without its line breaks: that is no change
        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
            $connection = $event->getForm()->getData();
            $data = $event->getData();
            if ($connection instanceof PartConnection && is_array($data) && isset($data['note'])
                && $data['note'] === str_replace(["\r", "\n"], '', $connection->getNote())) {
                $data['note'] = $connection->getNote();
                $event->setData($data);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PartConnection::class]);
    }
}
