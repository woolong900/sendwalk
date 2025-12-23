<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MailingList extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'lists';

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'custom_fields',
        'subscribers_count',
        'unsubscribed_count',
    ];

    protected $casts = [
        'custom_fields' => 'array',
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
}

