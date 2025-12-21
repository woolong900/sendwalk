<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('list_id')->nullable()->constrained()->onDelete('set null');
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('workflow_data');
            $table->enum('trigger_type', ['subscribe', 'unsubscribe', 'click', 'open', 'date', 'custom'])->default('subscribe');
            $table->json('trigger_config')->nullable();
            $table->boolean('is_active')->default(false);
            $table->integer('total_entered')->default(0);
            $table->integer('total_completed')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('automation_subscribers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_id')->constrained()->onDelete('cascade');
            $table->foreignId('subscriber_id')->constrained()->onDelete('cascade');
            $table->enum('status', ['active', 'completed', 'stopped'])->default('active');
            $table->string('current_step')->nullable();
            $table->timestamp('entered_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['automation_id', 'subscriber_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_subscribers');
        Schema::dropIfExists('automations');
    }
};

