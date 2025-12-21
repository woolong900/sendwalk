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
        \DB::statement("ALTER TABLE subscribers MODIFY COLUMN status ENUM('active', 'unsubscribed', 'bounced', 'complained', 'blacklisted') DEFAULT 'active'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // First update all blacklisted statuses to active before removing the enum value
        \DB::statement("UPDATE subscribers SET status = 'active' WHERE status = 'blacklisted'");
        \DB::statement("ALTER TABLE subscribers MODIFY COLUMN status ENUM('active', 'unsubscribed', 'bounced', 'complained') DEFAULT 'active'");
    }
};
