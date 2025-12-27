<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// 从命令行参数获取活动 ID
$campaignId = $argv[1] ?? null;

if (!$campaignId) {
    echo "用法: php diagnose-campaign.php <campaign_id>\n";
    echo "示例: php diagnose-campaign.php 20\n";
    exit(1);
}

echo "=== 诊断活动 ID {$campaignId} ===\n\n";

try {
    // 检查活动是否存在（包括软删除的）
    $campaign = \App\Models\Campaign::withTrashed()->find($campaignId);
    
    if (!$campaign) {
        echo "❌ 活动 ID {$campaignId} 不存在\n";
        exit(1);
    }
    
    echo "✅ 活动存在\n";
    echo "   ID: {$campaign->id}\n";
    echo "   名称: {$campaign->name}\n";
    echo "   状态: {$campaign->status}\n";
    echo "   创建时间: {$campaign->created_at}\n";
    echo "   更新时间: {$campaign->updated_at}\n";
    
    if ($campaign->deleted_at) {
        echo "   ⚠️  已软删除: {$campaign->deleted_at}\n";
    } else {
        echo "   未删除: ✓\n";
    }
    
    echo "\n=== 测试各个关系加载 ===\n\n";
    
    // 1. 测试 list 关系
    echo "1. list 关系:\n";
    try {
        $list = $campaign->list;
        if ($list) {
            echo "   ✅ 加载成功: {$list->name} (ID: {$list->id})\n";
        } else {
            echo "   ⚠️  为 null\n";
        }
    } catch (\Exception $e) {
        echo "   ❌ 失败: {$e->getMessage()}\n";
    }
    
    // 2. 测试 lists 关系
    echo "\n2. lists 关系:\n";
    try {
        $lists = $campaign->lists;
        echo "   ✅ 加载成功: 共 {$lists->count()} 个列表\n";
        foreach ($lists as $list) {
            echo "      - {$list->name} (ID: {$list->id})\n";
        }
    } catch (\Exception $e) {
        echo "   ❌ 失败: {$e->getMessage()}\n";
        echo "   堆栈: " . substr($e->getTraceAsString(), 0, 500) . "\n";
    }
    
    // 3. 测试 smtpServer 关系
    echo "\n3. smtpServer 关系:\n";
    try {
        $server = $campaign->smtpServer;
        if ($server) {
            echo "   ✅ 加载成功: {$server->name} (ID: {$server->id})\n";
        } else {
            echo "   ❌ 为 null (smtp_server_id: {$campaign->smtp_server_id})\n";
        }
    } catch (\Exception $e) {
        echo "   ❌ 失败: {$e->getMessage()}\n";
        echo "   堆栈: " . substr($e->getTraceAsString(), 0, 500) . "\n";
    }
    
    // 4. 测试 sends 统计
    echo "\n4. sends 关系:\n";
    try {
        $sendsCount = $campaign->sends()->count();
        echo "   ✅ 统计成功: {$sendsCount} 条记录\n";
        if ($sendsCount > 100000) {
            echo "   ⚠️  警告: 发送记录过多，不建议直接加载所有记录\n";
        }
    } catch (\Exception $e) {
        echo "   ❌ 失败: {$e->getMessage()}\n";
    }
    
    // 5. 测试 list_ids accessor
    echo "\n5. list_ids accessor:\n";
    try {
        $listIds = $campaign->list_ids;
        echo "   ✅ 获取成功: " . json_encode($listIds) . "\n";
    } catch (\Exception $e) {
        echo "   ❌ 失败: {$e->getMessage()}\n";
        echo "   堆栈: " . substr($e->getTraceAsString(), 0, 500) . "\n";
    }
    
    // 6. 模拟 CampaignController::show 的完整加载
    echo "\n=== 模拟 API show 方法 ===\n\n";
    try {
        $freshCampaign = \App\Models\Campaign::find($campaignId);
        
        if (!$freshCampaign) {
            echo "❌ find() 返回 null (活动可能已被软删除)\n";
        } else {
            echo "✅ find() 成功\n";
            
            // 加载关系
            echo "   加载 list 关系...\n";
            $freshCampaign->load('list');
            echo "   ✓\n";
            
            echo "   加载 lists 关系...\n";
            $freshCampaign->load('lists');
            echo "   ✓\n";
            
            echo "   加载 smtpServer 关系...\n";
            $freshCampaign->load('smtpServer');
            echo "   ✓\n";
            
            // 测试 JSON 序列化
            echo "\n   测试 JSON 序列化...\n";
            $json = json_encode($freshCampaign);
            if ($json === false) {
                echo "   ❌ JSON 序列化失败: " . json_last_error_msg() . "\n";
            } else {
                $length = strlen($json);
                echo "   ✅ JSON 序列化成功 (长度: {$length} 字节)\n";
                
                // 如果内容较小，显示一部分
                if ($length < 5000) {
                    echo "\n   JSON 预览:\n";
                    echo "   " . substr($json, 0, 1000) . "\n";
                }
            }
        }
    } catch (\Exception $e) {
        echo "❌ 模拟失败\n";
        echo "   错误类型: " . get_class($e) . "\n";
        echo "   错误消息: {$e->getMessage()}\n";
        echo "   文件: {$e->getFile()}:{$e->getLine()}\n";
        echo "\n   完整堆栈跟踪:\n";
        echo $e->getTraceAsString() . "\n";
    }
    
    echo "\n=== 检查数据库完整性 ===\n\n";
    
    // 检查 campaign_list 表
    echo "campaign_list 关联:\n";
    $campaignLists = \DB::table('campaign_list')
        ->where('campaign_id', $campaignId)
        ->get();
    echo "   记录数: {$campaignLists->count()}\n";
    foreach ($campaignLists as $cl) {
        echo "   - list_id: {$cl->list_id}\n";
        
        // 检查列表是否存在
        $listExists = \DB::table('lists')->where('id', $cl->list_id)->exists();
        if (!$listExists) {
            echo "     ⚠️  警告: 列表 ID {$cl->list_id} 不存在！\n";
        }
    }
    
    // 检查 SMTP 服务器
    echo "\nSMTP 服务器:\n";
    echo "   smtp_server_id: {$campaign->smtp_server_id}\n";
    $smtpExists = \DB::table('smtp_servers')->where('id', $campaign->smtp_server_id)->exists();
    if ($smtpExists) {
        echo "   ✅ SMTP 服务器存在\n";
    } else {
        echo "   ❌ SMTP 服务器不存在！\n";
    }
    
    echo "\n=== 诊断完成 ===\n";
    
} catch (\Exception $e) {
    echo "\n❌ 诊断过程中发生错误\n";
    echo "   错误类型: " . get_class($e) . "\n";
    echo "   错误消息: {$e->getMessage()}\n";
    echo "   文件: {$e->getFile()}:{$e->getLine()}\n";
    echo "\n完整堆栈跟踪:\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

