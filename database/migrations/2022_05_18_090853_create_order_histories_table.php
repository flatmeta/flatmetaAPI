<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOrderHistoriesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('order_histories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('old_order_id')->references('id')->on('orders');
            $table->foreignId('user_id')->references('id')->on('users');
            $table->string('name',255)->nullable();
            $table->string('no_of_tiles',255);
            $table->string('amount',255);
            $table->text('custom_details')->nullable();
            $table->string('sale_price',255)->nullable();
            $table->text('log')->nullable();

            $table->dateTime('next_due_date')->nullable();
            $table->dateTime('next_expiry')->nullable();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('order_histories');
    }
}
