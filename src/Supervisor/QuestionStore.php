<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Supervisor;

use ScottKeckWarren\Kanine\Domain\Question;

final class QuestionStore
{
    /** @var array<string, Question> keyed by questionId */
    private array $questions = [];

    public function add(Question $question): void
    {
        if (isset($this->questions[$question->id])) {
            throw new \InvalidArgumentException("Question {$question->id} already exists");
        }

        $this->questions[$question->id] = $question;
    }

    /**
     * @return list<Question> all pending (unanswered) questions for this pupId
     */
    public function getPending(string $pupId): array
    {
        return array_values(array_filter(
            $this->questions,
            fn (Question $q) => $q->pupId === $pupId && $q->answer === null,
        ));
    }

    public function answer(string $questionId, string $body): void
    {
        if (!isset($this->questions[$questionId])) {
            throw new \InvalidArgumentException("Question {$questionId} not found");
        }

        $q = $this->questions[$questionId];
        $this->questions[$questionId] = new Question(
            id: $q->id,
            taskId: $q->taskId,
            pupId: $q->pupId,
            body: $q->body,
            postedAt: $q->postedAt,
            answer: $body,
            answeredAt: new \DateTimeImmutable(),
        );
    }

    /**
     * @return list<array{questionId: string, body: string}> clears answered questions after return
     */
    public function popAnswered(string $pupId): array
    {
        $answered = array_filter(
            $this->questions,
            fn (Question $q) => $q->pupId === $pupId && $q->answer !== null,
        );

        foreach (array_keys($answered) as $id) {
            unset($this->questions[$id]);
        }

        return array_values(array_map(
            fn (Question $q) => ['questionId' => $q->id, 'body' => (string) $q->answer],
            $answered,
        ));
    }
}
