<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Pup;

use Symfony\Component\Process\Process;

final class PullRequestCreator implements PullRequestCreatorInterface
{
    /** @var callable(string, string, string, string, string): string */
    private readonly mixed $prCreator;

    /** @var callable(array<string>, string): Process */
    private readonly mixed $processFactory;

    /**
     * @param callable(string $owner, string $repo, string $branch, string $title, string $body): string $prCreator
     * @param callable(array<string>, string): Process|null $processFactory
     */
    public function __construct(
        mixed $prCreator,
        mixed $processFactory = null,
    ) {
        $this->prCreator      = $prCreator;
        $this->processFactory = $processFactory
            ?? static fn (array $cmd, string $cwd): Process => new Process($cmd, $cwd);
    }

    /**
     * Factory method that wires to a GitHub\Client instance.
     */
    public static function withGithubClient(
        \Github\Client $client,
        string $defaultBranch = 'main',
        mixed $processFactory = null,
    ): self {
        return new self(
            prCreator: static fn (
                string $owner,
                string $repo,
                string $branch,
                string $title,
                string $body,
            ): string => $client->api('pull_request')->create(
                $owner,
                $repo,
                [
                    'title' => $title,
                    'head'  => $branch,
                    'base'  => $defaultBranch,
                    'body'  => $body,
                ],
            )['html_url'],
            processFactory: $processFactory,
        );
    }

    /**
     * Push branch to origin from the given worktree path.
     *
     * @throws \RuntimeException if git push fails
     */
    public function push(string $worktreePath, string $branch): void
    {
        $process = ($this->processFactory)(
            ['git', 'push', 'origin', $branch],
            $worktreePath,
        );
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                'git push failed: ' . $process->getErrorOutput(),
            );
        }
    }

    /**
     * Create a pull request and return its URL.
     */
    public function createPR(
        string $owner,
        string $repo,
        string $branch,
        string $title,
        string $body,
    ): string {
        return ($this->prCreator)($owner, $repo, $branch, $title, $body);
    }
}
