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
        Schema::table('smtp_servers', function (Blueprint $table) {
            $table->integer('sender_email_index')->default(0)->after('sender_emails');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('smtp_servers', function (Blueprint $table) {
            $table->dropColumn('sender_email_index');
        });
    }
};
