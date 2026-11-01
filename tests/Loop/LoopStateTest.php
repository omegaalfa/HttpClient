<?php

declare(strict_types=1);

namespace Tests\Omegaalfa\HttpClient\Loop;

use Omegaalfa\HttpClient\Loop\Loop;
use Omegaalfa\HttpClient\Loop\Task;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class LoopStateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new ReflectionProperty(Loop::class, 'instance'))->setValue(null, null);
    }

    public function testLoopStateTransitionsThroughShutdown(): void
    {
        $loop = Loop::get();
        self::assertSame(Loop::ACTIVE, $loop->getState());

        $task = new Task(static fn (): string => 'done');
        self::assertSame('done', $task->await());
        self::assertSame(Task::COMPLETED, $task->getState());

        $loop->shutdown();
        self::assertSame(Loop::SHUT_DOWN, $loop->getState());
    }
}
