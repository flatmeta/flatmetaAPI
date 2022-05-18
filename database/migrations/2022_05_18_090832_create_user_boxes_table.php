<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserBoxesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('user_boxes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')->references('id')->on('orders');
            $table->string('lat',255);
            $table->string('lng',255);
            $table->string('image',255)->nullable();;
            $table->string('price',255);
            
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
        Schema::dropIfExists('user_boxes');
    }
}
