<?php

namespace Tests\Feature;

use App\Events\CommentCreated;
use App\Models\Comment;
use Tests\TestCase;

/**
 * Конфигурация RabbitMQ без живого брокера: схема в config/queue.php
 * и отказ rabbitmq:setup при неполном конфиге.
 */
class RabbitMqSetupTest extends TestCase
{
    public function test_broadcasts_go_to_their_own_queue_declared_in_topology(): void
    {
        $queue = (new CommentCreated(new Comment()))->broadcastQueue();

        $this->assertSame('broadcasts', $queue);
        $this->assertContains($queue, config('queue.connections.rabbitmq.topology.queues'));
    }

    public function test_rabbitmq_connection_uses_named_exchange_with_queue_name_as_routing_key(): void
    {
        $options = config('queue.connections.rabbitmq.options.queue');

        $this->assertSame('comments', $options['exchange']);
        $this->assertSame('direct', $options['exchange_type']);
        $this->assertSame('%s', $options['exchange_routing_key']);
    }

    public function test_setup_fails_without_exchange(): void
    {
        config(['queue.connections.rabbitmq.options.queue.exchange' => null]);

        $this->artisan('rabbitmq:setup')
            ->expectsOutputToContain('has no exchange or topology.queues')
            ->assertFailed();
    }
}
