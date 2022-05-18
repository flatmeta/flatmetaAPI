<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();


            $table->string('fullname',50);
            $table->string('username',50);
            $table->string('email')->unique();
            $table->string('password');
            $table->string('image')->nullable();
            $table->text('access_token')->nullable();
            $table->text('device_token')->nullable();
            $table->text('verification_key')->nullable();

            $table->enum('status', ['1', '2', '3']);
            
            $table->timestamp('email_verified_at')->nullable();
            
            $table->rememberToken();
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
        Schema::dropIfExists('users');
    }
}
