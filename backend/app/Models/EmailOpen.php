<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailOpen extends Model
{
    protected $fillable = [
        'campaign_id',
        'subscriber_id',
        'email',
        'ip_address',
        'user_agent',
        'opened_at',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
    ];

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    public function subscriber()
    {
        return $this->belongsTo(Subscriber::class);
    }
}
