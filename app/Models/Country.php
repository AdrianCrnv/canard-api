<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class Country extends Model {

    use HasFactory;

    public function airports(){
        return $this->hasMany(Airport::class)->orderBy('name');
    }

    public function vors(){
        return $this->hasMany(Vor::class);
    }

    public static function getCountriesWithAirports(){
        $countries_ids = [];
        $airports = Airport::all();
        $vors = Vor::all();

        foreach ($airports as $key => $airport) {
            if(!in_array($airport->country_id, $countries_ids)){
                array_push($countries_ids, $airport->country_id);
            }
        }

        foreach ($vors as $key => $vor) {
            if(!in_array($vor->country_id, $countries_ids)){
                array_push($countries_ids, $vor->country_id);
            }
        }

        $countries = Country::whereIn('id', $countries_ids)->get();

        return $countries;
    }

    public static function getCountriesWithAirportsToOperator(){
        $airports_ids = [];
        $vors_ids = [];
        $countries_ids = [];

        $airports_to_operator = DB::table('operator_airport')
        ->where('operator_id', Auth::user()->operator_id)->get();

        foreach ($airports_to_operator as $key => $airport) {
            if($airport->subject_type_id == 4){
                if(!in_array($airport->subject_id, $airports_ids)){
                    array_push($airports_ids, $airport->subject_id);
                }
            }else if($airport->subject_type_id == 5){
                if(!in_array($airport->subject_id, $vors_ids)){
                    array_push($vors_ids, $airport->subject_id);
                }
            }
        }

        $airports = Airport::whereIn('id', $airports_ids)->get();
        $vors = Vor::whereIn('id', $vors_ids)->get();

        foreach ($airports as $key => $airport) {
            if(!in_array($airport->country_id, $countries_ids)){
                array_push($countries_ids, $airport->country_id);
            }
        }

        foreach ($vors as $key => $vor) {
            if(!in_array($vor->country_id, $countries_ids)){
                array_push($countries_ids, $vor->country_id);
            }
        }

        $countries = Country::whereIn('id', $countries_ids)->get();

        return $countries;
    }

    public static function getCountriesWithAirportsOrVors(){
        return Country::has('airports')->orHas('vors')->get();
    }

    public static function getCountriesWithVors(){
        return Country::has('vors')->get();
    }

    // Returns countries that have at least one header (airports->runways->headers)
    public static function getCountriesWithHeaders(){
        $countries = collect(); // Create a Laravel collection
        $headers = Header::all();

        foreach ($headers as $header) {
            $country = $header->runway->airport->country;

            if($countries->contains($country) == false) // Only add country to collection if it's not already there
                $countries->push($country); // Add country to collection
        }

        $countries = $countries->sortBy('name'); // Sort collection items by name

        return $countries;
    }

    // Returns countries that have at least one runway (airports->runways)
    public static function getCountriesWithRunways(){
        $countries = collect(); // Create a Laravel collection
        $runways = Runway::all();

        foreach ($runways as $runway) {
            $country = $runway->airport->country;

            if($countries->contains($country) == false) // Only add country to collection if it's not already there
                $countries->push($country); // Add country to collection
        }

        $countries = $countries->sortBy('name'); // Sort collection items by name

        return $countries;
    }
}
