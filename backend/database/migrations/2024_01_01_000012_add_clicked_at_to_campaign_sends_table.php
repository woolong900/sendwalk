<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_sends', function (Blueprint $table) {
            $table->timestamp('clicked_at')->nullable()->after('opened_at');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_sends', function (Blueprint $table) {
            $table->dropColumn('clicked_at');
        });
    }
};

