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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 64)->unique()->comment('订单编号');
            $table->text('product_names')->nullable()->comment('商品名称（多个商品用逗号分隔）');
            $table->string('customer_email', 255)->nullable()->comment('顾客邮箱');
            $table->decimal('total_price', 12, 2)->default(0)->comment('订单总价');
            $table->string('payment_method', 64)->nullable()->comment('支付方式');
            $table->timestamp('paid_at')->nullable()->comment('支付时间');
            $table->string('utm_source', 255)->nullable()->comment('UTM来源');
            $table->string('transaction_no', 128)->nullable()->comment('流水号');
            $table->string('domain', 255)->nullable()->comment('域名来源');
            $table->text('landing_page')->nullable()->comment('落地页URL');
            $table->string('utm_medium', 255)->nullable()->comment('UTM媒介');
            $table->unsignedBigInteger('remote_order_id')->nullable()->comment('远程订单ID');
            $table->timestamps();
            
            // 索引
            $table->index('customer_email');
            $table->index('paid_at');
            $table->index('remote_order_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
