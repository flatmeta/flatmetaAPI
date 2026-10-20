<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;

class AuthController extends Controller
{
    protected function baseUrl(): string
    {
        $base = config('app.url') ?: env('APP_URL', url('/'));
        return rtrim($base, '/') . '/';
    }

    protected function formatUserImage(?string $image): string
    {
        $userfilePath = $this->baseUrl() . 'assets/uploads/users/';
        if (empty($image)) {
            return $userfilePath . 'noimage.png';
        }
        if (str_starts_with($image, 'data:') || str_starts_with($image, 'http://') || str_starts_with($image, 'https://')) {
            return $image;
        }
        return $userfilePath . ltrim($image, '/');
    }

    protected function buildUserPayload(User $user): array
    {
        return $user->apiPayload();
    }

    /** A Sanctum personal access token; the app sends it as "Authorization: Bearer <token>". */
    protected function issueTokenForUser(User $user): string
    {
        return $user->createToken('flatmeta')->plainTextToken;
    }

    public function register(Request $request)
    {
        $email = strtolower(trim($request->email ?? ''));
        $username = strtolower(preg_replace('/[^0-9a-zA-Z_]/', '', $request->username ?? ''));
        $fullname = trim($request->fullname ?? '');
        $password = $request->password;
        $status = '1';

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['status' => false, 'message' => 'Please enter a valid email address']);
        }

        if (empty($username)) {
            $username = strtolower(preg_replace('/[^0-9a-zA-Z_]/', '_', explode('@', $email)[0] ?? 'citizen'));
        }
        if (empty($fullname)) {
            $fullname = ucwords(str_replace('_', ' ', $username));
        }
        if (empty($password)) {
            $password = $this->getToken(12);
        } elseif (strlen($password) < 6) {
            return response()->json(['status' => false, 'message' => 'Password should be at least 6 characters']);
        }

        if (User::where('email', $email)->exists()) {
            return response()->json(['status' => false, 'message' => 'Email already registered.']);
        }

        if (User::where('username', $username)->exists()) {
            $username .= '_' . random_int(100, 999);
        }

        try {
            $verification_key = $this->getToken(16);

            $user = new User();
            $user->fullname = $fullname;
            $user->email = $email;
            $user->username = $username;
            $user->password = Hash::make($password);
            $user->status = $status;
            $user->verification_key = $verification_key;

            if ($user->save()) {
                $token = $this->issueTokenForUser($user);
                return response()->json([
                    'status' => true,
                    'message' => 'Registered successfully!',
                    'access_token' => $token,
                    'data' => $this->buildUserPayload($user),
                ]);
            }

            return response()->json(['status' => false, 'message' => 'Unable to save user']);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    protected function emailCodeCacheKey(string $email): string
    {
        return 'email_login_code:' . sha1($email);
    }

    /**
     * Revokes every access token issued for the user (e.g. tokens minted for an
     * account registered by someone who did not own the email address).
     */
    protected function revokeAllTokens(User $user): void
    {
        $user->tokens()->delete();
    }

    protected function sendEmailLoginCode(string $email)
    {
        $limiterKey = 'email-code-send:' . sha1($email);
        if (RateLimiter::tooManyAttempts($limiterKey, 5)) {
            $seconds = RateLimiter::availableIn($limiterKey);
            return response()->json(['status' => false, 'message' => "Too many codes requested. Try again in {$seconds} seconds."]);
        }
        RateLimiter::hit($limiterKey, 900);

        $code = (string) random_int(100000, 999999);
        Cache::put($this->emailCodeCacheKey($email), [
            'hash' => Hash::make($code),
            'attempts' => 0,
        ], now()->addMinutes(10));

        try {
            Mail::to($email)->send(new \App\Mail\ValidateUser([
                'subject' => 'Your FlatMeta verification code',
                'heading' => 'Verify your email',
                'text_one' => 'Use this code to sign in to FlatMeta and save your progress.',
                'button_text' => $code,
                'text_two' => 'This code will expire in 10 minutes.',
                'text_three' => 'If you did not request this code, you can ignore this email.',
            ]));
        } catch (\Throwable $e) {
            Cache::forget($this->emailCodeCacheKey($email));
            return response()->json(['status' => false, 'message' => 'Could not send the verification email. Please try again later.']);
        }

        $message = 'Verification code sent to ' . $email;
        return response()->json([
            'status' => true,
            'code_sent' => true,
            'message' => $message,
            'data' => ['message' => $message, 'email' => $email, 'code_sent' => true],
        ]);
    }

    /**
     * Checks a code previously mailed by sendEmailLoginCode(). Codes are single use,
     * expire after 10 minutes and are invalidated after 5 wrong attempts.
     */
    protected function checkEmailLoginCode(string $email, string $code): bool
    {
        $key = $this->emailCodeCacheKey($email);
        $entry = Cache::get($key);
        if (!is_array($entry) || empty($entry['hash'])) {
            return false;
        }

        if (Hash::check($code, $entry['hash'])) {
            Cache::forget($key);
            return true;
        }

        $entry['attempts'] = ($entry['attempts'] ?? 0) + 1;
        if ($entry['attempts'] >= 5) {
            Cache::forget($key);
        } else {
            Cache::put($key, $entry, now()->addMinutes(10));
        }
        return false;
    }

    /**
     * Email-Only Guest Upgrade / Verification (Brief 06/01/23: "User login only needed to save, only email verification needed")
     *
     * Step 1: POST { email } -> mails a 6 digit code (no token is issued).
     * Step 2: POST { email, code } -> verifies the code, creates the account if needed and issues a token.
     */
    public function verifyEmail(Request $request)
    {
        $email = strtolower(trim($request->email ?? ''));
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['status' => false, 'message' => 'Please enter a valid email address']);
        }

        $code = trim((string) ($request->code ?? $request->verification_code ?? ''));
        if ($code === '') {
            return $this->sendEmailLoginCode($email);
        }

        if (!$this->checkEmailLoginCode($email, $code)) {
            return response()->json(['status' => false, 'message' => 'Invalid or expired verification code']);
        }

        try {
            $user = User::where('email', $email)->first();

            if (!$user) {
                $baseHandle = strtolower(preg_replace('/[^0-9a-zA-Z_]/', '_', $request->username ?? explode('@', $email)[0] ?? 'citizen'));
                $username = $baseHandle ?: 'citizen';
                if (User::where('username', $username)->exists()) {
                    $username .= '_' . random_int(100, 999);
                }
                $fullname = trim($request->fullname ?? '') ?: ucwords(str_replace('_', ' ', $username));

                $user = new User();
                $user->fullname = $fullname;
                $user->email = $email;
                $user->username = $username;
                $user->password = Hash::make($request->password ?: $this->getToken(12));
                $user->status = '1';
                $user->verification_key = $this->getToken(16);
                $user->save();
            } else {
                if (!empty($request->fullname)) {
                    $user->fullname = trim($request->fullname);
                }
                $user->save();
            }

            if (empty($user->email_verified_at)) {
                // First proof of email ownership: drop tokens issued before it (e.g. a squatted registration).
                $this->revokeAllTokens($user);
                $user->email_verified_at = now();
                $user->save();
            }

            $token = $this->issueTokenForUser($user);
            return response()->json([
                'status' => true,
                'message' => 'Email verified and progress saved!',
                'access_token' => $token,
                'data' => $this->buildUserPayload($user),
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function login(Request $request)
    {
        $login = trim($request->email ?? $request->username ?? '');
        $password = $request->password;

        if (empty($login)) {
            return response()->json(['status' => false, 'message' => 'Please enter your email or username']);
        }

        // Passwordless sign-in goes through the emailed code flow (a token is only issued once the code is verified)
        if (empty($password) && filter_var($login, FILTER_VALIDATE_EMAIL)) {
            return $this->verifyEmail($request);
        }

        if (empty($password)) {
            return response()->json(['status' => false, 'message' => 'Please enter both your email/username and password']);
        }

        try {
            $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
            $credentials = [
                $field => $login,
                'password' => $password,
            ];

            if (auth()->attempt($credentials)) {
                $user = auth()->user();
                $token = $this->issueTokenForUser($user);
                return response()->json([
                    'status' => true,
                    'access_token' => $token,
                    'data' => $this->buildUserPayload($user),
                ]);
            } else {
                $returndata['message'] = 'Wrong Email or Password';
                return response()->json(['status' => false, 'data' => $returndata]);
            }
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function getToken($length)
    {
        $token = '';
        $codeAlphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $max = strlen($codeAlphabet);

        for ($i = 0; $i < $length; $i++) {
            $token .= $codeAlphabet[random_int(0, $max - 1)];
        }

        return $token;
    }

    public function GetUserDetails(Request $request)
    {
        try {
            $userdata = $request->user();

            if (!empty($userdata)) {
                return response()->json([
                    'status' => true,
                    'data' => $this->buildUserPayload($userdata),
                ]);
            } else {
                $data['message'] = 'Please login to continue';
                return response()->json(['status' => false, 'data' => $data]);
            }
        } catch (BadRequestException $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }
}
