<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Campaign extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'list_id',
        'smtp_server_id',
        'name',
        'subject',
        'preview_text',
        'from_name',
        'from_email',
        'reply_to',
        'html_content',
        'plain_content',
        'status',
        'scheduled_at',
        'sent_at',
        'total_recipients',
        'total_sent',
        'total_delivered',
        'total_opened',
        'total_clicked',
        'total_bounced',
        'total_complained',
        'total_unsubscribed',
        'ab_test_config',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
        'ab_test_config' => 'array',
    ];

    protected $appends = [
        'open_rate',
        'click_rate',
        'complaint_rate',
        'delivery_rate',
        'bounce_rate',
        'unsubscribe_rate',
        'list_ids',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function list()
    {
        return $this->belongsTo(MailingList::class, 'list_id');
    }

    // Many-to-many relationship with lists
    public function lists()
    {
        return $this->belongsToMany(MailingList::class, 'campaign_list', 'campaign_id', 'list_id')
            ->withTimestamps();
    }

    public function smtpServer()
    {
        return $this->belongsTo(SmtpServer::class, 'smtp_server_id');
    }

    public function sends()
    {
        return $this->hasMany(CampaignSend::class);
    }

    public function links()
    {
        return $this->hasMany(Link::class);
    }

    public function abuseReports()
    {
        return $this->hasMany(AbuseReport::class);
    }

    public function getOpenRateAttribute()
    {
        if ($this->total_delivered == 0) return 0;
        return round(($this->total_opened / $this->total_delivered) * 100, 2);
    }

    public function getClickRateAttribute()
    {
        if ($this->total_delivered == 0) return 0;
        return round(($this->total_clicked / $this->total_delivered) * 100, 2);
    }

    public function getComplaintRateAttribute()
    {
        if ($this->total_delivered == 0) return 0;
        return round(($this->total_complained / $this->total_delivered) * 100, 2);
    }

    public function getDeliveryRateAttribute()
    {
        if ($this->total_sent == 0) return 0;
        return round(($this->total_delivered / $this->total_sent) * 100, 2);
    }

    public function getBounceRateAttribute()
    {
        if ($this->total_sent == 0) return 0;
        return round(($this->total_bounced / $this->total_sent) * 100, 2);
    }

    public function getUnsubscribeRateAttribute()
    {
        if ($this->total_delivered == 0) return 0;
        return round(($this->total_unsubscribed / $this->total_delivered) * 100, 2);
    }

    public function getListIdsAttribute()
    {
        // 使用已加载的关系（如果已加载），避免额外的数据库查询
        if ($this->relationLoaded('lists')) {
            return $this->lists->pluck('id')->toArray();
        }
        
        // 如果关系未加载，执行查询
        return $this->lists()->pluck('lists.id')->toArray();
    }
}

