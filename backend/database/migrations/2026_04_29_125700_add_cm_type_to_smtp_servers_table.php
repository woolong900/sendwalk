<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE smtp_servers MODIFY COLUMN type ENUM('smtp', 'ses', 'sendgrid', 'mailgun', 'postmark', 'cm') NOT NULL DEFAULT 'smtp'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE smtp_servers MODIFY COLUMN type ENUM('smtp', 'ses', 'sendgrid', 'mailgun', 'postmark') NOT NULL DEFAULT 'smtp'");
    }
};
