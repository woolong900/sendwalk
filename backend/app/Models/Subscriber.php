<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Subscriber extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'email',
        'first_name',
        'last_name',
        'custom_fields',
        'status',
        'subscribed_at',
        'unsubscribed_at',
        'ip_address',
        'source',
        'bounce_count',
        'last_bounce_at',
    ];

    protected $casts = [
        'custom_fields' => 'array',
        'subscribed_at' => 'datetime',
        'unsubscribed_at' => 'datetime',
        'last_bounce_at' => 'datetime',
    ];

    public function lists()
    {
        return $this->belongsToMany(MailingList::class, 'list_subscriber', 'subscriber_id', 'list_id')
            ->withPivot('status', 'subscribed_at')
            ->withTimestamps();
    }

    public function campaignSends()
    {
        return $this->hasMany(CampaignSend::class);
    }

    public function getFullNameAttribute()
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}

