<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Airport extends Model {

    use HasFactory;

    protected $guarded = []; // Allow all fields to be saved as Mass Assignment

    public function country(){
        return $this->belongsTo(Country::class);
    }

    public function manager(){
        return $this->belongsTo(AirportManager::class);
    }

    public function ambit(){
        return $this->belongsTo(AirportAmbit::class);
    }

    public function runways(){
        return $this->hasMany(Runway::class);
    }

    public function taxiways(){
        return $this->hasMany(Taxiway::class);
    }

    public function aprons(){
        return $this->hasMany(Apron::class);
    }

    public function stands()
    {
        return $this->hasMany(Stand::class);
    }

    public function etods(){
        return $this->hasMany(Etod::class);
    }

    public function surveillances(){
        return $this->hasMany(Surveillance::class);
    }

    public function references(){
        return $this->hasMany(Reference::class);
    }

    public function vor(){
        return $this->hasOne(Vor::class);
    }

    public function markerpoints(){
        return $this->hasMany(MarkerPoints::class);
    }

    public function floodlights(){
        return $this->hasMany(FloodlightTower::class);
    }

    public function aerodromeBeacons(){
        return $this->hasMany(AerodromeBeacon::class);
    }

    public function clients(){
        return $this->belongsToMany(\App\Models\Client::class, 'company_airports', 'airport_id', 'company_id');
    }

    public function wdi_list(){
        return $this->hasMany(Wdi::class);
    }

    // Returns "true" if the airport has at least one PAPI
    public function hasPapi(){
        foreach ($this->runways as $runway) {
            foreach ($runway->headers as $header) {
                if(!$header->papis->isEmpty())
                    return true;
            }

        }

        return false;
    }

    // Returns "true" if the airport has at least one ILS
    public function hasIls(){
        foreach ($this->runways as $runway) {
            foreach ($runway->headers as $header) {
                if($header->ils)
                    return true;
            }
        }

        return false;
    }

    // Returns "true" if the airport has at least one ILS
    public function hasAls(){
        foreach ($this->runways as $runway) {
            foreach ($runway->headers as $header) {
                if($header->als)
                    return true;
            }
        }

        return false;
    }

    public function parameters(){
        return Parameter::where('subject_type_id', 4)->where('subject_id', $this->id)->get();
    }

    // Airports for Operator Users
    public function operator($operator_id = null){
        if($operator_id == null){
            $operator_airport = DB::table('operator_airport')
                                ->where('subject_type_id', 4)
                                ->get();
        }else{
            $operator_airport = DB::table('operator_airport')->where('operator_id', $this->id)->get();
        }
        $operators_id = [];
        foreach ($operator_airport as $key => $element) {
            array_push($operators_id, $element->operator_id);
        }
        $operators = Operator::whereIn('id', $operators_id)->get();

        return $operators;
    }

}
