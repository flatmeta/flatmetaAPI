<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One PayPal money movement: a subscription checkout, a marketplace sale, a seller payout or a refund. */
class Payment extends Model
{
    public const KIND_SUBSCRIPTION = 'subscription';
    public const KIND_SALE = 'sale';
    public const KIND_PAYOUT = 'payout';
    public const KIND_REFUND = 'refund';

    protected $fillable = ['kind', 'status', 'user_id', 'order_id', 'related_payment_id', 'provider_ref', 'amount', 'currency', 'meta'];

    protected $casts = ['meta' => 'array', 'amount' => 'float'];

    public function order()
    {
        return $this->belongsTo(Orders::class, 'order_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function mergeMeta(array $values): void
    {
        $this->meta = array_merge($this->meta ?? [], $values);
    }
}
