<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Domain;

use PHPUnit\Framework\TestCase;
use ScottKeckWarren\Kanine\Domain\Question;

final class QuestionTest extends TestCase
{
    public function testIdIsAccessible(): void
    {
        $question = $this->makeQuestion(id: 'abc-123');

        $this->assertSame('abc-123', $question->id);
    }

    public function testTaskIdIsAccessible(): void
    {
        $question = $this->makeQuestion(taskId: 99);

        $this->assertSame(99, $question->taskId);
    }

    public function testPupIdIsAccessible(): void
    {
        $question = $this->makeQuestion(pupId: 'pup-7');

        $this->assertSame('pup-7', $question->pupId);
    }

    public function testBodyIsAccessible(): void
    {
        $question = $this->makeQuestion(body: 'What is the expected output?');

        $this->assertSame('What is the expected output?', $question->body);
    }

    public function testPostedAtIsAccessible(): void
    {
        $dt = new \DateTimeImmutable('2026-01-02T10:00:00Z');
        $question = $this->makeQuestion(postedAt: $dt);

        $this->assertSame($dt, $question->postedAt);
    }

    public function testAnswerIsNullByDefault(): void
    {
        $question = $this->makeQuestion();

        $this->assertNull($question->answer);
    }

    public function testAnsweredAtIsNullByDefault(): void
    {
        $question = $this->makeQuestion();

        $this->assertNull($question->answeredAt);
    }

    public function testAnswerAndAnsweredAtCanBeSetTogether(): void
    {
        $answeredAt = new \DateTimeImmutable('2026-01-02T11:00:00Z');
        $question = $this->makeQuestion(answer: 'Yes, do X', answeredAt: $answeredAt);

        $this->assertSame('Yes, do X', $question->answer);
        $this->assertSame($answeredAt, $question->answeredAt);
    }

    private function makeQuestion(
        string $id = 'uuid-1',
        int $taskId = 1,
        string $pupId = 'pup-1',
        string $body = 'Question body',
        ?\DateTimeImmutable $postedAt = null,
        ?string $answer = null,
        ?\DateTimeImmutable $answeredAt = null,
    ): Question {
        return new Question(
            id: $id,
            taskId: $taskId,
            pupId: $pupId,
            body: $body,
            postedAt: $postedAt ?? new \DateTimeImmutable(),
            answer: $answer,
            answeredAt: $answeredAt,
        );
    }
}
