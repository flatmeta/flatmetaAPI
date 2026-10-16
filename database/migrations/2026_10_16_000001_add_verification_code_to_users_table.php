<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddVerificationCodeToUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * verification_code was written by the password reset flow but never had a migration,
     * so some databases already have it (added by hand). Only add what is missing.
     *
     * @return void
     */
    public function up()
    {
        // Codes are now stored hashed, so a hand-made numeric column must become a string.
        if (Schema::hasColumn('users', 'verification_code') && DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE users MODIFY verification_code VARCHAR(255) NULL');
        }

        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'verification_code')) {
                $table->string('verification_code', 255)->nullable();
            }
            if (!Schema::hasColumn('users', 'verification_code_expires_at')) {
                $table->timestamp('verification_code_expires_at')->nullable();
            }
            if (!Schema::hasColumn('users', 'verification_attempts')) {
                $table->unsignedTinyInteger('verification_attempts')->default(0);
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['verification_code_expires_at', 'verification_attempts']);
        });
    }
}
