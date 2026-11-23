<?php

declare(strict_types=1);

namespace App\Entity\Parts\PartTraits;

use App\Entity\Parts\Part;
use App\Entity\Parts\PartConnection;
use App\Entity\ProjectSystem\Project;
use App\Entity\ProjectSystem\ProjectBOMEntry;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

trait ProjectTrait
{
    /**
     * @var Collection<ProjectBOMEntry> $project_bom_entries
     */
    #[ORM\OneToMany(targetEntity: ProjectBOMEntry::class, mappedBy: 'part')]
    protected Collection $project_bom_entries;

    /**
     * @var Project|null If a project is set here, then this part is special and represents the builds of a project.
     */
    #[ORM\OneToOne(targetEntity: Project::class, inversedBy: 'build_part')]
    #[ORM\JoinColumn]
    protected ?Project $built_project = null;

    /**
     *  Returns all ProjectBOMEntries that use this part.
     *
     * @phpstan-return Collection<int, ProjectBOMEntry>
     */
    public function getProjectBomEntries(): Collection
    {
        return $this->project_bom_entries;
    }

    /**
     * Adds the given BOM entry to the list of BOM entries this part is used in.
     * The BOM entry is assigned to this part.
     *
     * @return $this
     */
    public function addProjectBomEntry(ProjectBOMEntry $entry): self
    {
        $entry->setPart($this);
        $this->project_bom_entries->add($entry);

        return $this;
    }

    /**
     * Removes the given BOM entry from the list of BOM entries this part is used in.
     *
     * @return $this
     */
    public function removeProjectBomEntry(ProjectBOMEntry $entry): self
    {
        $this->project_bom_entries->removeElement($entry);

        return $this;
    }

    /**
     * Checks whether this part represents the builds of a project
     * @return bool True if it represents the builds, false if not
     */
    #[Groups(['part:read'])]
    public function isProjectBuildPart(): bool
    {
        return $this->built_project !== null;
    }

    /**
     * Returns the project that this part represents the builds of, or null if it doesn't
     */
    public function getBuiltProject(): ?Project
    {
        return $this->built_project;
    }


    /**
     * Sets the project that this part represents the builds of
     * @param  Project|null  $built_project The project that this part represents the builds of, or null if it is not a build part
     */
    public function setBuiltProject(?Project $built_project): self
    {
        $this->built_project = $built_project;
        return $this;
    }


    /**
     * Get all projects whose BOM uses this part directly, each project once, sorted by name.
     *
     * @return list<Project> an empty array if no BOM uses this part
     */
    public function getProjects(): array
    {
        $projects = [];

        foreach ($this->project_bom_entries as $entry) {
            $project = $entry->getProject();
            if ($project !== null) {
                $projects[spl_object_id($project)] = $project;
            }
        }

        $projects = array_values($projects);
        usort($projects, static fn (Project $a, Project $b): int => strnatcasecmp($a->getName(), $b->getName()));

        return $projects;
    }

    /**
     * Get every BOM entry that uses this part: first its own entries, with via null, then the entries of each meta
     * part it is connected to, with via that meta part and connection the connection that leads there. Connections
     * of every status count, as the list answers whether the part is in use; connection tells whether it is usable.
     * Each group is sorted by project name; a project using the part both ways appears in both.
     * A meta part has no connections above it, so it gives its own entries only.
     *
     * @return list<array{entry: ProjectBOMEntry, via: Part|null, connection: PartConnection|null}>
     */
    public function getBomUsages(): array
    {
        $projectName = static fn (ProjectBOMEntry $entry): string => $entry->getProject()?->getName() ?? '';

        $direct = [];
        foreach ($this->project_bom_entries as $entry) {
            $direct[] = ['entry' => $entry, 'via' => null, 'connection' => null];
        }
        usort($direct, static fn (array $a, array $b): int =>
            strnatcasecmp($projectName($a['entry']), $projectName($b['entry']))
            ?: ($a['entry']->getID() ?? PHP_INT_MAX) <=> ($b['entry']->getID() ?? PHP_INT_MAX));

        $through = [];
        if ($this->getKind()->canBeConnected()) {
            foreach ($this->getConnectionsAsPart() as $connection) {
                $metaPart = $connection->getMetaPart();
                if ($metaPart === null) {
                    continue;
                }
                foreach ($metaPart->getProjectBomEntries() as $entry) {
                    $through[] = ['entry' => $entry, 'via' => $metaPart, 'connection' => $connection];
                }
            }
        }
        usort($through, static fn (array $a, array $b): int =>
            strnatcasecmp($projectName($a['entry']), $projectName($b['entry']))
            ?: strnatcasecmp($a['via']->getName(), $b['via']->getName())
            ?: ($a['entry']->getID() ?? PHP_INT_MAX) <=> ($b['entry']->getID() ?? PHP_INT_MAX));

        return array_merge($direct, $through);
    }
}
