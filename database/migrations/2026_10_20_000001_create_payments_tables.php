<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money tracking for PayPal:
 * - payments: every subscription checkout, marketplace purchase, seller payout and refund.
 * - paypal_events: webhook events already handled (PayPal can deliver the same one twice).
 * - users.paypal_email: where a seller's share is paid out.
 * - orders.subscription_status: last known PayPal state of the land's monthly subscription.
 */
class CreatePaymentsTables extends Migration
{
    public function up()
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);                       // subscription | sale | payout | refund
            $table->string('status', 20)->default('pending'); // pending | completed | failed | cancelled | refunded | unclaimed
            $table->unsignedBigInteger('user_id')->nullable(); // who pays (subscription/sale) or who is paid (payout)
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('related_payment_id')->nullable(); // payout/refund -> the sale it belongs to
            $table->string('provider_ref', 64)->nullable();   // PayPal subscription id / order id / payout batch id
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->text('meta')->nullable();
            $table->timestamps();

            $table->index(['kind', 'status']);
            $table->index('order_id');
            $table->index('user_id');
            $table->unique(['kind', 'provider_ref']);
        });

        Schema::create('paypal_events', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('type', 80);
            $table->string('resource_id', 64)->nullable();
            $table->string('result', 120)->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('paypal_email')->nullable()->after('email');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('subscription_status', 20)->nullable()->after('subscription_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('paypal_events');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('paypal_email');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('subscription_status');
        });
    }
}
