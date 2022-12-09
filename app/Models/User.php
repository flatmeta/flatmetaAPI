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
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    public static function GetAllUsers($request)
    {

        $query = DB::table('users'); 
        $query->select('users.*');

        if (!empty($request->keyword)){
            $query->orwhere('first_name','like',"%$request->keyword%");
            $query->orwhere('last_name','like',"%$request->keyword%");
            $query->orwhere('email','like',"%$request->keyword%");
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
