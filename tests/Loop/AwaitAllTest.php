<?php

declare(strict_types=1);

namespace Tests\Omegaalfa\HttpClient\Loop;

use Omegaalfa\HttpClient\Loop\Loop;
use Omegaalfa\HttpClient\Loop\Task;
use ReflectionProperty;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function Omegaalfa\HttpClient\Loop\async;
use function Omegaalfa\HttpClient\Loop\awaitAll;
use function Omegaalfa\HttpClient\Loop\delay;

final class AwaitAllTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new ReflectionProperty(Loop::class, 'instance'))->setValue(null, null);
    }

    public function testEmptyGroupReturnsImmediately(): void
    {
        self::assertSame([], awaitAll());
    }

    public function testPreservesArgumentOrder(): void
    {
        $slow = async(static function (): string {
            delay(0.003);

            return 'slow';
        });
        $fast = async(static function (): string {
            delay(0.001);

            return 'fast';
        });

        $results = awaitAll(later: $slow, earlier: $fast);

        self::assertSame(['slow', 'fast'], $results);
        self::assertSame(Task::COMPLETED, $slow->getState());
        self::assertSame(Task::COMPLETED, $fast->getState());
    }

    public function testStartsAllChildrenBeforeTheirDelayedCompletions(): void
    {
        $started = 0;
        $completed = 0;
        $tasks = [];
        for ($i = 0; $i < 8; $i++) {
            $tasks[] = async(static function () use (&$started, &$completed): int {
                $started++;
                delay(0.002);
                $completed++;

                return 1;
            });
        }

        self::assertCount(8, awaitAll(...$tasks));
        self::assertSame(8, $started);
        self::assertSame(8, $completed);
    }

    public function testCanAwaitGroupFromInsideAnotherTask(): void
    {
        $first = async(static function (): int {
            delay(0.001);

            return 10;
        });
        $second = async(static function (): int {
            delay(0.002);

            return 20;
        });
        $parent = async(static fn (): array => awaitAll($first, $second));

        self::assertSame([10, 20], $parent->await());
    }

    public function testCompletedAggregateDoesNotRetainChildTasks(): void
    {
        $task = async(static fn (): string => 'released');
        $reference = \WeakReference::create($task);

        self::assertSame(['released'], awaitAll($task));
        unset($task);
        gc_collect_cycles();

        self::assertNull($reference->get());
    }

    public function testPropagatesOriginalFailure(): void
    {
        $failure = new RuntimeException('child failed');
        $failed = async(static function () use ($failure): never {
            delay(0.001);
            throw $failure;
        });
        $succeeded = async(static fn (): string => 'ok');

        try {
            awaitAll($failed, $succeeded);
            self::fail('Expected the child exception to propagate.');
        } catch (RuntimeException $error) {
            self::assertSame($failure, $error);
        }
    }

    public function testObservesEveryChildFailureAndPropagatesFirstInArgumentOrder(): void
    {
        $firstFailure = new RuntimeException('first argument failure');
        $earlierFailure = new RuntimeException('earlier completion failure');
        $first = async(static function () use ($firstFailure): never {
            delay(0.003);
            throw $firstFailure;
        });
        $second = async(static function () use ($earlierFailure): never {
            delay(0.001);
            throw $earlierFailure;
        });
        $survivorCompleted = false;
        $survivor = async(static function () use (&$survivorCompleted): string {
            delay(0.005);
            $survivorCompleted = true;

            return 'finished';
        });

        try {
            awaitAll($first, $second, $survivor);
            self::fail('Expected the first argument failure to propagate.');
        } catch (RuntimeException $error) {
            self::assertSame($firstFailure, $error);
        }

        Loop::get()->run(); // The group doesn't cancel siblings after one child fails.

        self::assertTrue($survivorCompleted);
        self::assertSame(Task::FAILED, $first->getState());
        self::assertSame(Task::FAILED, $second->getState());
        self::assertSame(Task::COMPLETED, $survivor->getState());
    }

    public function testCancellingTaskWaitingForGroupDoesNotCancelItsChildren(): void
    {
        $loop = Loop::get();
        $completedChildren = 0;
        $children = [];
        for ($i = 0; $i < 3; $i++) {
            $children[] = async(static function () use (&$completedChildren): void {
                delay(0.003);
                $completedChildren++;
            });
        }
        $parent = async(static fn (): array => awaitAll(...$children));
        $loop->defer(static fn () => $parent->cancel());

        try {
            $parent->await();
            self::fail('Expected the waiting Task to be cancelled.');
        } catch (\Omegaalfa\HttpClient\Loop\TaskCancelled) {
        }

        self::assertSame(Task::CANCELLED, $parent->getState());
        self::assertSame(0, $completedChildren);

        $loop->run();

        self::assertSame(3, $completedChildren);
        foreach ($children as $child) {
            self::assertSame(Task::COMPLETED, $child->getState());
        }
    }
}
