<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Template;
use App\Models\User;

class TemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 获取第一个用户（如果存在）
        $user = User::first();
        
        if (!$user) {
            $this->command->warn('No users found. Skipping template seeding.');
            return;
        }

        $templates = [
            [
                'name' => '简约通知模板',
                'category' => 'transactional',
                'description' => '简洁的通知邮件模板，适用于系统通知、订单确认等场景',
                'html_content' => <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>通知</title>
</head>
<body style="margin: 0; padding: 0; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; background-color: #f4f4f4;">
    <table role="presentation" style="width: 100%; border-collapse: collapse;">
        <tr>
            <td align="center" style="padding: 40px 0;">
                <table role="presentation" style="width: 600px; max-width: 100%; border-collapse: collapse; background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="padding: 40px 40px 20px 40px; text-align: center; background-color: #3b82f6; border-radius: 8px 8px 0 0;">
                            <h1 style="margin: 0; color: #ffffff; font-size: 28px; font-weight: 600;">重要通知</h1>
                        </td>
                    </tr>
                    
                    <!-- Content -->
                    <tr>
                        <td style="padding: 40px;">
                            <p style="margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;">
                                你好 {first_name}，
                            </p>
                            <p style="margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;">
                                这是一封重要的通知邮件。您可以在此处添加通知内容。
                            </p>
                            <p style="margin: 0 0 30px 0; color: #333333; font-size: 16px; line-height: 1.6;">
                                感谢您的关注！
                            </p>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="padding: 30px 40px; background-color: #f8f9fa; border-radius: 0 0 8px 8px;">
                            <p style="margin: 0 0 10px 0; color: #6b7280; font-size: 14px; text-align: center;">
                                此邮件由 {sender_domain} 自动发送
                            </p>
                            <p style="margin: 0; color: #6b7280; font-size: 14px; text-align: center;">
                                <a href="{unsubscribe_url}" style="color: #3b82f6; text-decoration: none;">取消订阅</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML,
            ],
            [
                'name' => '营销推广模板',
                'category' => 'marketing',
                'description' => '适合产品促销、活动推广的邮件模板，带有醒目的 CTA 按钮',
                'html_content' => <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>特别优惠</title>
</head>
<body style="margin: 0; padding: 0; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; background-color: #f4f4f4;">
    <table role="presentation" style="width: 100%; border-collapse: collapse;">
        <tr>
            <td align="center" style="padding: 40px 0;">
                <table role="presentation" style="width: 600px; max-width: 100%; border-collapse: collapse; background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <!-- Hero Image -->
                    <tr>
                        <td style="padding: 0;">
                            <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); height: 200px; border-radius: 8px 8px 0 0; display: flex; align-items: center; justify-content: center;">
                                <h1 style="color: #ffffff; font-size: 36px; font-weight: 700; margin: 0; text-align: center;">🎉 特别优惠</h1>
                            </div>
                        </td>
                    </tr>
                    
                    <!-- Content -->
                    <tr>
                        <td style="padding: 40px;">
                            <h2 style="margin: 0 0 20px 0; color: #1f2937; font-size: 24px; font-weight: 600;">
                                嗨 {first_name}！
                            </h2>
                            <p style="margin: 0 0 20px 0; color: #4b5563; font-size: 16px; line-height: 1.6;">
                                我们为您准备了一份特别的优惠！现在行动，享受独家折扣。
                            </p>
                            <p style="margin: 0 0 30px 0; color: #4b5563; font-size: 16px; line-height: 1.6;">
                                这个机会不容错过，立即查看详情吧！
                            </p>
                            
                            <!-- CTA Button -->
                            <table role="presentation" style="margin: 0 auto;">
                                <tr>
                                    <td style="border-radius: 6px; background-color: #10b981;">
                                        <a href="#" style="display: inline-block; padding: 16px 40px; color: #ffffff; text-decoration: none; font-size: 16px; font-weight: 600;">
                                            立即查看 →
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="padding: 30px 40px; background-color: #f8f9fa; border-radius: 0 0 8px 8px;">
                            <p style="margin: 0 0 10px 0; color: #6b7280; font-size: 14px; text-align: center;">
                                来自 {sender_domain}
                            </p>
                            <p style="margin: 0; color: #6b7280; font-size: 14px; text-align: center;">
                                <a href="{unsubscribe_url}" style="color: #3b82f6; text-decoration: none;">取消订阅</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML,
            ],
            [
                'name' => '新闻资讯模板',
                'category' => 'newsletter',
                'description' => '适合定期发送的新闻通讯，可以添加多个内容块',
                'html_content' => <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>新闻通讯</title>
</head>
<body style="margin: 0; padding: 0; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; background-color: #f4f4f4;">
    <table role="presentation" style="width: 100%; border-collapse: collapse;">
        <tr>
            <td align="center" style="padding: 40px 0;">
                <table role="presentation" style="width: 600px; max-width: 100%; border-collapse: collapse; background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="padding: 30px 40px; border-bottom: 3px solid #3b82f6;">
                            <h1 style="margin: 0; color: #1f2937; font-size: 28px; font-weight: 700;">📰 本周精选</h1>
                            <p style="margin: 10px 0 0 0; color: #6b7280; font-size: 14px;">
                                {date} | {list_name}
                            </p>
                        </td>
                    </tr>
                    
                    <!-- Greeting -->
                    <tr>
                        <td style="padding: 30px 40px 20px 40px;">
                            <p style="margin: 0; color: #4b5563; font-size: 16px; line-height: 1.6;">
                                你好 {full_name}，
                            </p>
                        </td>
                    </tr>
                    
                    <!-- Article 1 -->
                    <tr>
                        <td style="padding: 20px 40px;">
                            <div style="border-left: 4px solid #3b82f6; padding-left: 20px;">
                                <h2 style="margin: 0 0 10px 0; color: #1f2937; font-size: 20px; font-weight: 600;">
                                    📌 文章标题一
                                </h2>
                                <p style="margin: 0; color: #4b5563; font-size: 15px; line-height: 1.6;">
                                    这里是文章摘要。您可以添加文章的简短描述，吸引读者点击阅读全文...
                                </p>
                                <p style="margin: 15px 0 0 0;">
                                    <a href="#" style="color: #3b82f6; text-decoration: none; font-weight: 600;">
                                        阅读更多 →
                                    </a>
                                </p>
                            </div>
                        </td>
                    </tr>
                    
                    <!-- Article 2 -->
                    <tr>
                        <td style="padding: 20px 40px;">
                            <div style="border-left: 4px solid #10b981; padding-left: 20px;">
                                <h2 style="margin: 0 0 10px 0; color: #1f2937; font-size: 20px; font-weight: 600;">
                                    📌 文章标题二
                                </h2>
                                <p style="margin: 0; color: #4b5563; font-size: 15px; line-height: 1.6;">
                                    另一篇精彩文章的摘要。继续添加更多有价值的内容...
                                </p>
                                <p style="margin: 15px 0 0 0;">
                                    <a href="#" style="color: #10b981; text-decoration: none; font-weight: 600;">
                                        阅读更多 →
                                    </a>
                                </p>
                            </div>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="padding: 30px 40px; background-color: #f8f9fa; border-radius: 0 0 8px 8px;">
                            <p style="margin: 0 0 10px 0; color: #6b7280; font-size: 14px; text-align: center;">
                                来自 {sender_domain}
                            </p>
                            <p style="margin: 0; color: #6b7280; font-size: 14px; text-align: center;">
                                <a href="{unsubscribe_url}" style="color: #3b82f6; text-decoration: none;">取消订阅</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML,
            ],
            [
                'name' => '欢迎邮件模板',
                'category' => 'welcome',
                'description' => '温暖的欢迎邮件，适合新订阅者或新用户',
                'html_content' => <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>欢迎加入</title>
</head>
<body style="margin: 0; padding: 0; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; background-color: #f4f4f4;">
    <table role="presentation" style="width: 100%; border-collapse: collapse;">
        <tr>
            <td align="center" style="padding: 40px 0;">
                <table role="presentation" style="width: 600px; max-width: 100%; border-collapse: collapse; background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="padding: 40px 40px 30px 40px; text-align: center;">
                            <div style="font-size: 60px; margin-bottom: 20px;">👋</div>
                            <h1 style="margin: 0; color: #1f2937; font-size: 32px; font-weight: 700;">
                                欢迎加入我们！
                            </h1>
                        </td>
                    </tr>
                    
                    <!-- Content -->
                    <tr>
                        <td style="padding: 20px 40px 40px 40px;">
                            <p style="margin: 0 0 20px 0; color: #1f2937; font-size: 18px; line-height: 1.6; text-align: center; font-weight: 600;">
                                你好 {first_name}！
                            </p>
                            <p style="margin: 0 0 20px 0; color: #4b5563; font-size: 16px; line-height: 1.6; text-align: center;">
                                非常高兴您加入我们的大家庭。我们将为您提供最新的资讯和优质的服务。
                            </p>
                            <p style="margin: 0 0 30px 0; color: #4b5563; font-size: 16px; line-height: 1.6; text-align: center;">
                                让我们一起开启美好的旅程！
                            </p>
                            
                            <!-- CTA Button -->
                            <table role="presentation" style="margin: 0 auto;">
                                <tr>
                                    <td style="border-radius: 6px; background-color: #3b82f6;">
                                        <a href="#" style="display: inline-block; padding: 16px 40px; color: #ffffff; text-decoration: none; font-size: 16px; font-weight: 600;">
                                            开始探索 →
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    
                    <!-- Features -->
                    <tr>
                        <td style="padding: 30px 40px; background-color: #f8f9fa;">
                            <h3 style="margin: 0 0 20px 0; color: #1f2937; font-size: 18px; font-weight: 600; text-align: center;">
                                您将获得：
                            </h3>
                            <table role="presentation" style="width: 100%;">
                                <tr>
                                    <td style="padding: 10px; text-align: center;">
                                        <span style="font-size: 24px;">✨</span>
                                        <p style="margin: 5px 0 0 0; color: #4b5563; font-size: 14px;">
                                            独家内容
                                        </p>
                                    </td>
                                    <td style="padding: 10px; text-align: center;">
                                        <span style="font-size: 24px;">🎁</span>
                                        <p style="margin: 5px 0 0 0; color: #4b5563; font-size: 14px;">
                                            优惠活动
                                        </p>
                                    </td>
                                    <td style="padding: 10px; text-align: center;">
                                        <span style="font-size: 24px;">📧</span>
                                        <p style="margin: 5px 0 0 0; color: #4b5563; font-size: 14px;">
                                            最新资讯
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="padding: 30px 40px; background-color: #f8f9fa; border-radius: 0 0 8px 8px;">
                            <p style="margin: 0 0 10px 0; color: #6b7280; font-size: 14px; text-align: center;">
                                来自 {sender_domain}
                            </p>
                            <p style="margin: 0; color: #6b7280; font-size: 14px; text-align: center;">
                                <a href="{unsubscribe_url}" style="color: #3b82f6; text-decoration: none;">取消订阅</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML,
            ],
        ];

        foreach ($templates as $templateData) {
            Template::create([
                'user_id' => $user->id,
                'name' => $templateData['name'],
                'category' => $templateData['category'],
                'description' => $templateData['description'],
                'html_content' => $templateData['html_content'],
                'is_default' => true, // 标记为系统默认模板
                'is_active' => true,
            ]);
        }

        $this->command->info('Default templates created successfully!');
    }
}
