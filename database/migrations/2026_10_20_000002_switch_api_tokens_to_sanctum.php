<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API sign-in moved from Passport to Sanctum. Sanctum keeps its tokens in
 * personal_access_tokens (it needs the expires_at column added in Sanctum 3);
 * Passport's tables only held tokens that no longer work, so they are dropped.
 * Everyone signs in again once.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('personal_access_tokens', 'expires_at')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->timestamp('expires_at')->nullable()->index()->after('last_used_at');
            });
        }

        foreach (['oauth_auth_codes', 'oauth_access_tokens', 'oauth_refresh_tokens', 'oauth_clients', 'oauth_personal_access_clients', 'oauth_device_codes'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function down(): void
    {
        // The Passport tables are not recreated: their tokens could not be used again anyway.
        if (Schema::hasColumn('personal_access_tokens', 'expires_at')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->dropIndex(['expires_at']);
                $table->dropColumn('expires_at');
            });
        }
    }
};
