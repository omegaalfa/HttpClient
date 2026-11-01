<?php

declare(strict_types=1);

namespace Tests\Omegaalfa\HttpClient\Loop;

use Omegaalfa\HttpClient\Loop\BoundedChannel;
use Omegaalfa\HttpClient\Loop\ChannelClosedException;
use Omegaalfa\HttpClient\Loop\Loop;
use Omegaalfa\HttpClient\Loop\Task;
use Omegaalfa\HttpClient\Loop\TaskCancelled;
use ReflectionProperty;
use PHPUnit\Framework\TestCase;

final class BoundedChannelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new ReflectionProperty(Loop::class, 'instance'))->setValue(null, null);
    }

    public function testConsumersWaitAndReceiveMessagesInOrderWithCapacityOne(): void
    {
        $channel = new BoundedChannel(1);
        $received = [];
        $consumer = new Task(static function () use ($channel, &$received): void {
            for ($i = 0; $i < 3; $i++) {
                $received[] = $channel->receive();
            }
        });
        $producer = new Task(static function () use ($channel): void {
            foreach (['a', 'b', 'c'] as $value) {
                $channel->send($value);
            }
        });
        Loop::get()->run();
        self::assertSame(['a', 'b', 'c'], $received);
        self::assertSame(1, $channel->getPeakQueueDepth());
        self::assertSame(0, $channel->getWaitingSenders());
        self::assertSame(0, $channel->getWaitingReceivers());
        $consumer->await();
        $producer->await();
    }

    public function testReservedReceiveReleasesCapacityForBlockedProducer(): void
    {
        $channel = new BoundedChannel(1);
        $consumer = new Task(static fn () => $channel->receive());
        $producer = new Task(static function () use ($channel): void {
            $channel->send('first');
            $channel->send('second');
        });
        Loop::get()->run();
        self::assertSame('first', $consumer->await());
        self::assertSame('second', $channel->receive());
        $producer->await();
        self::assertSame(0, $channel->getWaitingSenders());
    }

    public function testProducerWaitsWhenFullAndIsResumedInFifoOrder(): void
    {
        $channel = new BoundedChannel(1);
        $channel->send('initial');
        $sent = [];
        $producers = [];
        foreach (['first', 'second', 'third'] as $value) {
            $producers[] = new Task(static function () use ($channel, &$sent, $value): void {
                $channel->send($value);
                $sent[] = $value;
            });
        }
        $consumer = new Task(static function () use ($channel): array {
            return [$channel->receive(), $channel->receive(), $channel->receive(), $channel->receive()];
        });
        Loop::get()->run();
        self::assertSame(['initial', 'first', 'second', 'third'], $consumer->await());
        self::assertSame(['first', 'second', 'third'], $sent);
        foreach ($producers as $producer) {
            $producer->await();
        }
        self::assertSame(0, $channel->getWaitingSenders());
    }

    public function testClosePreservesQueuedMessagesAndRejectsBlockedSends(): void
    {
        $channel = new BoundedChannel(1);
        $channel->send('kept');
        $blockedSend = new Task(static function () use ($channel): string {
            try {
                $channel->send('not accepted');
            } catch (ChannelClosedException) {
                return 'closed';
            }
            return 'unexpected';
        });
        $closer = new Task(static function () use ($channel): void {
            Loop::get()->pause();
            $channel->close();
            $channel->close();
        });
        $drainer = new Task(static function () use ($channel): array {
            Loop::get()->pause();
            $values = [$channel->receive()];
            try {
                $channel->receive();
            } catch (ChannelClosedException) {
                $values[] = 'drained';
            }
            return $values;
        });
        Loop::get()->run();
        self::assertSame('closed', $blockedSend->await());
        self::assertSame(['kept', 'drained'], $drainer->await());
        self::assertTrue($channel->isClosed());
        self::assertSame(0, $channel->getQueueDepth());
        $closer->await();
    }

    public function testCloseWakesAllConsumersWaitingOnAnEmptyChannel(): void
    {
        $channel = new BoundedChannel(2);
        $consumers = [];
        for ($i = 0; $i < 3; $i++) {
            $consumers[] = new Task(static function () use ($channel): string {
                try {
                    $channel->receive();
                } catch (ChannelClosedException) {
                    return 'closed';
                }
                return 'unexpected';
            });
        }
        $closer = new Task(static function () use ($channel): void {
            Loop::get()->pause();
            $channel->close();
        });
        Loop::get()->run();
        foreach ($consumers as $consumer) {
            self::assertSame('closed', $consumer->await());
        }
        self::assertSame(0, $channel->getWaitingReceivers());
        $closer->await();
    }

    public function testCancelProducerBeforeAcceptanceDropsOnlyItsMessage(): void
    {
        $channel = new BoundedChannel(1);
        $channel->send('buffered');
        $producer = new Task(static fn () => $channel->send('cancelled'));
        $canceller = new Task(static function () use ($producer): void {
            Loop::get()->pause();
            $producer->cancel();
        });
        Loop::get()->run();
        self::assertSame(Task::CANCELLED, $producer->getState());
        self::assertSame('buffered', $channel->receive());
        self::assertSame(0, $channel->getWaitingSenders());
        try {
            $producer->await();
            self::fail('Expected TaskCancelled.');
        } catch (TaskCancelled) {
        }
        $canceller->await();
    }

    public function testCancelConsumerBeforeDeliveryLeavesMessageForNextConsumer(): void
    {
        $channel = new BoundedChannel(1);
        $consumer = new Task(static fn () => $channel->receive());
        $canceller = new Task(static function () use ($consumer): void {
            Loop::get()->pause();
            $consumer->cancel();
        });
        Loop::get()->run();
        self::assertSame(Task::CANCELLED, $consumer->getState());
        $channel->send('available');
        self::assertSame('available', $channel->receive());
        self::assertSame(0, $channel->getWaitingReceivers());
        try {
            $consumer->await();
            self::fail('Expected TaskCancelled.');
        } catch (TaskCancelled) {
        }
        $canceller->await();
    }

    public function testCancelReservedReceiverRestoresMessageForNextReceiver(): void
    {
        $channel = new BoundedChannel(1);
        $first = new Task(static fn () => $channel->receive());
        $second = new Task(static fn () => $channel->receive());
        $producer = new Task(static function () use ($channel): void {
            $channel->send('reserved');
        });
        $canceller = new Task(static function () use ($first): void {
            $first->cancel();
        });
        Loop::get()->run();
        self::assertSame(Task::CANCELLED, $first->getState());
        self::assertSame('reserved', $second->await());
        self::assertSame(0, $channel->getQueueDepth());
        self::assertSame(0, $channel->getWaitingReceivers());
        try {
            $first->await();
            self::fail('Expected TaskCancelled.');
        } catch (TaskCancelled) {
        }
        $producer->await();
        $canceller->await();
    }

    public function testCancellationAfterSendReservationDoesNotRetractAcceptedMessage(): void
    {
        $channel = new BoundedChannel(1);
        $channel->send('occupies capacity');
        $producer = new Task(static fn () => $channel->send('already accepted'));
        $consumer = new Task(static fn () => $channel->receive());
        $canceller = new Task(static function () use ($producer): void {
            $producer->cancel();
        });
        Loop::get()->run();
        self::assertSame('occupies capacity', $consumer->await());
        self::assertSame(Task::CANCELLED, $producer->getState());
        self::assertSame('already accepted', $channel->receive());
        try {
            $producer->await();
            self::fail('Expected TaskCancelled.');
        } catch (TaskCancelled) {
        }
        $canceller->await();
    }

    public function testConsumersAreServedInFifoOrder(): void
    {
        $channel = new BoundedChannel(1);
        $received = [];
        $consumers = [];
        for ($i = 0; $i < 3; $i++) {
            $consumers[] = new Task(static function () use ($channel, &$received, $i): void {
                $received[$i] = $channel->receive();
            });
        }
        $producer = new Task(static function () use ($channel): void {
            foreach ([10, 20, 30] as $value) {
                $channel->send($value);
            }
        });
        Loop::get()->run();
        self::assertSame([10, 20, 30], array_values($received));
        foreach ($consumers as $consumer) {
            $consumer->await();
        }
        $producer->await();
    }

    public function testMultipleProducersAndConsumersDeliverEveryMessageOnce(): void
    {
        $channel = new BoundedChannel(7);
        $produced = $consumed = [];
        $tasks = [];
        for ($p = 0; $p < 4; $p++) {
            $tasks[] = new Task(static function () use ($channel, &$produced, $p): void {
                for ($i = 0; $i < 250; $i++) {
                    $value = $p . ':' . $i;
                    $channel->send($value);
                    $produced[] = $value;
                }
            });
        }
        for ($c = 0; $c < 5; $c++) {
            $tasks[] = new Task(static function () use ($channel, &$consumed): void {
                for ($i = 0; $i < 200; $i++) {
                    $consumed[] = $channel->receive();
                }
            });
        }
        Loop::get()->run();
        self::assertCount(1_000, $produced);
        self::assertCount(1_000, $consumed);
        self::assertCount(1_000, array_unique($consumed));
        self::assertSame(0, $channel->getQueueDepth());
        foreach ($tasks as $task) {
            $task->await();
        }
    }

    public function testTwoThousandMessagesUseFixedTasksAndDrainAfterClose(): void
    {
        $channel = new BoundedChannel(1);
        $produced = $consumed = 0;
        $consumers = [];
        for ($i = 0; $i < 4; $i++) {
            $consumers[] = new Task(static function () use ($channel, &$consumed): void {
                try {
                    while (true) {
                        $channel->receive();
                        $consumed++;
                    }
                } catch (ChannelClosedException) {
                }
            });
        }
        $producer = new Task(static function () use ($channel, &$produced): void {
            for ($i = 0; $i < 2_000; $i++) {
                $channel->send($i);
                $produced++;
            }
            $channel->close();
        });
        Loop::get()->run();
        self::assertSame(2_000, $produced);
        self::assertSame(2_000, $consumed);
        self::assertLessThanOrEqual(1, $channel->getPeakQueueDepth());
        self::assertSame(0, $channel->getWaitingSenders());
        self::assertSame(0, $channel->getWaitingReceivers());
        $producer->await();
        foreach ($consumers as $consumer) {
            $consumer->await();
        }
    }

    public function testShutdownDetachesBlockedChannelProducersAndConsumers(): void
    {
        $loop = Loop::get();
        $full = new BoundedChannel(1);
        $empty = new BoundedChannel(1);
        $full->send('retained');
        $sender = new Task(static fn () => $full->send('pending'));
        $receiver = new Task(static fn () => $empty->receive());
        $loop->defer(static fn () => $loop->shutdown());
        $loop->run();

        self::assertSame(Loop::SHUT_DOWN, $loop->getState());
        self::assertSame(Task::CANCELLED, $sender->getState());
        self::assertSame(Task::CANCELLED, $receiver->getState());
        self::assertSame(0, $full->getWaitingSenders());
        self::assertSame(0, $empty->getWaitingReceivers());
        self::assertSame('retained', $full->receive());
        try {
            $sender->await();
            self::fail('Expected sender cancellation.');
        } catch (TaskCancelled) {
        }
        try {
            $receiver->await();
            self::fail('Expected receiver cancellation.');
        } catch (TaskCancelled) {
        }
    }

    public function testCloseKeepsMessageReservedForConsumerUntilItIsDelivered(): void
    {
        $channel = new BoundedChannel(1);
        $received = null;
        $consumer = new Task(static function () use ($channel, &$received): void {
            $received = $channel->receive();
        });
        $producer = new Task(static function () use ($channel): void {
            $channel->send('committed');
        });
        $closer = new Task(static function () use ($channel): void {
            $channel->close();
        });

        Loop::get()->run();

        self::assertSame('committed', $received);
        self::assertTrue($channel->isClosed());
        self::assertSame(0, $channel->getQueueDepth());
        $consumer->await();
        $producer->await();
        $closer->await();
        try {
            $channel->receive();
            self::fail('Expected closed and drained channel.');
        } catch (ChannelClosedException) {
        }
    }

    public function testCloseAndCancellationAfterReservationRestoreCommittedMessage(): void
    {
        $channel = new BoundedChannel(1);
        $first = new Task(static fn () => $channel->receive());
        $second = new Task(static function () use ($channel): string {
            try {
                $channel->receive();
            } catch (ChannelClosedException) {
                return 'closed';
            }
            return 'unexpected';
        });
        $producer = new Task(static function () use ($channel): void {
            $channel->send('preserved');
        });
        $closer = new Task(static function () use ($channel, $first): void {
            $channel->close();
            $first->cancel();
        });

        Loop::get()->run();

        self::assertSame(Task::CANCELLED, $first->getState());
        self::assertSame(Task::COMPLETED, $second->getState());
        self::assertSame('preserved', $channel->receive());
        self::assertSame(0, $channel->getQueueDepth());
        self::assertSame(0, $channel->getWaitingReceivers());
        $producer->await();
        $closer->await();
        try {
            $first->await();
            self::fail('Expected cancellation.');
        } catch (TaskCancelled) {
        }
        self::assertSame('closed', $second->await());
    }

    public function testCloseAndCancellationOfQueuedSenderDoNotDeliverStaleWake(): void
    {
        $channel = new BoundedChannel(1);
        $channel->send('buffered');
        $sender = new Task(static function () use ($channel): void {
            $channel->send('must not be accepted');
        });
        $closer = new Task(static function () use ($channel, $sender): void {
            $channel->close();
            $sender->cancel();
        });

        Loop::get()->run();

        self::assertSame(Task::CANCELLED, $sender->getState());
        self::assertSame(0, $channel->getWaitingSenders());
        self::assertSame('buffered', $channel->receive());
        self::assertSame(0, $channel->getQueueDepth());
        $closer->await();
        try {
            $sender->await();
            self::fail('Expected cancellation.');
        } catch (TaskCancelled) {
        }
    }

    public function testShutdownRestoresAReservedAcceptedMessage(): void
    {
        $channel = new BoundedChannel(1);
        $consumer = new Task(static fn () => $channel->receive());
        $producer = new Task(static function () use ($channel): void {
            $channel->send('accepted');
        });
        Loop::get()->defer(static fn () => Loop::get()->shutdown());

        Loop::get()->run();

        self::assertSame(Task::CANCELLED, $consumer->getState());
        self::assertSame(Task::COMPLETED, $producer->getState());
        self::assertSame(0, $channel->getWaitingSenders());
        self::assertSame(0, $channel->getWaitingReceivers());
        self::assertSame('accepted', $channel->receive());
        try {
            $consumer->await();
            self::fail('Expected cancellation.');
        } catch (TaskCancelled) {
        }
        $producer->await();
    }

    public function testCancelledSenderClearsUnacceptedPayloadFromWaiter(): void
    {
        $channel = new BoundedChannel(1);
        $channel->send('fills capacity');
        $sender = new Task(static function () use ($channel): void {
            $channel->send(new \stdClass());
        });

        Loop::get()->run(static fn (): bool => $channel->getWaitingSenders() === 1);
        $senderMap = (new ReflectionProperty(BoundedChannel::class, 'senders'))->getValue($channel);
        $waiter = array_values($senderMap)[0];
        $sender->cancel();
        Loop::get()->run();
        unset($sender);
        gc_collect_cycles();

        self::assertNull($waiter->value);
        self::assertSame('cancelled', $waiter->state);
        self::assertNull($waiter->handle);
        self::assertSame(1, $channel->getQueueDepth());
        self::assertSame(0, $channel->getWaitingSenders());
    }

    public function testDeliveredMessageIsReleasedAfterConsumerDropsIt(): void
    {
        $channel = new BoundedChannel(1);
        $payload = new \stdClass();
        $payloadReference = \WeakReference::create($payload);
        $channel->send($payload);
        unset($payload);

        $received = $channel->receive();
        self::assertNotNull($payloadReference->get());
        unset($received);
        gc_collect_cycles();

        self::assertNull($payloadReference->get());
        self::assertSame(0, $channel->getQueueDepth());
    }

    public function testCancelledReservedReceiveKeepsValueUntilDrainThenReleasesIt(): void
    {
        $channel = new BoundedChannel(1);
        $consumer = new Task(static fn () => $channel->receive());
        Loop::get()->run(static fn (): bool => $channel->getWaitingReceivers() === 1);

        $payload = new \stdClass();
        $payloadReference = \WeakReference::create($payload);
        $channel->send($payload);
        $consumer->cancel();
        Loop::get()->run();
        unset($payload, $consumer);
        gc_collect_cycles();

        self::assertNotNull($payloadReference->get());
        $drained = $channel->receive();
        unset($drained);
        gc_collect_cycles();
        self::assertNull($payloadReference->get());
        self::assertSame(0, $channel->getQueueDepth());
    }

    public function testOneHundredThousandMessagesWithCapacityOneHaveNoLossOrDuplicates(): void
    {
        $messageCount = 100_000;
        $consumerCount = 4;
        $perConsumer = intdiv($messageCount, $consumerCount);
        $channel = new BoundedChannel(1);
        $seen = [];
        $consumers = [];

        for ($consumer = 0; $consumer < $consumerCount; $consumer++) {
            $consumers[] = new Task(static function () use ($channel, &$seen, $perConsumer): void {
                for ($i = 0; $i < $perConsumer; $i++) {
                    $value = $channel->receive();
                    if (isset($seen[$value])) {
                        throw new \LogicException('Duplicate channel delivery.');
                    }
                    $seen[$value] = true;
                }
            });
        }

        $producer = new Task(static function () use ($channel, $messageCount): void {
            for ($i = 0; $i < $messageCount; $i++) {
                $channel->send($i);
            }
            $channel->close();
        });

        Loop::get()->run();

        self::assertCount($messageCount, $seen);
        self::assertSame(($messageCount * ($messageCount - 1)) / 2, array_sum(array_keys($seen)));
        self::assertLessThanOrEqual(1, $channel->getPeakQueueDepth());
        self::assertSame(0, $channel->getWaitingSenders());
        self::assertSame(0, $channel->getWaitingReceivers());
        $producer->await();
        foreach ($consumers as $consumer) {
            $consumer->await();
        }
    }
}
