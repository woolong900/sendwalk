<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'values',
    ];

    /**
     * 获取随机值
     */
    public function getRandomValue(): string
    {
        $values = $this->getValuesArray();
        
        if (empty($values)) {
            return '';
        }
        
        return $values[array_rand($values)];
    }

    /**
     * 获取值数组
     */
    public function getValuesArray(): array
    {
        if (empty($this->values)) {
            return [];
        }
        
        // 按行分割
        $lines = explode("\n", $this->values);
        
        // 过滤空行并去除首尾空格
        return array_filter(array_map('trim', $lines), function($line) {
            return !empty($line);
        });
    }

    /**
     * 获取值数量
     */
    public function getValuesCount(): int
    {
        return count($this->getValuesArray());
    }

    /**
     * 获取标签占位符
     */
    public function getPlaceholder(): string
    {
        return '{' . $this->name . '}';
    }

    /**
     * 关联用户
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

