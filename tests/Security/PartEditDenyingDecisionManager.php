<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Parts\Part;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;

/**
 * Only in the test environment: denies edit on the parts a test names. Part-DB's rights on parts are the same for every
 * part, so this is how a test gets a user who may edit one part but not another.
 */
final class PartEditDenyingDecisionManager implements AccessDecisionManagerInterface
{
    /** @var list<int> The IDs of the parts that may not be edited, set by a test and cleared after it */
    public static array $deniedPartIds = [];

    public function __construct(private readonly AccessDecisionManagerInterface $inner)
    {
    }

    public function decide(TokenInterface $token, array $attributes, mixed $object = null, bool|AccessDecision|null $accessDecision = null, bool $allowMultipleAttributes = false): bool
    {
        if ($object instanceof Part && in_array('edit', $attributes, true) && in_array($object->getID(), self::$deniedPartIds, true)) {
            return false;
        }

        return $this->inner->decide($token, $attributes, $object, $accessDecision, $allowMultipleAttributes);
    }
}
