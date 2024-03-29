<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOrdersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->references('id')->on('users');
            $table->string('subscription_id',255)->nullable();
            $table->string('name',255)->nullable();
            $table->string('no_of_tiles',255);
            $table->string('amount',255);
            $table->text('custom_details')->nullable();
            $table->string('sale_price',255)->nullable();
            $table->enum('on_sale', ['0', '1']);
            $table->enum('status', ['0', '1', '2']);
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
        Schema::dropIfExists('orders');
    }
}
