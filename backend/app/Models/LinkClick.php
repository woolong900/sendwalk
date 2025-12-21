<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LinkClick extends Model
{
    use HasFactory;

    protected $fillable = [
        'link_id',
        'subscriber_id',
        'ip_address',
        'user_agent',
        'country',
        'city',
        'clicked_at',
    ];

    protected $casts = [
        'clicked_at' => 'datetime',
    ];

    public function link()
    {
        return $this->belongsTo(Link::class);
    }

    public function subscriber()
    {
        return $this->belongsTo(Subscriber::class);
    }
}

