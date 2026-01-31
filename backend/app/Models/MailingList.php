<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;

class MailingList extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'lists';

    // 列表类型常量
    const TYPE_MANUAL = 'manual';
    const TYPE_AUTO = 'auto';

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'type',
        'conditions',
        'custom_fields',
        'subscribers_count',
        'unsubscribed_count',
    ];

    protected $casts = [
        'custom_fields' => 'array',
        'conditions' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function subscribers()
    {
        return $this->belongsToMany(Subscriber::class, 'list_subscriber', 'list_id', 'subscriber_id')
            ->using(ListSubscriber::class)
            ->withPivot('status', 'subscribed_at', 'unsubscribed_at')
            ->withTimestamps();
    }

    public function campaigns()
    {
        return $this->hasMany(Campaign::class, 'list_id');
    }

    /**
     * 判断是否为自动列表
     */
    public function isAutoList(): bool
    {
        return $this->type === self::TYPE_AUTO;
    }

    /**
     * 判断是否为手动列表
     */
    public function isManualList(): bool
    {
        return $this->type === self::TYPE_MANUAL || $this->type === null;
    }

    /**
     * 获取自动列表的订阅者查询构建器
     * 
     * @return Builder|null
     */
    public function getAutoSubscribersQuery(): ?Builder
    {
        if (!$this->isAutoList() || empty($this->conditions)) {
            return null;
        }

        $conditions = $this->conditions;
        $logic = $conditions['logic'] ?? 'and';
        $rules = $conditions['rules'] ?? [];

        if (empty($rules)) {
            return null;
        }

        $query = Subscriber::where('status', 'active');

        if ($logic === 'and') {
            // AND 逻辑：所有条件都必须满足
            foreach ($rules as $rule) {
                $query = $this->applyRuleToQuery($query, $rule);
            }
        } else {
            // OR 逻辑：满足任一条件即可
            $query->where(function ($q) use ($rules) {
                foreach ($rules as $index => $rule) {
                    if ($index === 0) {
                        $this->applyRuleToQuery($q, $rule);
                    } else {
                        $q->orWhere(function ($subQ) use ($rule) {
                            $this->applyRuleToQuery($subQ, $rule);
                        });
                    }
                }
            });
        }

        return $query;
    }

    /**
     * 应用单个规则到查询
     * 
     * @param Builder $query
     * @param array $rule
     * @return Builder
     */
    protected function applyRuleToQuery(Builder $query, array $rule): Builder
    {
        $type = $rule['type'] ?? '';

        switch ($type) {
            case 'in_list':
                // 存在于某个列表
                $listId = $rule['list_id'] ?? null;
                if ($listId) {
                    $query->whereExists(function ($q) use ($listId) {
                        $q->select(\DB::raw(1))
                            ->from('list_subscriber')
                            ->whereColumn('list_subscriber.subscriber_id', 'subscribers.id')
                            ->where('list_subscriber.list_id', $listId)
                            ->where('list_subscriber.status', 'active');
                    });
                }
                break;

            case 'not_in_list':
                // 不存在于某个列表
                $listId = $rule['list_id'] ?? null;
                if ($listId) {
                    $query->whereNotExists(function ($q) use ($listId) {
                        $q->select(\DB::raw(1))
                            ->from('list_subscriber')
                            ->whereColumn('list_subscriber.subscriber_id', 'subscribers.id')
                            ->where('list_subscriber.list_id', $listId)
                            ->where('list_subscriber.status', 'active');
                    });
                }
                break;

            case 'has_opened':
                // 是否打开过任意活动邮件
                $value = $rule['value'] ?? true;
                if ($value) {
                    // 曾经打开过
                    $query->whereExists(function ($q) {
                        $q->select(\DB::raw(1))
                            ->from('campaign_sends')
                            ->whereColumn('campaign_sends.subscriber_id', 'subscribers.id')
                            ->whereNotNull('campaign_sends.opened_at');
                    });
                } else {
                    // 从未打开过
                    $query->whereNotExists(function ($q) {
                        $q->select(\DB::raw(1))
                            ->from('campaign_sends')
                            ->whereColumn('campaign_sends.subscriber_id', 'subscribers.id')
                            ->whereNotNull('campaign_sends.opened_at');
                    });
                }
                break;

            case 'has_delivered':
                // 是否送达过任意活动邮件
                $value = $rule['value'] ?? true;
                if ($value) {
                    // 曾经送达过
                    $query->whereExists(function ($q) {
                        $q->select(\DB::raw(1))
                            ->from('campaign_sends')
                            ->whereColumn('campaign_sends.subscriber_id', 'subscribers.id')
                            ->whereNotNull('campaign_sends.sent_at');
                    });
                } else {
                    // 从未送达过
                    $query->whereNotExists(function ($q) {
                        $q->select(\DB::raw(1))
                            ->from('campaign_sends')
                            ->whereColumn('campaign_sends.subscriber_id', 'subscribers.id')
                            ->whereNotNull('campaign_sends.sent_at');
                    });
                }
                break;
        }

        return $query;
    }

    /**
     * 获取订阅者数量
     * 
     * @return int
     */
    public function getSubscribersCountAttribute($value): int
    {
        return $value ?? 0;
    }

    /**
     * 重新同步自动列表的订阅者
     * 
     * @return void
     */
    public function syncAutoSubscribers(): void
    {
        if (!$this->isAutoList()) {
            return;
        }

        \App\Jobs\SyncAutoListSubscribers::dispatch($this->id)->onQueue('default');
    }
}

