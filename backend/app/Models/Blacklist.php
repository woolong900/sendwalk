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
        $skipped = 0;
        $updated = 0;

        foreach ($emails as $email) {
            $email = strtolower(trim($email));
            
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped++;
                continue;
            }

            // Add to blacklist (skip if already exists)
            $blacklistEntry = self::firstOrCreate(
                ['user_id' => $userId, 'email' => $email],
                ['reason' => $reason]
            );

            if ($blacklistEntry->wasRecentlyCreated) {
                $added++;
                
                // Update all subscribers with this email to blacklisted status
                $updatedCount = Subscriber::where('email', $email)
                    ->where('status', '!=', 'blacklisted')
                    ->update(['status' => 'blacklisted']);
                    
                // Update list_subscriber pivot table status to blacklisted
                $subscriber = Subscriber::where('email', $email)->first();
                if ($subscriber) {
                    \DB::table('list_subscriber')
                        ->where('subscriber_id', $subscriber->id)
                        ->where('status', '!=', 'blacklisted')
                        ->update(['status' => 'blacklisted']);
                }
                    
                $updated += $updatedCount;
            } else {
                $skipped++;
            }
        }

        return [
            'added' => $added,
            'skipped' => $skipped,
            'subscribers_updated' => $updated,
        ];
    }
}
