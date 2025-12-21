<?php

namespace App\Providers;

use App\Queue\SortOrderDatabaseQueue;
use Illuminate\Support\ServiceProvider;
use Illuminate\Queue\QueueManager;

/**
 * 注册自定义队列驱动：sort-order-database
 * 
 * 使用 sort_order 字段排序，而不是 available_at
 */
class SortOrderQueueServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        /** @var QueueManager $queue */
        $queue = $this->app['queue'];

        $queue->addConnector('sort-order-database', function () {
            return new SortOrderDatabaseConnector($this->app['db']);
        });
    }
}

/**
 * 自定义数据库队列连接器
 */
class SortOrderDatabaseConnector
{
    /**
     * Database manager instance.
     *
     * @var \Illuminate\Database\DatabaseManager
     */
    protected $database;

    /**
     * Create a new connector instance.
     *
     * @param  \Illuminate\Database\DatabaseManager  $database
     * @return void
     */
    public function __construct($database)
    {
        $this->database = $database;
    }

    /**
     * Establish a queue connection.
     *
     * @param  array  $config
     * @return \Illuminate\Contracts\Queue\Queue
     */
    public function connect(array $config)
    {
        return new SortOrderDatabaseQueue(
            $this->database->connection($config['connection'] ?? null),
            $config['table'],
            $config['queue'],
            $config['retry_after'] ?? 60,
            $config['after_commit'] ?? null
        );
    }
}

