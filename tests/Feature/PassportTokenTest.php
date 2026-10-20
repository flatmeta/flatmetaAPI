<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PassportTokenTest extends TestCase
{
    use RefreshDatabase;

    /** Live databases keep the personal access client that Passport 10 created; tokens must still work with it. */
    public function test_tokens_from_the_existing_personal_access_client_authenticate(): void
    {
        $clientId = DB::table('oauth_clients')->insertGetId([
            'name' => 'Flatmeta Personal Access Client',
            'secret' => Str::random(40),
            'provider' => null,
            'redirect' => 'http://localhost',
            'personal_access_client' => true,
            'password_client' => false,
            'revoked' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('oauth_personal_access_clients')->insert(['client_id' => $clientId, 'created_at' => now(), 'updated_at' => now()]);

        $user = User::factory()->create();
        $token = $user->createToken('flatmeta')->accessToken;

        $this->getJson('/api/getuserbytoken', ['Authorization' => 'Bearer ' . $token])
            ->assertOk()
            ->assertJson(['status' => true]);

        $user->tokens()->first()->revoke();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/getuserbytoken', ['Authorization' => 'Bearer ' . $token])->assertStatus(401);
    }
}
