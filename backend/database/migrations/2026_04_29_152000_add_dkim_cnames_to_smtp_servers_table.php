<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smtp_servers', function (Blueprint $table) {
            $table->text('dkim_cnames')->nullable()->after('sender_email_index');
        });
    }

    public function down(): void
    {
        Schema::table('smtp_servers', function (Blueprint $table) {
            $table->dropColumn('dkim_cnames');
        });
    }
};
