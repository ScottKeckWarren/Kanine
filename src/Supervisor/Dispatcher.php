<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Supervisor;

use ScottKeckWarren\Kanine\Domain\PupStatus;

final class Dispatcher
{
    public function __construct(
        private readonly IssueStore $issueStore,
        private readonly PupRegistry $pupRegistry,
    ) {
    }

    /**
     * Check all active pups for inactivity. Marks pups inactive and releases
     * their issue assignments when their heartbeat is older than $timeoutSeconds.
     *
     * @return list<string> pupIds that were marked inactive
     */
    public function checkInactivity(int $timeoutSeconds, ?\DateTimeImmutable $now = null): array
    {
        $now       = $now ?? new \DateTimeImmutable();
        $timedOut  = [];
        $activePups = $this->pupRegistry->getAllActivePups();

        foreach ($activePups as $pup) {
            if ($pup->lastHeartbeatAt === null) {
                continue;
            }

            $age = $now->getTimestamp() - $pup->lastHeartbeatAt->getTimestamp();

            if ($age > $timeoutSeconds) {
                $issue = $this->issueStore->getByPupId($pup->id);

                if ($issue !== null) {
                    $this->issueStore->unassign($issue->id, $issue->repo);
                }

                $this->pupRegistry->markInactive($pup->id);
                $timedOut[] = $pup->id;
            }
        }

        return $timedOut;
    }

    /**
     * Run one dispatch cycle: assign one eligible issue to each idle pup.
     * Returns the number of assignments made.
     */
    public function dispatch(): int
    {
        $idlePups       = $this->pupRegistry->getIdlePups();
        $eligibleIssues = $this->issueStore->getEligible();

        if ($idlePups === [] || $eligibleIssues === []) {
            return 0;
        }

        $assigned   = 0;
        $issueIndex = 0;

        foreach ($idlePups as $pup) {
            if ($issueIndex >= count($eligibleIssues)) {
                break;
            }

            $issue = $eligibleIssues[$issueIndex];
            $this->issueStore->assign($issue->id, $issue->repo, $pup->id);
            $this->pupRegistry->updateStatus($pup->id, PupStatus::Working);
            $issueIndex++;
            $assigned++;
        }

        return $assigned;
    }
}
