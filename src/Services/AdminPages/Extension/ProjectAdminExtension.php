<?php

declare(strict_types=1);

namespace App\Services\AdminPages\Extension;

use App\Entity\Parts\Part;
use App\Entity\ProjectSystem\Project;
use App\Exceptions\PartUsedInBomException;
use App\Services\AdminPages\AbstractAdminPageExtension;
use App\Services\Parts\PartDeletionGuard;
use App\Services\Trees\StructuralElementRecursionHelper;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;

use function Symfony\Component\Translation\t;

final class ProjectAdminExtension extends AbstractAdminPageExtension
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly StructuralElementRecursionHelper $recursionHelper,
        private readonly PartDeletionGuard $deletionGuard,
    ) {
    }

    /**
     * Deleting a project deletes its build part, and those of the sub-projects deleted with it. A setting may refuse
     * that while a BOM of another project uses one: then nothing is deleted and the user stays on the project.
     */
    public function checkDelete(object $entity): bool
    {
        if (!$entity instanceof Project) {
            throw new \InvalidArgumentException('Expected ' . Project::class . ', got ' . $entity::class . '.');
        }

        $deleted = [$entity];
        if ($this->requestStack->getCurrentRequest()?->get('delete_recursive', false)) {
            $this->recursionHelper->execute($entity, static function (Project $project) use (&$deleted): void {
                if (!in_array($project, $deleted, true)) {
                    $deleted[] = $project;
                }
            });
        }

        foreach ($deleted as $project) {
            if (!$project->getBuildPart() instanceof Part) {
                continue;
            }
            try {
                $this->deletionGuard->assertDeletable($project->getBuildPart(), $deleted);
            } catch (PartUsedInBomException $exception) {
                /** @var Session $session */
                $session = $this->requestStack->getSession();
                $session->getFlashBag()->add('error', t('project.delete.refused_build_part_used_in_bom', [
                    '%project%' => $project->getName(),
                    '%projects%' => $exception->getProjectsParameter(),
                ]));

                return false;
            }
        }

        return true;
    }
}
