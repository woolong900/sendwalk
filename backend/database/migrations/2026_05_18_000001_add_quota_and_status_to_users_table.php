<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('send_quota')->nullable()->after('role');
            $table->unsignedBigInteger('sent_quota_used')->default(0)->after('send_quota');
            $table->string('status')->default('active')->after('sent_quota_used');
            $table->index(['status', 'role']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['status', 'role']);
            $table->dropColumn(['send_quota', 'sent_quota_used', 'status']);
        });
    }
};
