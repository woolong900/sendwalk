<?php

namespace App\Observers;

use App\Models\ListSubscriber;
use App\Models\MailingList;

class ListSubscriberObserver
{
    /**
     * 订阅关系创建后更新计数
     */
    public function created(ListSubscriber $listSubscriber): void
    {
        $this->updateListCounts($listSubscriber->list_id);
    }

    /**
     * 订阅关系更新后更新计数
     */
    public function updated(ListSubscriber $listSubscriber): void
    {
        $this->updateListCounts($listSubscriber->list_id);
    }

    /**
     * 订阅关系删除后更新计数
     */
    public function deleted(ListSubscriber $listSubscriber): void
    {
        $this->updateListCounts($listSubscriber->list_id);
    }

    /**
     * 更新列表的订阅者计数
     */
    protected function updateListCounts(int $listId): void
    {
        $list = MailingList::find($listId);
        
        if (!$list) {
            return;
        }

        // 统计活跃订阅者
        $subscribersCount = ListSubscriber::where('list_id', $listId)
            ->where('status', 'active')
            ->count();

        // 统计取消订阅者
        $unsubscribedCount = ListSubscriber::where('list_id', $listId)
            ->where('status', 'unsubscribed')
            ->count();

        // 更新列表计数，但不触发模型事件
        $list->timestamps = false;
        $list->subscribers_count = $subscribersCount;
        $list->unsubscribed_count = $unsubscribedCount;
        $list->save();
        $list->timestamps = true;
    }
}

