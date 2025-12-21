<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

/**
 * 虚拟时间管理器
 * 
 * 用于队列任务的虚拟时间分配，解决多活动并发时的公平调度问题
 * 
 * 核心思想：
 * - 不使用绝对时间戳（wall clock time）
 * - 使用虚拟时间（virtual time）作为任务的排序依据
 * - 所有活动共享同一个虚拟时间轴
 * - 新活动从当前虚拟时间开始分配
 */
class VirtualTimeManager
{
    /**
     * Redis键前缀
     */
    protected $keyPrefix = 'queue:virtual_time:';
    
    /**
     * 虚拟时间间隔（秒）
     * 
     * 每个任务在虚拟时间轴上的间隔
     * 不影响实际执行，仅用于排序
     */
    protected $interval = 0.001; // 1ms
    
    /**
     * 获取队列的当前虚拟时间
     * 
     * @param string $queueName 队列名称
     * @return float 当前虚拟时间
     */
    public function getCurrentTime($queueName)
    {
        $key = $this->keyPrefix . $queueName;
        $time = Redis::get($key);
        
        if ($time === null) {
            // 首次使用，初始化为0
            $time = 0;
            Redis::set($key, $time);
        }
        
        return (float) $time;
    }
    
    /**
     * 为活动分配虚拟时间位置
     * 
     * @param string $queueName 队列名称
     * @param int $taskCount 任务数量
     * @param int $activeCampaigns 当前活跃的活动数
     * @param int $offset 活动偏移量
     * @return array ['start_time' => float, 'interval' => int]
     */
    public function allocateTime($queueName, $taskCount, $activeCampaigns, $offset)
    {
        $key = $this->keyPrefix . $queueName;
        
        // 使用Redis事务确保原子性
        $result = Redis::transaction(function ($redis) use ($key, $taskCount, $activeCampaigns, $offset) {
            // 获取当前虚拟时间
            $currentTime = $redis->get($key) ?: 0;
            $currentTime = (float) $currentTime;
            
            // 计算起始位置
            $interval = $activeCampaigns + 1;
            
            // 如果有其他活动正在发送，从当前虚拟时间的下一个属于该活动的位置开始
            if ($activeCampaigns > 0) {
                // 计算当前位置对应的position
                $currentPosition = $currentTime / $this->interval;
                // 找到下一个属于当前活动的位置
                $nextPosition = $currentPosition + (($offset - ($currentPosition % $interval) + $interval) % $interval);
                $startTime = $nextPosition * $this->interval;
            } else {
                // 第一个活动，从当前时间开始
                $startTime = $currentTime;
            }
            
            // 计算该活动的结束虚拟时间
            $endPosition = ($startTime / $this->interval) + ($taskCount * $interval);
            $endTime = $endPosition * $this->interval;
            
            // 更新虚拟时间（如果endTime更大）
            if ($endTime > $currentTime) {
                $redis->set($key, $endTime);
            }
            
            return [
                'start_time' => $startTime,
                'interval' => $interval,
                'current_time' => $currentTime,
                'end_time' => $endTime,
            ];
        });
        
        Log::info('Allocated virtual time', [
            'queue' => $queueName,
            'task_count' => $taskCount,
            'active_campaigns' => $activeCampaigns,
            'offset' => $offset,
            'start_time' => $result['start_time'],
            'end_time' => $result['end_time'],
            'interval' => $result['interval'],
        ]);
        
        return $result;
    }
    
    /**
     * 重置队列的虚拟时间
     * 
     * @param string $queueName 队列名称
     */
    public function reset($queueName)
    {
        $key = $this->keyPrefix . $queueName;
        Redis::set($key, 0);
        
        Log::info('Reset virtual time', [
            'queue' => $queueName,
        ]);
    }
    
    /**
     * 获取所有队列的虚拟时间
     * 
     * @return array
     */
    public function getAllTimes()
    {
        $keys = Redis::keys($this->keyPrefix . '*');
        $times = [];
        
        foreach ($keys as $key) {
            $queueName = str_replace($this->keyPrefix, '', $key);
            $times[$queueName] = (float) Redis::get($key);
        }
        
        return $times;
    }
}

