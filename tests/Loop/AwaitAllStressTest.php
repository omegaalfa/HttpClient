<?php

declare(strict_types=1);

namespace Tests\Omegaalfa\HttpClient\Loop;

use Omegaalfa\HttpClient\Loop\Loop;
use Omegaalfa\HttpClient\Loop\Task;
use Omegaalfa\HttpClient\Loop\TaskCancelled;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use WeakReference;

use function Omegaalfa\HttpClient\Loop\async;
use function Omegaalfa\HttpClient\Loop\awaitAll;
use function Omegaalfa\HttpClient\Loop\delay;

final class AwaitAllStressTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new ReflectionProperty(Loop::class, 'instance'))->setValue(null, null);
    }

    private function skipWhenXdebugRetainsFiberFrames(): void
    {
        $mode = trim((string) ini_get('xdebug.mode'));
        if (extension_loaded('xdebug') && $mode !== '' && $mode !== 'off') {
            self::markTestSkipped('Xdebug instrumentation retains Fiber frames; run composer test with Xdebug disabled for reference-release checks.');
        }
    }

    /** @return iterable<string, array{int}> */
    public static function groupSizes(): iterable
    {
        yield 'single Task' => [1];
        yield 'one hundred Tasks' => [100];
        yield 'one thousand Tasks' => [1_000];
        yield 'ten thousand Tasks' => [10_000];
    }

    #[DataProvider('groupSizes')]
    public function testLargeGroupsCompleteEveryChildOutsideFiber(int $size): void
    {
        $tasks = [];
        for ($i = 0; $i < $size; $i++) {
            $tasks[] = async(static fn (): int => 1);
        }

        $results = awaitAll(...$tasks);

        self::assertCount($size, $results);
        self::assertSame($size, array_sum($results));
        foreach ($tasks as $task) {
            self::assertSame(Task::COMPLETED, $task->getState());
        }
    }

    public function testLargeGroupCompletesInsideFiberAndNestedAwaitAll(): void
    {
        $leaves = [];
        for ($i = 0; $i < 1_000; $i++) {
            $leaves[] = async(static fn (): int => 1);
        }
        $inner = async(static fn (): array => awaitAll(...$leaves));
        $outer = async(static fn (): array => awaitAll($inner));

        self::assertCount(1_000, awaitAll($outer)[0][0]);
        self::assertSame(Task::COMPLETED, $inner->getState());
        self::assertSame(Task::COMPLETED, $outer->getState());
    }

    public function testChildTaskReferencesAreReleasedAfterSuccessAndFailure(): void
    {
        $this->skipWhenXdebugRetainsFiberFrames();

        $successful = async(static fn (): string => 'ok');
        $successfulRef = WeakReference::create($successful);
        self::assertSame(['ok'], awaitAll($successful));
        unset($successful);

        $failure = new RuntimeException('child failure');
        $failed = async(static function () use ($failure): never {
            throw $failure;
        });
        $failedRef = WeakReference::create($failed);
        $failureRef = WeakReference::create($failure);
        try {
            awaitAll($failed);
            self::fail('Expected the child failure to propagate.');
        } catch (RuntimeException $error) {
            self::assertSame($failure, $error);
        }
        unset($failed, $failure, $error);
        gc_collect_cycles();

        self::assertNull($successfulRef->get());
        self::assertNull($failedRef->get());
        self::assertNull($failureRef->get());
    }

    public function testCompletedGroupTaskDoesNotKeepChildTasksAlive(): void
    {
        $children = [
            async(static fn (): string => 'first'),
            async(static fn (): string => 'second'),
        ];
        $references = array_map(WeakReference::create(...), $children);
        $parent = async(static fn (): array => awaitAll(...$children));

        self::assertSame([['first', 'second']], awaitAll($parent));
        unset($children);
        gc_collect_cycles();

        foreach ($references as $reference) {
            self::assertNull($reference->get());
        }
        self::assertSame(Task::COMPLETED, $parent->getState());
    }

    public function testFailedGroupTaskDoesNotKeepChildTasksAlive(): void
    {
        $this->skipWhenXdebugRetainsFiberFrames();

        $failure = new RuntimeException('nested failure');
        $failed = async(static function () use ($failure): never {
            throw $failure;
        });
        $succeeded = async(static fn (): string => 'ok');
        $children = [$failed, $succeeded];
        $references = array_map(WeakReference::create(...), $children);
        $parent = async(static fn (): array => awaitAll(...$children));

        try {
            $parent->await();
            self::fail('Expected the parent Task to propagate the child error.');
        } catch (RuntimeException $error) {
            self::assertSame($failure, $error);
        }
        unset($children, $failed, $succeeded, $failure, $error);
        gc_collect_cycles();

        self::assertSame(Task::FAILED, $parent->getState());
        foreach ($references as $reference) {
            self::assertNull($reference->get());
        }
    }

    public function testCancelledAwaiterDoesNotRetainAbandonedChildGroup(): void
    {
        $this->skipWhenXdebugRetainsFiberFrames();

        $loop = Loop::get();
        $children = [];
        $references = [];
        for ($i = 0; $i < 10; $i++) {
            $child = async(static function (): void {
                delay(0.001);
            });
            $children[] = $child;
            $references[] = WeakReference::create($child);
        }
        $parent = async(static fn (): array => awaitAll(...$children));
        $parentRef = WeakReference::create($parent);
        $loop->defer(static fn () => $parent->cancel());

        try {
            $parent->await();
            self::fail('Expected cancellation of the awaiting Task.');
        } catch (TaskCancelled) {
        }
        $loop->run(); // The children remain independent and finish normally.
        self::assertSame(Task::CANCELLED, $parent->getState());
        foreach ($children as $child) {
            self::assertSame(Task::COMPLETED, $child->getState());
        }

        unset($children, $child);
        gc_collect_cycles();
        foreach ($references as $reference) {
            self::assertNull($reference->get());
        }
        unset($parent);
        gc_collect_cycles();
        self::assertNull($parentRef->get());
    }

    public function testThousandsOfAbandonedGroupsDoNotRetainTasks(): void
    {
        $this->skipWhenXdebugRetainsFiberFrames();

        $loop = Loop::get();
        $references = [];
        for ($group = 0; $group < 1_000; $group++) {
            $child = async(static function (): void {
                delay(0.0001);
            });
            $children = [$child];
            $references[] = WeakReference::create($child);
            $parent = async(static fn (): array => awaitAll(...$children));
            $references[] = WeakReference::create($parent);
            $loop->defer(static fn () => $parent->cancel());
            try {
                $parent->await();
                self::fail('Expected the group waiter to be cancelled.');
            } catch (TaskCancelled) {
            }
            $loop->run();
            unset($children, $child, $parent);
            if (($group % 100) === 99) {
                gc_collect_cycles();
            }
        }

        gc_collect_cycles();
        foreach ($references as $reference) {
            self::assertNull($reference->get());
        }
    }

    public function testShutdownReleasesTasksSuspendedInNestedGroups(): void
    {
        $this->skipWhenXdebugRetainsFiberFrames();

        $loop = Loop::get();
        $children = [];
        $references = [];
        for ($i = 0; $i < 8; $i++) {
            $child = async(static function (): void {
                delay(60.0);
            });
            $children[] = $child;
            $references[] = WeakReference::create($child);
        }
        $parent = async(static fn (): array => awaitAll(...$children));
        $parentRef = WeakReference::create($parent);
        $loop->defer(static fn () => $loop->stop());
        $loop->run();
        $loop->shutdown();

        self::assertSame(Task::CANCELLED, $parent->getState());
        unset($children, $child);
        gc_collect_cycles();
        foreach ($references as $reference) {
            self::assertNull($reference->get());
        }
        unset($parent);
        gc_collect_cycles();
        self::assertNull($parentRef->get());
    }
}
