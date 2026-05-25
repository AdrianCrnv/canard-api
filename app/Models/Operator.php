<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Operator extends Model implements HasMedia {

    use HasFactory;
    use InteractsWithMedia;

    protected $guarded = []; // Allow all fields to be saved as Mass Assignment

    public function users(){
        return $this->hasMany(User::class);
    }

    public function contact(){
        return $this->belongsTo(Contact::class);
    }

    public function country(){
        return $this->belongsTo(Country::class);
    }

    public function drones(){
        return $this->hasMany(Drone::class);
    }

    public function operations(){
        return $this->hasMany(Operation::class);
    }

    public function pilots(){
        return User::role('pilot')->where('operator_id', $this->id)->where('is_active', 1)->get();
    }

    public function allTechnicians(){
        return User::role('technician')->where('operator_id', $this->id)->get();
    }

    public function activeTechnicians(){
        return User::role('technician')->where('operator_id', $this->id)->where('is_active', 1)->get();
    }

    public function maintainers(){
        return User::role('maintenance')->where('operator_id', $this->id)->get();
    }

    public function company() {
        return User::role('company')->whereNull('operator_id')->where('operator_id', $this->id)->get();
    }

    // Airports for Operator Users
    public function airports(){
        return $this->belongsToMany(Airport::class, 'operator_airport', 'operator_id', 'subject_id')
                    ->wherePivot('subject_type_id', 4);
    }

    public function clients(){
        return $this->belongsToMany(Company::class, 'operator_company', 'operator_id', 'company_id');
    }

    // Vors for Operator Users
    public function vors(){
        return $this->belongsToMany(Vor::class, 'operator_airport', 'operator_id', 'subject_id')
                    ->wherePivot('subject_type_id', 5);
    }
}
