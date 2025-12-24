<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Template extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'category',
        'description',
        'thumbnail',
        'html_content',
        'plain_content',
        'is_default',
        'is_active',
        'usage_count',
        'last_used_at',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
    ];

    /**
     * 模板分类选项
     */
    public const CATEGORIES = [
        'general' => '通用',
        'marketing' => '营销推广',
        'transactional' => '交易通知',
        'newsletter' => '新闻资讯',
        'welcome' => '欢迎邮件',
        'announcement' => '公告通知',
    ];

    /**
     * 关联用户
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 记录模板使用
     */
    public function recordUsage(): void
    {
        $this->increment('usage_count');
        $this->update(['last_used_at' => now()]);
    }

    /**
     * 获取分类名称
     */
    public function getCategoryNameAttribute(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }

    /**
     * 复制模板
     */
    public function duplicate(int $userId, ?string $newName = null): self
    {
        $template = $this->replicate();
        $template->user_id = $userId;
        $template->name = $newName ?? $this->name . ' (副本)';
        $template->is_default = false;
        $template->usage_count = 0;
        $template->last_used_at = null;
        $template->save();

        return $template;
    }
}
