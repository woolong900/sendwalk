<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class ListSubscriber extends Pivot
{
    protected $table = 'list_subscriber';

    protected $fillable = [
        'list_id',
        'subscriber_id',
        'status',
        'subscribed_at',
        'unsubscribed_at',
    ];

    protected $casts = [
        'subscribed_at' => 'datetime',
        'unsubscribed_at' => 'datetime',
    ];

    public function list()
    {
        return $this->belongsTo(MailingList::class, 'list_id');
    }

    public function subscriber()
    {
        return $this->belongsTo(Subscriber::class, 'subscriber_id');
    }
}

