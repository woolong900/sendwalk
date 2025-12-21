<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        \DB::statement("ALTER TABLE list_subscriber MODIFY COLUMN status ENUM('pending', 'active', 'unsubscribed', 'blacklisted') DEFAULT 'pending'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // First update all blacklisted statuses to unsubscribed before removing the enum value
        \DB::statement("UPDATE list_subscriber SET status = 'unsubscribed' WHERE status = 'blacklisted'");
        \DB::statement("ALTER TABLE list_subscriber MODIFY COLUMN status ENUM('pending', 'active', 'unsubscribed') DEFAULT 'pending'");
    }
};
