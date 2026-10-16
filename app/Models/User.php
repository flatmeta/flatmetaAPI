<?php

namespace App\Models;

use Illuminate\Support\Facades\DB;
use Laravel\Passport\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;


class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'verification_code',
        'verification_code_expires_at',
        'verification_attempts',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'verification_code_expires_at' => 'datetime',
        'is_admin' => 'boolean',
    ];

    public const DEFAULT_CLAN = 'Cyber Architects';
    public const CLANS = [
        'Cyber Architects' => ['badge' => '⚡', 'color' => '#06b6d4'],
        'Emerald Guardians' => ['badge' => '🌿', 'color' => '#10b981'],
        'Solar Pioneers' => ['badge' => '☀️', 'color' => '#f59e0b'],
        'Royal Knights' => ['badge' => '🛡️', 'color' => '#8b5cf6'],
    ];

    /** Avatar builder keys the app understands; anything else is dropped. */
    protected const AVATAR_KEYS = [
        'topType', 'accessoriesType', 'hairColor', 'facialHairType', 'clotheType',
        'eyeType', 'eyebrowType', 'mouthType', 'skinColor', 'circleBg', 'customImage',
    ];

    public static function baseUrl(): string
    {
        return rtrim(config('app.url') ?: url('/'), '/') . '/';
    }

    public static function imageUrl(?string $image): string
    {
        $base = self::baseUrl() . 'assets/uploads/users/';
        if (empty($image)) {
            return $base . 'noimage.png';
        }
        if (preg_match('#^https?://#i', $image)) {
            return $image;
        }
        return $base . ltrim($image, '/');
    }

    /**
     * Validates an avatar config sent by the app. Values are short identifiers,
     * the circle colour is a hex colour, and a photo must be one we host.
     */
    public static function cleanAvatarConfig($raw): ?array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return null;
        }
        $clean = [];
        foreach (self::AVATAR_KEYS as $key) {
            if (!isset($raw[$key]) || !is_string($raw[$key])) {
                continue;
            }
            $value = $raw[$key];
            if ($key === 'circleBg') {
                if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $value)) {
                    $clean[$key] = $value;
                }
            } elseif ($key === 'customImage') {
                if (str_starts_with($value, self::baseUrl() . 'assets/uploads/users/')) {
                    $clean[$key] = $value;
                }
            } elseif (preg_match('/^[A-Za-z0-9]{1,40}$/', $value)) {
                $clean[$key] = $value;
            }
        }
        return $clean ?: null;
    }

    /** The user object every auth/profile endpoint returns to the app. */
    public function apiPayload(): array
    {
        return [
            'user_id' => $this->id,
            'fullname' => $this->fullname,
            'username' => $this->username,
            'email' => $this->email,
            'phone' => $this->phone ?? '',
            'clan' => $this->clan ?: self::DEFAULT_CLAN,
            'user_image' => self::imageUrl($this->image),
            'avatar_config' => self::cleanAvatarConfig($this->avatar_config),
            'status' => $this->status,
        ];
    }

    public static function GetAllUsers($request)
    {

        $query = DB::table('users'); 
        $query->select('users.*');

        if (!empty($request->keyword)){
            $keyword = $request->keyword;
            $query->where(function($q) use ($keyword) {
                $q->where('fullname','like',"%$keyword%")
                  ->orWhere('username','like',"%$keyword%")
                  ->orWhere('email','like',"%$keyword%");
            });
        }

        if (!empty($request->status)){
            $query->where('users.status',$request->status);
        }else{
            $query->where('users.status','!=','3');
        }

        if(!empty($request->sorting) && !empty($request->type)){
            $query->orderBy($request->sorting, $request->type);
        }else{
            $query->orderBy('id', 'desc'); 
        }

        if(!empty($request->paginate)){
            $result= $query->paginate($request->paginate);
        }else{
            $result= $query->paginate(25);
        }

        return $result;

    }
    
}
