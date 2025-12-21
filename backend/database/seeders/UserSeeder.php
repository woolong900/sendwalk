<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 创建测试管理员账户
        User::create([
            'name' => 'Admin',
            'email' => 'admin@sendwalk.com',
            'password' => Hash::make('password'),
        ]);

        // 创建测试用户账户
        User::create([
            'name' => 'Test User',
            'email' => 'test@sendwalk.com',
            'password' => Hash::make('password'),
        ]);

        $this->command->info('✅ 测试用户创建成功！');
        $this->command->info('管理员 - Email: admin@sendwalk.com, Password: password');
        $this->command->info('测试用户 - Email: test@sendwalk.com, Password: password');
    }
}

