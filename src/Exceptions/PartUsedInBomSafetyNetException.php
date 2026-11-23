<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Thrown by PartProjectBOMEntryUnlinkListener when a flush would delete a part used in a BOM while the setting "Refuse
 * deleting parts used in a BOM" is on. Every delete path asks PartDeletionGuard first, so this means one forgot to: a
 * bug, which must be logged as critical and answered with 500. It is therefore not a PartUsedInBomException, which is
 * an expected refusal logged as a warning (config/packages/framework.yaml), and not a \LogicException, which the MCP
 * tools hand back as a tool error. The refusal it stands in for is the previous exception.
 */
class PartUsedInBomSafetyNetException extends \RuntimeException
{
    public function __construct(PartUsedInBomException $refusal)
    {
        parent::__construct('A part used in a BOM was about to be deleted by a path that did not ask PartDeletionGuard first: '
            .$refusal->getMessage(), 0, $refusal);
    }
}
