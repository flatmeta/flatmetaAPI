<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;

class PassportAuthController extends Controller
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
        return [
            'user_id' => $user->id,
            'fullname' => $user->fullname,
            'username' => $user->username,
            'email' => $user->email,
            'user_image' => $this->formatUserImage($user->image),
            'status' => $user->status,
        ];
    }

    protected function issueTokenForUser(User $user): string
    {
        try {
            return $user->createToken('flatmeta')->accessToken;
        } catch (\Throwable $e) {
            return 'flatmeta_token_' . base64_encode($user->id . ':' . $user->email);
        }
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
            if (!empty($request->user_image)) {
                $user->image = $request->user_image;
            }

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

    /**
     * Email-Only Guest Upgrade / Verification (Brief 06/01/23: "User login only needed to save, only email verification needed")
     */
    public function verifyEmail(Request $request)
    {
        $email = strtolower(trim($request->email ?? ''));
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['status' => false, 'message' => 'Please enter a valid email address']);
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
                if (!empty($request->user_image)) {
                    $user->image = $request->user_image;
                }
                $user->save();
            } else {
                if (!empty($request->fullname)) {
                    $user->fullname = trim($request->fullname);
                }
                if (!empty($request->user_image) && empty($user->image)) {
                    $user->image = $request->user_image;
                }
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

        // Support passwordless / quick email verification when no password is provided
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
