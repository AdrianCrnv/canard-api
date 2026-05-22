<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    use HasFactory;

    protected $table = 'company';
    protected $guarded = [];

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function airports()
    {
        return $this->belongsToMany(Airport::class, 'company_airports', 'company_id', 'airport_id');
    }
}
