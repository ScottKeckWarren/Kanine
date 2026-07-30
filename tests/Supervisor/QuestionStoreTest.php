<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Supervisor;

use PHPUnit\Framework\TestCase;
use ScottKeckWarren\Kanine\Domain\Question;
use ScottKeckWarren\Kanine\Supervisor\QuestionStore;

final class QuestionStoreTest extends TestCase
{
    public function testAddStoresPendingQuestion(): void
    {
        $store    = new QuestionStore();
        $question = new Question(
            id: 'q-1',
            taskId: 10,
            pupId: 'pup-1',
            body: 'What is the plan?',
            postedAt: new \DateTimeImmutable(),
        );

        $store->add($question);

        $pending = $store->getPending('pup-1');

        $this->assertCount(1, $pending);
        $this->assertSame('q-1', $pending[0]->id);
    }

    public function testGetPendingReturnsEmptyForUnknownPup(): void
    {
        $store = new QuestionStore();

        $this->assertSame([], $store->getPending('unknown'));
    }

    public function testAnswerSetsAnswerAndTimestamp(): void
    {
        $store    = new QuestionStore();
        $question = new Question(
            id: 'q-2',
            taskId: 5,
            pupId: 'pup-1',
            body: 'Should I continue?',
            postedAt: new \DateTimeImmutable(),
        );
        $store->add($question);

        $store->answer('q-2', 'Yes, proceed');

        $pending = $store->getPending('pup-1');
        $this->assertSame([], $pending);
    }

    public function testPopAnsweredReturnsAnsweredQuestionsAndClearsStore(): void
    {
        $store    = new QuestionStore();
        $question = new Question(
            id: 'q-3',
            taskId: 7,
            pupId: 'pup-1',
            body: 'What now?',
            postedAt: new \DateTimeImmutable(),
        );
        $store->add($question);
        $store->answer('q-3', 'Do this');

        $answered = $store->popAnswered('pup-1');

        $this->assertCount(1, $answered);
        $this->assertSame('q-3', $answered[0]['questionId']);
        $this->assertSame('Do this', $answered[0]['body']);

        $second = $store->popAnswered('pup-1');
        $this->assertSame([], $second);
    }

    public function testPopAnsweredReturnsOnlyAnsweredNotPending(): void
    {
        $store = new QuestionStore();

        $q1 = new Question(
            id: 'q-answered',
            taskId: 1,
            pupId: 'pup-1',
            body: 'Answered question',
            postedAt: new \DateTimeImmutable(),
        );
        $q2 = new Question(
            id: 'q-pending',
            taskId: 1,
            pupId: 'pup-1',
            body: 'Pending question',
            postedAt: new \DateTimeImmutable(),
        );

        $store->add($q1);
        $store->add($q2);
        $store->answer('q-answered', 'The answer');

        $answered = $store->popAnswered('pup-1');

        $this->assertCount(1, $answered);
        $this->assertSame('q-answered', $answered[0]['questionId']);

        $pending = $store->getPending('pup-1');
        $this->assertCount(1, $pending);
        $this->assertSame('q-pending', $pending[0]->id);
    }

    public function testDuplicateQuestionIdThrows(): void
    {
        $store    = new QuestionStore();
        $question = new Question(
            id: 'q-dup',
            taskId: 99,
            pupId: 'pup-1',
            body: 'First',
            postedAt: new \DateTimeImmutable(),
        );

        $store->add($question);

        $duplicate = new Question(
            id: 'q-dup',
            taskId: 99,
            pupId: 'pup-1',
            body: 'Second',
            postedAt: new \DateTimeImmutable(),
        );

        $this->expectException(\InvalidArgumentException::class);

        $store->add($duplicate);
    }
}
