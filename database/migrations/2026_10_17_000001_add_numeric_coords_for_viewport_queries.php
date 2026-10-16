<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The map only loads the land in view, so squares and areas must be
 * searchable by position:
 * - user_boxes.lat_num / lng_num: numeric copies of the string lat/lng
 *   (kept in sync by the UserBoxes model), indexed for bbox queries.
 * - orders.center_lat / center_lng: each area's centre, for the one-row-per-area
 *   summary drawn at the zoomed-out levels.
 */
class AddNumericCoordsForViewportQueries extends Migration
{
    public function up()
    {
        Schema::table('user_boxes', function (Blueprint $table) {
            $table->decimal('lat_num', 10, 7)->nullable()->after('lng');
            $table->decimal('lng_num', 10, 7)->nullable()->after('lat_num');
            $table->index(['lat_num', 'lng_num'], 'user_boxes_lat_lng_num_index');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('center_lat', 10, 7)->nullable();
            $table->decimal('center_lng', 10, 7)->nullable();
            $table->index(['status', 'center_lat', 'center_lng'], 'orders_status_center_index');
        });

        DB::statement("UPDATE user_boxes SET lat_num = CAST(lat AS DECIMAL(10,7)), lng_num = CAST(lng AS DECIMAL(10,7)) WHERE lat REGEXP '^-?[0-9.]+$' AND lng REGEXP '^-?[0-9.]+$'");
        DB::statement('UPDATE orders o JOIN (SELECT order_id, AVG(lat_num) AS clat, AVG(lng_num) AS clng FROM user_boxes GROUP BY order_id) b ON b.order_id = o.id SET o.center_lat = b.clat, o.center_lng = b.clng');
    }

    public function down()
    {
        Schema::table('user_boxes', function (Blueprint $table) {
            $table->dropIndex('user_boxes_lat_lng_num_index');
            $table->dropColumn(['lat_num', 'lng_num']);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_status_center_index');
            $table->dropColumn(['center_lat', 'center_lng']);
        });
    }
}
