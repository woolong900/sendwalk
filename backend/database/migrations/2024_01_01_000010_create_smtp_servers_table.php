<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smtp_servers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->enum('type', ['smtp', 'ses', 'sendgrid', 'mailgun', 'postmark'])->default('smtp');
            $table->string('host')->nullable();
            $table->integer('port')->nullable();
            $table->string('username')->nullable();
            $table->string('password')->nullable();
            $table->string('encryption')->nullable();
            $table->json('credentials')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            
            // Rate limits (per second, minute, hour, day)
            $table->integer('rate_limit_second')->nullable();
            $table->integer('rate_limit_minute')->nullable();
            $table->integer('rate_limit_hour')->nullable();
            $table->integer('rate_limit_day')->nullable();
            
            $table->integer('emails_sent_today')->default(0);
            $table->date('last_reset_date')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smtp_servers');
    }
};

