<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar',
        'role',
        'send_quota',
        'sent_quota_used',
        'status',
    ];

    protected $appends = [
        'remaining_quota',
        'is_banned',
    ];
    
    /**
     * 检查用户是否是管理员
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function getIsBannedAttribute(): bool
    {
        return $this->status === 'banned';
    }

    public function getRemainingQuotaAttribute(): ?int
    {
        if ($this->send_quota === null) {
            return null;
        }

        return max(0, $this->send_quota - $this->sent_quota_used);
    }

    public function hasSendQuota(int $count = 1): bool
    {
        if ($this->send_quota === null) {
            return true;
        }

        return $this->remaining_quota >= $count;
    }

    public function consumeSendQuota(int $count = 1): bool
    {
        return DB::transaction(function () use ($count) {
            $user = self::whereKey($this->id)->lockForUpdate()->first();

            if (!$user || !$user->isActive()) {
                return false;
            }

            if ($user->send_quota !== null && $user->sent_quota_used + $count > $user->send_quota) {
                return false;
            }

            $user->increment('sent_quota_used', $count);
            $user->refresh();
            $this->sent_quota_used = $user->sent_quota_used;

            return true;
        });
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'send_quota' => 'integer',
            'sent_quota_used' => 'integer',
        ];
    }
}

