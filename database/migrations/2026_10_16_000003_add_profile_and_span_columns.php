<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - users.phone / clan / avatar_config: profile data that used to live in
 *   storage/app/user_meta.json (a file rewritten on every request).
 * - user_boxes.span_bounds / previous_image: one image can stretch across any
 *   selection of squares (not only a whole order), and each square can be
 *   reverted to its previous artwork.
 * - user_boxes.image -> text: image URLs can be longer than 255 characters.
 */
class AddProfileAndSpanColumns extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 30)->nullable()->after('email');
            $table->string('clan', 50)->nullable()->after('phone');
            $table->text('avatar_config')->nullable()->after('image');
        });

        Schema::table('user_boxes', function (Blueprint $table) {
            $table->text('previous_image')->nullable()->after('image');
            $table->text('span_bounds')->nullable()->after('previous_image');
        });
        DB::statement('ALTER TABLE user_boxes MODIFY image TEXT NULL');

        $path = storage_path('app/user_meta.json');
        if (file_exists($path)) {
            $map = json_decode((string) file_get_contents($path), true);
            if (is_array($map)) {
                foreach ($map as $userId => $meta) {
                    if (!is_array($meta)) {
                        continue;
                    }
                    DB::table('users')->where('id', (int) $userId)->update([
                        'phone' => isset($meta['phone']) ? substr((string) $meta['phone'], 0, 30) : null,
                        'clan' => isset($meta['clan']) ? substr((string) $meta['clan'], 0, 50) : null,
                    ]);
                }
            }
        }
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'clan', 'avatar_config']);
        });
        Schema::table('user_boxes', function (Blueprint $table) {
            $table->dropColumn(['previous_image', 'span_bounds']);
        });
        DB::statement('ALTER TABLE user_boxes MODIFY image VARCHAR(255) NULL');
    }
}
