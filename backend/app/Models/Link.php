<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Link extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'original_url',
        'hash',
        'click_count',
        'unique_click_count',
    ];

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    public function clicks()
    {
        return $this->hasMany(LinkClick::class);
    }
}

