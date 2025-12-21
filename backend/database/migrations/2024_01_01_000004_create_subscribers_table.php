<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->json('custom_fields')->nullable();
            $table->enum('status', ['active', 'unsubscribed', 'bounced', 'complained'])->default('active');
            $table->timestamp('subscribed_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('source')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Pivot table for lists and subscribers
        Schema::create('list_subscriber', function (Blueprint $table) {
            $table->id();
            $table->foreignId('list_id')->constrained()->onDelete('cascade');
            $table->foreignId('subscriber_id')->constrained()->onDelete('cascade');
            $table->enum('status', ['pending', 'active', 'unsubscribed'])->default('pending');
            $table->timestamp('subscribed_at')->nullable();
            $table->timestamps();

            $table->unique(['list_id', 'subscriber_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('list_subscriber');
        Schema::dropIfExists('subscribers');
    }
};

