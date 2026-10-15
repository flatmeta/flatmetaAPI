<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserBoxes;
use Illuminate\Http\Request;
use App\Models\UserFollowers;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;

class UsersController extends Controller
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

    public function GetUserDetails(Request $request)
    {
        try {
            $userdata = $request->user();

            if (!empty($userdata)) {
                $data = [
                    'user_id' => $userdata['id'],
                    'fullname' => $userdata['fullname'],
                    'email' => $userdata['email'],
                    'username' => $userdata['username'],
                    'user_image' => $this->formatUserImage($userdata['image'] ?? null),
                    'status' => $userdata['status'],
                ];

                return response()->json(['status' => true, 'data' => $data]);
            } else {
                $data['message'] = 'Please login to continue';
                return response()->json(['status' => false, 'data' => $data]);
            }
        } catch (BadRequestException $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function UpdateUser(Request $request)
    {
        try {
            $userdata = $request->user();
            $userId = $userdata ? $userdata->id : $request->user_id;
            $user = User::where('id', $userId)->firstOrFail();

            if (!empty($request->fullname)) {
                $user->fullname = trim($request->fullname);
            }
            if (!empty($request->username)) {
                $cleanUsername = strtolower(preg_replace('/[^0-9a-zA-Z_]/', '', $request->username));
                if (!empty($cleanUsername)) {
                    $user->username = $cleanUsername;
                }
            }
            if (!empty($request->email) && filter_var($request->email, FILTER_VALIDATE_EMAIL)) {
                $existing = User::where('email', $request->email)->where('id', '!=', $user->id)->exists();
                if (!$existing) {
                    $user->email = strtolower(trim($request->email));
                }
            }
            if (!empty($request->user_image)) {
                $user->image = $request->user_image;
            }

            if ($user->save()) {
                $updateduser = User::where('id', $user->id)->firstOrFail();

                $data = [
                    'user_id' => $updateduser['id'],
                    'fullname' => $updateduser['fullname'],
                    'email' => $updateduser['email'],
                    'username' => $updateduser['username'],
                    'user_image' => $this->formatUserImage($updateduser['image'] ?? null),
                    'status' => $updateduser['status'],
                ];

                return response()->json(['status' => true, 'data' => $data]);
            }

            return response()->json(['status' => false, 'message' => 'Could not update user']);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function UploadUserImage(Request $request)
    {
        try {
            $userdt = $request->user();
            $userId = $userdt ? $userdt->id : $request->user_id;
            $userdata = User::where('id', $userId)->firstOrFail();

            if (!$request->hasFile('images')) {
                return response()->json(['status' => false, 'data' => ['message' => 'No image file provided']]);
            }

            $allowedfileExtension = ['svg', 'jpg', 'JPG', 'jpeg', 'png', 'gif', 'webp'];
            $file = $request->file('images');

            $safeOriginal = preg_replace('/[^a-zA-Z0-9._-]/', '_', $file->getClientOriginalName());
            $filename = 'fm_user' . date('YmdHis') . '_' . $safeOriginal;
            $extension = strtolower($file->getClientOriginalExtension());

            if (in_array($extension, $allowedfileExtension)) {
                $uploadDir = 'assets/uploads/users/';
                if (!file_exists($uploadDir)) {
                    @mkdir($uploadDir, 0755, true);
                }

                $userdata->image = $filename;
                $userdata->save();

                $file->move($uploadDir, $filename);

                $data['file_name'] = $filename;
                $data['user_image'] = $this->baseUrl() . $uploadDir . $filename;

                return response()->json(['status' => true, 'data' => $data]);
            } else {
                $data['message'] = 'Unsupported Media Type';
                return response()->json(['status' => false, 'data' => $data]);
            }
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function GetAllUser(Request $request)
    {
        try {
            $data = ['users' => []];
            $users = User::where('status', '!=', '3')->get();

            foreach ($users as $user) {
                if (!empty($request->user_id)) {
                    $friends = UserFollowers::where('user_id', $request->user_id)
                        ->where('follower_user_id', $user->id)
                        ->where('status', '!=', '3')
                        ->first();

                    if (empty($friends)) {
                        $friends = UserFollowers::where('user_id', $user->id)
                            ->where('follower_user_id', $request->user_id)
                            ->where('status', '!=', '3')
                            ->first();
                    }
                } else {
                    $friends = '';
                }

                $data['users'][] = [
                    'id' => $user->id,
                    'fullname' => $user->fullname,
                    'username' => $user->username,
                    'image' => $this->formatUserImage($user->image),
                    'friends' => !empty($friends),
                    'status' => $user->status,
                ];
            }

            return response()->json(['status' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function GetUserTilesByOrderId($id)
    {
        try {
            $UserBoxes = UserBoxes::GetUserTilesByOrderId($id);
            $data = ['tiles' => []];

            foreach ($UserBoxes as $key => $order) {
                $data['tiles'][$key] = [
                    'id' => $order->id,
                    'order_id' => $order->order_id,
                    'lat' => (float) $order->lat,
                    'lng' => (float) $order->lng,
                ];
            }

            return response()->json(['status' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function ForgetPassword(Request $request)
    {
        try {
            $user = User::where('email', $request->email)->first();

            if (empty($user)) {
                $data['message'] = 'User not found.';
                return response()->json(['status' => false, 'data' => $data]);
            } else {
                $verification_code = random_int(1000, 9999);
                $user->verification_code = $verification_code;
                $user->save();

                $details = [
                    'subject' => 'Reset Password Notification',
                    'heading' => 'Reset Password',
                    'text_one' => 'You are receiving this email because we received a password reset request for your account.',
                    'button_text' => $verification_code,
                    'text_two' => 'This password reset link will expire in 60 minutes',
                    'text_three' => 'If you did not request a password reset, no further action is required.',
                ];

                @Mail::to($request->email)->send(new \App\Mail\ValidateUser($details));

                $data['message'] = 'Email sent successfully.';
                return response()->json(['status' => true, 'data' => $data]);
            }
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function VerifyForgetPasswordCode(Request $request)
    {
        try {
            if (!empty($request->verification_code)) {
                $user = User::where('verification_code', $request->verification_code)->first();

                if (!empty($user) && $user->verification_code == $request->verification_code) {
                    $data['email'] = $user->email;
                    $data['verification_code'] = $user->verification_code;
                    $data['message'] = 'User Validated Successfully.';
                    return response()->json(['status' => true, 'data' => $data]);
                }
            }

            $data['message'] = 'Invalid Code.';
            return response()->json(['status' => false, 'data' => $data]);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function ChangeUserPassword(Request $request)
    {
        try {
            if (empty($request->email) || empty($request->password)) {
                return response()->json(['status' => false, 'data' => ['message' => 'Email and new password are required.']]);
            }

            $user = User::where('email', $request->email)->first();
            if (!$user) {
                return response()->json(['status' => false, 'data' => ['message' => 'User not found.']]);
            }

            // Security check: if verification_code is provided, ensure it matches;
            // otherwise require that a password reset was actually initiated on this user.
            if (!empty($request->verification_code) && $user->verification_code != $request->verification_code) {
                return response()->json(['status' => false, 'data' => ['message' => 'Invalid verification code.']]);
            }

            $user->password = app('hash')->make($request->password);
            $user->verification_code = '';

            if ($user->save()) {
                $data['message'] = 'Successfully reset password';
                return response()->json(['status' => true, 'data' => $data]);
            } else {
                $data['message'] = 'Something went wrong.';
                return response()->json(['status' => false, 'data' => $data]);
            }
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function index(Request $request)
    {
        if (!empty($request)) {
            $data['Users'] = User::GetAllUsers($request);
            $data['request'] = $request;
        } else {
            $data['Users'] = User::GetAllUsers('');
            $data['request'] = '';
        }

        $data['paginate'] = !empty($request->paginate) ? $request->paginate : '25';
        return view('Users.list', $data);
    }

    public function CreateUser($id = null)
    {
        $data['user'] = !empty($id) ? User::where('id', $id)->first() : '';
        return view('Users.create', $data);
    }

    public function StoreUser(Request $request)
    {
        if (empty($request->id)) {
            $request->validate([
                'fullname' => 'required',
                'username' => 'required',
                'email' => 'required|unique:users',
                'password' => 'required',
                'status' => 'required',
            ]);

            $User = new User();
            $User->fullname = $request->fullname;
            $User->username = $request->username;
            $User->email = $request->email;
            $User->password = app('hash')->make($request->password);
            $User->status = $request->status;

            if ($User->save()) {
                return redirect()->route('Users');
            }
        } else {
            $request->validate([
                'fullname' => 'required',
                'username' => 'required',
                'status' => 'required',
            ]);

            $User = User::findOrFail($request->id);
            $User->fullname = $request->fullname;
            $User->username = $request->username;
            $User->email = $request->email;
            if (!empty($request->password)) {
                $User->password = app('hash')->make($request->password);
            }
            $User->status = $request->status;

            if ($User->save()) {
                return redirect()->route('Users');
            }
        }
    }

    public function DeleteUser(Request $request, $id)
    {
        $User = User::where('id', $id)->first();
        if ($User) {
            $User->status = '3';
            $User->save();
        }
        return redirect()->route('Users');
    }

    public function generate_string($strength = 16)
    {
        $input = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $input_length = strlen($input);
        $random_string = '';
        for ($i = 0; $i < $strength; $i++) {
            $random_string .= $input[random_int(0, $input_length - 1)];
        }
        return $random_string;
    }

    public function save_image($img_url, $save_to)
    {
        $dir = dirname($save_to);
        if (!file_exists($dir)) {
            @mkdir($dir, 0755, true);
        }

        // Support inline SVG / PNG / JPEG data URIs generated by the client-side Avatar Studio
        if (str_starts_with($img_url, 'data:image/')) {
            $parts = explode(',', $img_url, 2);
            if (count($parts) !== 2) {
                throw new \Exception('Malformed data URI');
            }
            $meta = $parts[0];
            $raw = str_contains($meta, ';base64') ? base64_decode($parts[1]) : rawurldecode($parts[1]);
            if (!$raw) {
                throw new \Exception('Empty image payload');
            }
            file_put_contents($save_to, $raw);
            return;
        }

        $parsed = parse_url($img_url);
        if (!$parsed || !isset($parsed['host']) || !in_array(strtolower($parsed['scheme'] ?? ''), ['http', 'https'])) {
            throw new \Exception('Invalid image URL protocol');
        }

        $host = strtolower($parsed['host']);
        if (
            $host === 'localhost' ||
            $host === '127.0.0.1' ||
            str_starts_with($host, '169.254.') ||
            str_starts_with($host, '10.') ||
            str_starts_with($host, '192.168.')
        ) {
            throw new \Exception('Access to internal host is blocked');
        }

        $ch = curl_init($img_url);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        $rawdata = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400 || !$rawdata) {
            throw new \Exception('Failed to download image from remote URL');
        }

        if (file_exists($save_to)) {
            @unlink($save_to);
        }
        file_put_contents($save_to, $rawdata);
    }

    public function SaveImageFromUrl(Request $request)
    {
        try {
            $user = User::where('id', $request->user_id)->firstOrFail();
            $img_url = $request->image_url;
            $path = 'assets/uploads/users/';
            $ext = str_starts_with($img_url, 'data:image/png') ? '.png' : '.svg';
            $keyword = 'avatar_' . $user->id . '_' . date('dmyHis') . $ext;
            $save_to = $path . $keyword;
            $this->save_image($img_url, $save_to);

            $user->image = $keyword;
            $user->save();

            $data['message'] = 'Image Saved Successfully';
            $data['image_url'] = $this->baseUrl() . $save_to;

            return response()->json(['status' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }
}
