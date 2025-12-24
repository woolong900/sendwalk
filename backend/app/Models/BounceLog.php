<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BounceLog extends Model
{
    protected $fillable = [
        'subscriber_id',
        'campaign_id',
        'email',
        'bounce_type',
        'error_code',
        'error_message',
        'smtp_response',
    ];

    /**
     * 关联订阅者
     */
    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    /**
     * 关联活动
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
