<?php

namespace Tests\Feature\Billing;

use App\Models\Orders;
use App\Models\User;
use App\Models\UserBoxes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\FakesPayPal;
use Tests\TestCase;

abstract class BillingTestCase extends TestCase
{
    use RefreshDatabase;
    use FakesPayPal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePayPal();
    }

    protected function user(array $attrs = []): User
    {
        return User::factory()->create($attrs);
    }

    protected function actingAsUser(User $user): self
    {
        Sanctum::actingAs($user);
        return $this;
    }

    /** A row of $count squares (NW corners on the zoom-15 grid) starting at a given column. */
    protected function squares(int $count, int $startCol = 0, int $row = 0): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = $this->corner(143890 + $startCol + $i, 89902 + $row);
        }
        return $out;
    }

    protected function corner(int $gx, int $gy): array
    {
        $world = 256 * 2 ** 15;
        $lng = ($gx * 40) / $world * 360 - 180;
        $n = M_PI - 2 * M_PI * ($gy * 40) / $world;
        $lat = 180 / M_PI * atan(0.5 * (exp($n) - exp(-$n)));
        return ['lat' => round($lat, 6), 'lng' => round($lng, 6)];
    }

    /** Gives a user an active land order directly. */
    protected function landFor(User $user, int $tiles, int $startCol = 1000, array $attrs = []): Orders
    {
        $order = new Orders();
        $order->user_id = $user->id;
        $order->name = 'Land';
        $order->no_of_tiles = $tiles;
        $order->amount = 0;
        $order->on_sale = '0';
        $order->status = '1';
        foreach ($attrs as $k => $v) {
            $order->{$k} = $v;
        }
        $order->save();
        foreach ($this->squares($tiles, $startCol) as $sq) {
            $box = new UserBoxes();
            $box->order_id = $order->id;
            $box->lat = $sq['lat'];
            $box->lng = $sq['lng'];
            $box->price = '0';
            $box->save();
        }
        return $order;
    }
}
