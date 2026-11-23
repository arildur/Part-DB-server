<?php
/*
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 *  Copyright (C) 2019 - 2025 Jan Böhmer (https://github.com/jbtronics)
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

declare(strict_types=1);


namespace App\Settings\BehaviorSettings;

use App\Settings\SettingsIcon;
use Jbtronics\SettingsBundle\Metadata\EnvVarMode;
use Jbtronics\SettingsBundle\Settings\Settings;
use Jbtronics\SettingsBundle\Settings\SettingsParameter;
use Jbtronics\SettingsBundle\Settings\SettingsTrait;
use Symfony\Component\Translation\TranslatableMessage as TM;

/**
 * How Part-DB deletes parts. The deletion check of every path reads it through \App\Services\Parts\PartDeletionGuard.
 * @see \App\Tests\Settings\PartDeletionSettingsTest
 */
#[Settings(name: "part_deletion", label: new TM("settings.behavior.part_deletion"))]
#[SettingsIcon('fa-trash-can')]
class PartDeletionSettings
{
    use SettingsTrait;

    /**
     * While on, a part that a BOM entry points to can not be deleted. While off, deleting it turns its BOM entries
     * into entries that keep only its name.
     */
    #[SettingsParameter(
        label: new TM("settings.behavior.part_deletion.refuse_deleting_parts_used_in_bom"),
        description: new TM("settings.behavior.part_deletion.refuse_deleting_parts_used_in_bom.help"),
        envVar: "bool:REFUSE_DELETING_PARTS_USED_IN_BOM",
        envVarMode: EnvVarMode::OVERWRITE
    )]
    public bool $refuseDeletingPartsUsedInBom = false;
}
