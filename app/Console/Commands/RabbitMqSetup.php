<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Объявляет в RabbitMQ обменник, очереди и привязки из config/queue.php
 * (connections.rabbitmq: options.queue.exchange и topology.queues).
 *
 * Нужна, потому что пакет при публикации объявляет только обменник: без очереди
 * и привязки сообщение ушло бы в никуда. Всё объявляется как durable, повторный
 * запуск ничего не меняет, поэтому команда выполняется при каждом старте воркера.
 */
class RabbitMqSetup extends Command
{
    protected $signature = 'rabbitmq:setup {connection=rabbitmq}';

    protected $description = 'Declare the RabbitMQ exchange, queues and bindings from config/queue.php';

    public function handle(): int
    {
        $connection = $this->argument('connection');
        $config = config("queue.connections.{$connection}");
        $exchange = $config['options']['queue']['exchange'] ?? null;
        $queues = $config['topology']['queues'] ?? [];

        if (! $exchange || $queues === []) {
            $this->error("queue.connections.{$connection} has no exchange or topology.queues");

            return self::FAILURE;
        }

        $this->call('rabbitmq:exchange-declare', [
            'name' => $exchange,
            'connection' => $connection,
            '--type' => $config['options']['queue']['exchange_type'] ?? 'direct',
        ]);

        foreach ($queues as $queue) {
            $this->call('rabbitmq:queue-declare', ['name' => $queue, 'connection' => $connection]);
            // routing key совпадает с exchange_routing_key = '%s', то есть с именем очереди
            $this->call('rabbitmq:queue-bind', [
                'queue' => $queue,
                'exchange' => $exchange,
                'connection' => $connection,
                '--routing-key' => $queue,
            ]);
        }

        $this->info("RabbitMQ ready: exchange {$exchange} -> ".implode(', ', $queues));

        return self::SUCCESS;
    }
}
