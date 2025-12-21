<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Automation extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'list_id',
        'name',
        'description',
        'workflow_data',
        'trigger_type',
        'trigger_config',
        'is_active',
        'total_entered',
        'total_completed',
    ];

    protected $casts = [
        'workflow_data' => 'array',
        'trigger_config' => 'array',
        'is_active' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function list()
    {
        return $this->belongsTo(MailingList::class, 'list_id');
    }

    public function subscribers()
    {
        return $this->belongsToMany(Subscriber::class, 'automation_subscribers', 'automation_id', 'subscriber_id')
            ->withPivot('status', 'current_step', 'entered_at', 'completed_at')
            ->withTimestamps();
    }
}

