<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Blacklist extends Model
{
    use HasFactory;

    protected $table = 'blacklist';

    protected $fillable = [
        'user_id',
        'email',
        'reason',
        'notes',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if an email is in the blacklist for a specific user
     */
    public static function isBlacklisted(int $userId, string $email): bool
    {
        return self::where('user_id', $userId)
            ->where('email', strtolower(trim($email)))
            ->exists();
    }

    /**
     * Add emails to blacklist and update subscribers
     */
    public static function addBatch(int $userId, array $emails, ?string $reason = null): array
    {
        $added = 0;
        $alreadyExists = 0;
        $invalid = 0;
        $totalUpdated = 0;
        
        // 添加调试信息
        \Log::info('开始批量添加黑名单', [
            'user_id' => $userId,
            'total_emails' => count($emails),
            'first_5_emails' => array_slice($emails, 0, 5),
        ]);

        $sampleInvalid = [];
        $sampleAlreadyExists = [];
        
        foreach ($emails as $index => $email) {
            $originalEmail = $email;
            $email = strtolower(trim($email));
            
            // 检查是否为空或格式无效
            if (empty($email)) {
                $invalid++;
                if (count($sampleInvalid) < 5) {
                    $sampleInvalid[] = "空邮箱 (原始: '" . $originalEmail . "')";
                }
                continue;
            }
            
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $invalid++;
                if (count($sampleInvalid) < 5) {
                    $sampleInvalid[] = "格式无效: " . $email;
                }
                continue;
            }

            // Add to blacklist (or get existing entry)
            try {
                $blacklistEntry = self::firstOrCreate(
                    ['user_id' => $userId, 'email' => $email],
                    ['reason' => $reason]
                );

                if ($blacklistEntry->wasRecentlyCreated) {
                    $added++;
                } else {
                    $alreadyExists++;
                    if (count($sampleAlreadyExists) < 5) {
                        $sampleAlreadyExists[] = $email;
                    }
                }
                
                // 无论是新增还是已存在，都更新相关订阅者状态
                $updatedCount = Subscriber::where('email', $email)
                    ->where('status', '!=', 'blacklisted')
                    ->update(['status' => 'blacklisted']);
                    
                $subscriber = Subscriber::where('email', $email)->first();
                if ($subscriber) {
                    \DB::table('list_subscriber')
                        ->where('subscriber_id', $subscriber->id)
                        ->where('status', '!=', 'blacklisted')
                        ->update(['status' => 'blacklisted']);
                }
                    
                $totalUpdated += $updatedCount;
            } catch (\Exception $e) {
                \Log::error('添加黑名单失败', [
                    'email' => $email,
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
                $invalid++;
            }
        }
        
        // 记录详细结果
        \Log::info('批量添加黑名单完成', [
            'user_id' => $userId,
            'added' => $added,
            'already_exists' => $alreadyExists,
            'invalid' => $invalid,
            'subscribers_updated' => $totalUpdated,
            'sample_invalid' => $sampleInvalid,
            'sample_already_exists' => $sampleAlreadyExists,
        ]);

        return [
            'added' => $added,
            'already_exists' => $alreadyExists,
            'invalid' => $invalid,
            'subscribers_updated' => $totalUpdated,
            'skipped' => $invalid,
        ];
    }
}
