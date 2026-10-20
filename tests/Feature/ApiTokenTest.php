<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** API sign-in uses Sanctum personal access tokens, sent as "Authorization: Bearer <token>". */
class ApiTokenTest extends TestCase
{
    use RefreshDatabase;

    protected function me(string $token)
    {
        $this->app['auth']->forgetGuards();
        return $this->getJson('/api/getuserbytoken', ['Authorization' => 'Bearer ' . $token]);
    }

    public function test_signing_in_gives_a_token_that_works_until_it_is_deleted(): void
    {
        $user = User::factory()->create(['email' => 'me@example.com']);

        $token = $this->postJson('/api/login', ['email' => 'me@example.com', 'password' => 'password'])
            ->assertJson(['status' => true])
            ->json('access_token');

        $this->me($token)->assertOk()->assertJson(['status' => true]);

        $user->tokens()->delete();
        $this->me($token)->assertStatus(401);
    }

    public function test_tokens_expire_after_a_year(): void
    {
        $token = User::factory()->create()->createToken('flatmeta')->plainTextToken;

        $this->travel(364)->days();
        $this->me($token)->assertOk();

        $this->travel(2)->days();
        $this->me($token)->assertStatus(401);
    }

    public function test_old_passport_tokens_and_made_up_tokens_are_rejected(): void
    {
        $user = User::factory()->create();

        // Passport issued JWTs; the old fallback issued base64 of "id:email". Neither may sign anyone in.
        $this->me('eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJzdWIiOiIxIn0.c2lnbmF0dXJl')->assertStatus(401);
        $this->me('flatmeta_token_' . base64_encode($user->id . ':' . $user->email))->assertStatus(401);
        $this->me('1|not-a-real-token')->assertStatus(401);
    }

    public function test_resetting_the_password_signs_out_everywhere(): void
    {
        $user = User::factory()->create([
            'email' => 'reset@example.com',
            'verification_code' => Hash::make('123456'),
            'verification_code_expires_at' => now()->addMinutes(10),
        ]);
        $token = $user->createToken('flatmeta')->plainTextToken;

        $this->postJson('/api/ChangeUserPassword', ['email' => 'reset@example.com', 'verification_code' => '123456', 'password' => 'new-password'])
            ->assertJson(['status' => true]);

        $this->me($token)->assertStatus(401);
        $this->assertSame(0, $user->tokens()->count());
    }
}
