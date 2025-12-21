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

    public function getListIdsAttribute()
    {
        // Return array of list IDs from the many-to-many relationship
        return $this->lists()->pluck('lists.id')->toArray();
    }
}

