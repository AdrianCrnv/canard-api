<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Traits\HasRoles;


class User extends Authenticatable
{
    use HasFactory, HasApiTokens, Notifiable, HasRoles;

    protected $fillable = [
        'name', 'email', 'password', 'operator_id', 'is_active'
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function checkPermissions($permission)
    {
        return $this->can($permission);
    }

    public function operator()
    {
        return $this->belongsTo(Operator::class);
    }
}
