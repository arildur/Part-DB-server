<?php

declare(strict_types=1);

/*
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 *  Copyright (C) 2019 - 2023 Jan Böhmer (https://github.com/jbtronics)
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as published
 *  by the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */
namespace App\Form\ProjectSystem;

use Symfony\Bundle\SecurityBundle\Security;
use App\Entity\Parts\Part;
use App\Entity\Parts\PartLot;
use App\Form\Type\PartLotSelectType;
use App\Form\Type\SIUnitType;
use App\Helpers\Projects\ProjectBuildRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\DataMapperInterface;
use Symfony\Component\Form\Event\PreSetDataEvent;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProjectBuildType extends AbstractType implements DataMapperInterface
{
    public function __construct(private readonly Security $security)
    {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'compound' => true,
            'data_class' => ProjectBuildRequest::class
        ]);
    }

    /**
     * Returns the name of the field for the amount a BOM entry takes from a lot. A lot can be reached by several entries.
     */
    public static function fieldName(?int $bom_entry_id, ?int $lot_id): string
    {
        return 'entry_'.$bom_entry_id.'_lot_'.$lot_id;
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->setDataMapper($this);

        $builder->add('submit', SubmitType::class, [
            'label' => 'project.build.btn_build',
            'disabled' => !$this->security->isGranted('@parts_stock.withdraw'),
        ]);

        $builder->add('dontCheckQuantity', CheckboxType::class, [
            'label' => 'project.build.dont_check_quantity',
            'help' => 'project.build.dont_check_quantity.help',
            'required' => false,
            'attr' => [
                'data-controller' => 'pages--dont-check-quantity-checkbox'
            ]
        ]);

        $builder->add('comment', TextType::class, [
            'label' => 'part.info.withdraw_modal.comment',
            'help' => 'part.info.withdraw_modal.comment.hint',
            'empty_data' => '',
            'required' => false,
        ]);


        //The form is initially empty, we have to define the fields after we know the data
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (PreSetDataEvent $event) {
            $form = $event->getForm();
            /** @var ProjectBuildRequest $build_request */
            $build_request = $event->getData();

            $form->add('addBuildsToBuildsPart', CheckboxType::class, [
                'label' => 'project.build.add_builds_to_builds_part',
                'required' => false,
                'disabled' => !$build_request->getProject()->getBuildPart() instanceof Part,
            ]);

            $build_part = $build_request->getProject()->getBuildPart();
            if ($build_part instanceof Part && $build_part->getKind()->allowsConnections()) {
                //A meta part offers its own lots and those of its connected parts, grouped by part
                $form->add('buildsPartLot', ChoiceType::class, [
                    'label' => 'project.build.builds_part_lot',
                    'required' => false,
                    'choices' => $build_request->getBuildsPartLotChoices(),
                    'choice_value' => static fn (?PartLot $lot): ?int => $lot?->getID(),
                    'choice_label' => static fn (PartLot $lot): string => PartLotSelectType::lotLabel($lot),
                    'group_by' => static fn (PartLot $lot): string => $lot->getPart() === $build_part
                        ? 'project.build.meta_part_own_lots' : $lot->getPart()->getName(),
                    'placeholder' => 'project.build.buildsPartLot.new_lot'
                ]);
            } elseif ($build_part instanceof Part) {
                $form->add('buildsPartLot', PartLotSelectType::class, [
                    'label' => 'project.build.builds_part_lot',
                    'required' => false,
                    'part' => $build_part,
                    'placeholder' => 'project.build.buildsPartLot.new_lot'
                ]);
            }

            foreach ($build_request->getPartBomEntries() as $bomEntry) {
                //Every part lot has a field to specify the number of parts to take from this lot
                foreach ($build_request->getPartLotsForBOMEntry($bomEntry) as $lot) {
                    $form->add(self::fieldName($bomEntry->getID(), $lot->getID()), SIUnitType::class, [
                        'label' => false,
                        'measurement_unit' => $lot->getPart()->getPartUnit(),
                        'max' => min($build_request->getNeededAmountForBOMEntry($bomEntry), $lot->getAmount()),
                        //Not disabled: a disabled field ignores what is posted, while the validator has to see and refuse it
                        'attr' => $this->security->isGranted('withdraw', $lot) ? [] : ['readonly' => true],
                    ]);
                }
            }

        });
    }

    public function mapDataToForms($data, \Traversable $forms): void
    {
        if (!$data instanceof ProjectBuildRequest) {
            throw new \RuntimeException('Data must be an instance of ' . ProjectBuildRequest::class);
        }

        /** @var FormInterface[] $forms */
        $forms = iterator_to_array($forms);
        foreach ($forms as $key => $form) {
            //Extract the ids of the BOM entry and the lot from the form name
            $matches = [];
            if (preg_match('/^entry_(\d+)_lot_(\d+)$/', $key, $matches)) {
                $form->setData($data->getWithdrawAmount((int) $matches[1], (int) $matches[2]));
            }
        }

        $forms['comment']->setData($data->getComment());
        $forms['dontCheckQuantity']->setData($data->isDontCheckQuantity());
        $forms['addBuildsToBuildsPart']->setData($data->getAddBuildsToBuildsPart());
        if (isset($forms['buildsPartLot'])) {
            $forms['buildsPartLot']->setData($data->getBuildsPartLot());
        }

    }

    public function mapFormsToData(\Traversable $forms, &$data): void
    {
        if (!$data instanceof ProjectBuildRequest) {
            throw new \RuntimeException('Data must be an instance of ' . ProjectBuildRequest::class);
        }

        /** @var FormInterface[] $forms */
        $forms = iterator_to_array($forms);

        foreach ($forms as $key => $form) {
            //Extract the ids of the BOM entry and the lot from the form name
            $matches = [];
            if (preg_match('/^entry_(\d+)_lot_(\d+)$/', $key, $matches)) {
                $data->setWithdrawAmount((int) $matches[1], (int) $matches[2], (float) $form->getData());
            }
        }

        $data->setComment($forms['comment']->getData());
        $data->setDontCheckQuantity($forms['dontCheckQuantity']->getData());

        if (isset($forms['buildsPartLot'])) {
            $lot = $forms['buildsPartLot']->getData();
            if (!$lot) { //When the user selected "Create new lot", create a new lot
                $lot = new PartLot();
                $description = 'Build ' . date('Y-m-d H:i:s');
                if ($data->getComment() !== '') {
                    $description .= ' (' . $data->getComment() . ')';
                }
                $lot->setDescription($description);

                $data->getProject()->getBuildPart()->addPartLot($lot);
            }

            $data->setBuildsPartLot($lot);
        }
        //This has to be set after the builds part lot, so that it can disable the option
        $data->setAddBuildsToBuildsPart($forms['addBuildsToBuildsPart']->getData());
    }
}
