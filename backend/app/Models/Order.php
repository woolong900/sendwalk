<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'order_number',
        'product_names',
        'customer_email',
        'total_price',
        'payment_method',
        'paid_at',
        'utm_source',
        'transaction_no',
        'domain',
        'landing_page',
        'utm_medium',
        'remote_order_id',
    ];

    protected $casts = [
        'total_price' => 'decimal:2',
        'paid_at' => 'datetime',
        'remote_order_id' => 'integer',
    ];
}
