<?php

namespace App\Queue;

use Illuminate\Queue\DatabaseQueue;
use Illuminate\Contracts\Queue\Queue as QueueContract;

/**
 * 自定义数据库队列：按 sort_order 排序
 * 
 * 覆盖 Laravel 默认的按 available_at 排序逻辑
 * 改为按 sort_order 字段排序，实现齐头并进
 */
class SortOrderDatabaseQueue extends DatabaseQueue
{
    /**
     * 从队列中获取下一个可用任务
     *
     * @param  string|null  $queue
     * @return \Illuminate\Queue\Jobs\DatabaseJob|null
     */
    public function pop($queue = null)
    {
        $queue = $this->getQueue($queue);

        return $this->database->transaction(function () use ($queue) {
            if ($job = $this->getNextAvailableJob($queue)) {
                return $this->marshalJob($queue, $job);
            }

            return null;
        });
    }

    /**
     * 获取下一个可用的任务
     * 
     * 简化版本：不检查 available_at，完全按 sort_order 排序
     *
     * @param  string|null  $queue
     * @return \StdClass|null
     */
    protected function getNextAvailableJob($queue)
    {
        $job = $this->database->table($this->table)
            ->lock($this->getLockForPopping())
            ->where('queue', $this->getQueue($queue))
            ->whereNull('reserved_at')
            // 不检查 available_at，所有任务立即可执行
            ->orderBy('sort_order', 'asc')  // ← 唯一排序依据
            ->first();

        return $job ? $job : null;
    }

    /**
     * 标记任务为已保留
     *
     * @param  string  $queue
     * @param  \StdClass  $job
     * @return \StdClass
     */
    protected function marshalJob($queue, $job)
    {
        $job = $this->markJobAsReserved($job);

        return new \Illuminate\Queue\Jobs\DatabaseJob(
            $this->container,
            $this,
            $job,
            $this->connectionName,
            $queue
        );
    }

    /**
     * 标记任务为已保留（被 Worker 领取）
     *
     * @param  \StdClass  $job
     * @return \StdClass
     */
    protected function markJobAsReserved($job)
    {
        $this->database->table($this->table)->where('id', $job->id)->update([
            'reserved_at' => $job->reserved_at = $this->currentTime(),
            'attempts' => $job->attempts + 1,
        ]);

        return $job;
    }
}

