<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use HasFactory;

    protected $table = 'company';

    protected $fillable = [
        'name',
        'is_active',
    ];

    public function users()
    {
        return $this->belongsToMany(User::class, 'company_user', 'company_id', 'user_id');
    }
}
