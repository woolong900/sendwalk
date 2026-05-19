<?php

namespace App\Console\Commands;

use App\Models\SmtpServer;
use App\Models\User;
use Illuminate\Console\Command;

class CopySmtpServerToUser extends Command
{
    protected $signature = 'smtp:copy-to-user
                            {target_user_id : 目标用户 ID}
                            {--source-id= : 源 SMTP 服务器 ID（与 --source-name 二选一）}
                            {--source-name= : 源 SMTP 服务器名称（模糊匹配，需配合 --source-username 或 --source-user-id）}
                            {--source-username= : 源 SMTP 服务器 username（一般是邮箱地址）}
                            {--source-user-id= : 源 SMTP 服务器所属用户 ID（用于精确定位）}
                            {--new-name= : 复制后新服务器的名称，默认沿用原名}';

    protected $description = '把一个 SMTP 服务器（完整配置含密码）复制到指定用户名下';

    public function handle(): int
    {
        $targetUserId = (int) $this->argument('target_user_id');
        $targetUser = User::find($targetUserId);

        if (!$targetUser) {
            $this->error("目标用户不存在: ID = {$targetUserId}");
            return 1;
        }

        // 1. 定位源服务器
        $server = null;
        if ($sourceId = $this->option('source-id')) {
            $server = SmtpServer::find($sourceId);
        } else {
            $query = SmtpServer::query();

            if ($name = $this->option('source-name')) {
                $query->where('name', 'like', "%{$name}%");
            }
            if ($username = $this->option('source-username')) {
                $query->where('username', $username);
            }
            if ($sourceUserId = $this->option('source-user-id')) {
                $query->where('user_id', $sourceUserId);
            }

            $candidates = $query->get();

            if ($candidates->count() === 0) {
                $this->error('未找到匹配的源 SMTP 服务器');
                return 1;
            }

            if ($candidates->count() > 1) {
                $this->error("找到 {$candidates->count()} 个匹配的服务器，请提供更精确的条件（建议直接用 --source-id）：");
                $this->table(
                    ['ID', 'user_id', 'name', 'type', 'username', 'host'],
                    $candidates->map(fn ($s) => [
                        $s->id, $s->user_id, $s->name, $s->type, $s->username, $s->host,
                    ])->toArray()
                );
                return 1;
            }

            $server = $candidates->first();
        }

        if (!$server) {
            $this->error('未找到源 SMTP 服务器');
            return 1;
        }

        $this->info('找到源服务器:');
        $this->table(
            ['Key', 'Value'],
            [
                ['id', $server->id],
                ['user_id', $server->user_id],
                ['name', $server->name],
                ['type', $server->type],
                ['host', $server->host],
                ['username', $server->username],
                ['sender_emails', $server->sender_emails],
            ]
        );

        if ($server->user_id === $targetUserId) {
            $this->warn("源服务器已经属于目标用户 (user_id={$targetUserId})，无需复制。");
            return 0;
        }

        if (!$this->confirm("确认把上述服务器复制到 user_id={$targetUserId} ({$targetUser->email})？")) {
            $this->info('已取消。');
            return 0;
        }

        // 2. 复制服务器（包含 password、credentials 等所有字段）
        $newServer = $server->replicate();
        $newServer->user_id = $targetUserId;
        $newServer->name = $this->option('new-name') ?: $server->name;
        $newServer->is_default = false;       // 副本默认不是默认服务器
        $newServer->emails_sent_today = 0;    // 重置发送计数
        $newServer->last_reset_date = null;
        $newServer->created_at = now();
        $newServer->updated_at = now();
        $newServer->save();

        $this->info('✅ 复制成功！');
        $this->table(
            ['Key', 'Value'],
            [
                ['新服务器 ID', $newServer->id],
                ['所属 user_id', $newServer->user_id],
                ['name', $newServer->name],
                ['type', $newServer->type],
            ]
        );

        return 0;
    }
}
