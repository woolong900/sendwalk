<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_blacklist', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('domain')->index();
            $table->string('reason')->nullable();
            $table->timestamps();

            // 同一用户下，同一域名只能有一条记录
            $table->unique(['user_id', 'domain']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_blacklist');
    }
};
