<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserBoxes;
use Illuminate\Http\Request;
use App\Models\UserFollowers;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;

class UsersController extends Controller
{
    const RASTER_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

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
        return response()->json(['status' => true, 'data' => $request->user()->apiPayload()]);
    }

    /**
     * Profile edits from the app. Email can't be changed here (that would skip
     * verification) and the photo only changes through UploadUserImage.
     */
    public function UpdateUser(Request $request)
    {
        try {
            $user = User::findOrFail($request->user()->id);

            if ($request->filled('fullname')) {
                $user->fullname = mb_substr(trim(strip_tags((string) $request->fullname)), 0, 50);
            }
            if ($request->filled('username')) {
                $clean = substr(strtolower(preg_replace('/[^0-9a-zA-Z_]/', '', (string) $request->username)), 0, 30);
                if ($clean !== '' && $clean !== $user->username) {
                    if (User::where('username', $clean)->where('id', '!=', $user->id)->exists()) {
                        return response()->json(['status' => false, 'message' => "The username @{$clean} is taken."]);
                    }
                    $user->username = $clean;
                }
            }
            if ($request->has('phone')) {
                $user->phone = substr(preg_replace('/[^0-9+()\- ]/', '', (string) $request->phone), 0, 30) ?: null;
            }
            if ($request->filled('clan')) {
                if (!array_key_exists($request->clan, User::CLANS)) {
                    return response()->json(['status' => false, 'message' => 'Unknown clan.']);
                }
                $user->clan = $request->clan;
            }
            if ($request->has('paypal_email')) {
                $payoutEmail = strtolower(trim((string) $request->paypal_email));
                if ($payoutEmail !== '' && !filter_var($payoutEmail, FILTER_VALIDATE_EMAIL)) {
                    return response()->json(['status' => false, 'message' => 'Enter a valid PayPal email for payouts.']);
                }
                $user->paypal_email = $payoutEmail ?: null;
            }
            if ($request->has('avatar_config')) {
                $config = User::cleanAvatarConfig($request->avatar_config);
                $user->avatar_config = $config ? json_encode($config) : null;
            }

            $user->save();
            return response()->json(['status' => true, 'data' => $user->fresh()->apiPayload()]);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function UploadUserImage(Request $request)
    {
        try {
            $userdata = User::where('id', $request->user()->id)->firstOrFail();

            if (!$request->hasFile('images')) {
                return response()->json(['status' => false, 'data' => ['message' => 'No image file provided']]);
            }

            // Raster images only (SVG can carry script and is served from the API origin), max 5 MB.
            $validator = Validator::make($request->all(), ['images' => 'file|max:5120|mimes:jpg,jpeg,png,gif,webp']);
            $file = $request->file('images');
            // Extension comes from the detected content type, never from the client file name.
            $extension = $file->guessExtension();

            if (!$validator->fails() && in_array($extension, self::RASTER_IMAGE_EXTENSIONS, true)) {
                $filename = 'fm_user' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $extension;
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
                $data['message'] = 'Unsupported Media Type (JPG, PNG, GIF or WEBP up to 5 MB)';
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

    /**
     * Checks a password reset code for the given user. Codes are stored hashed, expire
     * after 60 minutes and are invalidated after 5 wrong attempts.
     */
    protected function checkPasswordResetCode(?User $user, $code): bool
    {
        $code = trim((string) $code);
        if (!$user || $code === '' || empty($user->verification_code) || empty($user->verification_code_expires_at)) {
            return false;
        }

        if (now()->greaterThan($user->verification_code_expires_at)) {
            $this->clearPasswordResetCode($user);
            return false;
        }

        if (Hash::check($code, $user->verification_code)) {
            return true;
        }

        $user->verification_attempts = (int) $user->verification_attempts + 1;
        if ($user->verification_attempts >= 5) {
            $this->clearPasswordResetCode($user);
        } else {
            $user->save();
        }
        return false;
    }

    protected function clearPasswordResetCode(User $user): void
    {
        $user->verification_code = null;
        $user->verification_code_expires_at = null;
        $user->verification_attempts = 0;
        $user->save();
    }

    public function ForgetPassword(Request $request)
    {
        try {
            $email = strtolower(trim((string) $request->email));
            $user = User::where('email', $email)->first();

            if (empty($user)) {
                $data['message'] = 'User not found.';
                return response()->json(['status' => false, 'data' => $data]);
            } else {
                $limiterKey = 'password-reset-send:' . sha1($email);
                if (RateLimiter::tooManyAttempts($limiterKey, 5)) {
                    $data['message'] = 'Too many reset requests. Try again in ' . RateLimiter::availableIn($limiterKey) . ' seconds.';
                    return response()->json(['status' => false, 'data' => $data]);
                }
                RateLimiter::hit($limiterKey, 900);

                $verification_code = (string) random_int(1000, 9999);
                $user->verification_code = Hash::make($verification_code);
                $user->verification_code_expires_at = now()->addMinutes(60);
                $user->verification_attempts = 0;
                $user->save();

                $details = [
                    'subject' => 'Reset Password Notification',
                    'heading' => 'Reset Password',
                    'text_one' => 'You are receiving this email because we received a password reset request for your account.',
                    'button_text' => $verification_code,
                    'text_two' => 'This password reset code will expire in 60 minutes',
                    'text_three' => 'If you did not request a password reset, no further action is required.',
                ];

                Mail::to($user->email)->send(new \App\Mail\ValidateUser($details));

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
            $email = strtolower(trim((string) $request->email));
            $user = $email !== '' ? User::where('email', $email)->first() : null;

            if ($this->checkPasswordResetCode($user, $request->verification_code)) {
                $data['email'] = $user->email;
                $data['verification_code'] = trim((string) $request->verification_code);
                $data['message'] = 'User Validated Successfully.';
                return response()->json(['status' => true, 'data' => $data]);
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
            if (empty($request->email) || empty($request->password) || empty($request->verification_code)) {
                return response()->json(['status' => false, 'data' => ['message' => 'Email, verification code and new password are required.']]);
            }
            if (strlen((string) $request->password) < 6) {
                return response()->json(['status' => false, 'data' => ['message' => 'Password should be at least 6 characters']]);
            }

            $user = User::where('email', strtolower(trim((string) $request->email)))->first();
            if (!$this->checkPasswordResetCode($user, $request->verification_code)) {
                return response()->json(['status' => false, 'data' => ['message' => 'Invalid or expired verification code.']]);
            }

            $user->password = app('hash')->make($request->password);
            $user->verification_code = null;
            $user->verification_code_expires_at = null;
            $user->verification_attempts = 0;

            if ($user->save()) {
                // A new password signs the account out everywhere.
                $user->tokens()->delete();
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

    /**
     * Returns the raster image extension for the given bytes, or null when the content is not
     * an allowed image (SVG and anything else is rejected).
     */
    protected function detectRasterImageExtension(string $raw): ?string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($raw);
        $map = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        return $map[$mime] ?? null;
    }

    protected function assertPublicHost(string $host): void
    {
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (empty($ips)) {
            throw new \Exception('Could not resolve image host');
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \Exception('Access to internal host is blocked');
            }
        }
    }

    /**
     * Downloads (or decodes a data URI) into memory, checks it is a raster image no larger than
     * MAX_IMAGE_BYTES and writes it to "$save_to_without_extension.<ext>". Returns the file name.
     */
    public function save_image($img_url, $save_to)
    {
        $dir = dirname($save_to);
        if (!file_exists($dir)) {
            @mkdir($dir, 0755, true);
        }

        // Support PNG / JPEG / GIF / WEBP data URIs generated by the client-side Avatar Studio
        if (str_starts_with($img_url, 'data:')) {
            $parts = explode(',', $img_url, 2);
            if (count($parts) !== 2) {
                throw new \Exception('Malformed data URI');
            }
            $meta = $parts[0];
            $raw = str_contains($meta, ';base64') ? base64_decode($parts[1], true) : rawurldecode($parts[1]);
            if (!$raw) {
                throw new \Exception('Empty image payload');
            }
        } else {
            $parsed = parse_url($img_url);
            if (!$parsed || !isset($parsed['host']) || !in_array(strtolower($parsed['scheme'] ?? ''), ['http', 'https'])) {
                throw new \Exception('Invalid image URL protocol');
            }
            $this->assertPublicHost(strtolower($parsed['host']));

            $ch = curl_init($img_url);
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_MAXFILESIZE, self::MAX_IMAGE_BYTES);
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function ($ch, $dlTotal, $dlNow) {
                return $dlNow > self::MAX_IMAGE_BYTES ? 1 : 0;
            });
            $raw = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 400 || !$raw) {
                throw new \Exception('Failed to download image from remote URL');
            }
        }

        if (strlen($raw) > self::MAX_IMAGE_BYTES) {
            throw new \Exception('Image is larger than 5 MB');
        }
        $extension = $this->detectRasterImageExtension($raw);
        if ($extension === null) {
            throw new \Exception('Unsupported image type (JPG, PNG, GIF or WEBP only)');
        }

        $target = $save_to . '.' . $extension;
        if (file_exists($target)) {
            @unlink($target);
        }
        file_put_contents($target, $raw);
        return basename($target);
    }

    public function SaveImageFromUrl(Request $request)
    {
        try {
            $user = User::where('id', $request->user()->id)->firstOrFail();
            $img_url = (string) $request->image_url;
            $path = 'assets/uploads/users/';
            $keyword = $this->save_image($img_url, $path . 'avatar_' . $user->id . '_' . date('dmyHis'));

            $user->image = $keyword;
            $user->save();

            $data['message'] = 'Image Saved Successfully';
            $data['image_url'] = $this->baseUrl() . $path . $keyword;

            return response()->json(['status' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }
}
