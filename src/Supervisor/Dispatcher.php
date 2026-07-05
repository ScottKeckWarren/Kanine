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
