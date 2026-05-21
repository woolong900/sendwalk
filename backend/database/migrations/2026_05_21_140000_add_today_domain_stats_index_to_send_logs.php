<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 为 DashboardController::todayDomainStats 的查询加上专用复合索引。
 *
 * 查询模式：
 *   WHERE campaign_id IN (...) AND created_at BETWEEN today AND tomorrow
 *   GROUP BY domain(SUBSTRING_INDEX(from_email,'@',-1))
 *
 * 现有索引：
 *   (campaign_id, status)            — 无 created_at，命中后仍需回表扫历史
 *   (created_at)                     — 仅 created_at，IN(...) 子句还要回表
 *   (smtp_server_id, created_at, status) — 不匹配 campaign_id 过滤
 *
 * 新增复合索引 (campaign_id, created_at, from_email(64)):
 * - campaign_id + created_at 直接定位今日范围，避免大范围扫描
 * - 把 from_email 前缀放到索引里，让 GROUP BY 时不必回表读完整行
 * - from_email(64) 前缀长度足够区分绝大多数邮箱
 */
return new class extends Migration
{
    public function up(): void
    {
        // 用 raw SQL 指定 from_email 列的索引前缀长度（Schema Builder 不支持）
        \DB::statement('
            ALTER TABLE send_logs
            ADD INDEX idx_campaign_time_from_email (campaign_id, created_at, from_email(64))
        ');
    }

    public function down(): void
    {
        \DB::statement('ALTER TABLE send_logs DROP INDEX idx_campaign_time_from_email');
    }
};
