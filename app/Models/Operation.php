<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use DB;

class Operation extends Model implements HasMedia {

    use HasFactory;
    use InteractsWithMedia;

    protected $guarded = []; // Allow all fields to be saved as Mass Assignment

    public function task()
    {
        return $this->hasMany(Task::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('videos');
        $this->addMediaCollection('reports');
    }

    public function type(){
        return $this->belongsTo(OperationType::class);
    }

    public function status(){
        return $this->belongsTo(OperationStatus::class);
    }

    public function operator(){
        return $this->belongsTo(Operator::class);
    }

    public function drone(){
        return $this->belongsTo(Drone::class);
    }

    public function getDrone(){
        $drone = $this->drone->name;
        return $drone;
    }

    public function pilot(){
        return $this->belongsTo(User::class);
    }

    public function pilotName(){
        $pilot_name = User::where('id', $this->pilot_id)->first('name');
        return $pilot_name;
    }

    public function technician(){
        return $this->belongsTo(User::class);
    }

    public function tasks(){
        return $this->hasMany(Task::class)->with('type');
    }

    public function country()
    {
        return $this->belongsTo(Country::class, 'country_id');
    }

    public function parameters(){
        return Parameter::where('subject_type_id', $this->type->subject_type->id)
                            ->where('subject_id', $this->subject_id)
                            ->with('parameter_type', 'task_type')
                            ->get();
    }

    public function getAirport(){
        $airport = null;
        switch ($this->type->subject_type->id) {
            case 1: // Header
                $header = Header::find($this->subject_id);
                if ($header) {
                    $airport = $header->runway ? $header->runway->airport : null;
                }
                break;

            case 2: // Threshold
                $airport = null;
                break;

            case 3: // Runway
                $runway = Runway::find($this->subject_id);
                if ($runway && $runway->airport) {
                    $airport = $runway->airport;
                } else {
                    $airport = null;
                }
                break;

            case 4: // Airport
                $airport = Airport::find($this->subject_id);
                break;

            case 5: // VOR
                $airport = null;
                $vor = Vor::where('id', $this->subject_id)->first();
                $enroute = $vor->enroute;

                if($enroute == 1){
                    $airport = null;
                }else{
                    $airport = Airport::find($vor->airport_id);
                }
                break;

            case 6: // APRON
                $airport = Airport::find($this->subject_id);
                break;

            case 8: // Taxiway
                $airport = Airport::find($this->subject_id);
                break;

            case 9: // Surveillance
                $airport = Airport::find($this->subject_id);
                break;

            case 10: // ETOD
                $airport = Airport::find($this->subject_id);
                break;

            case 11: // APRON FLoodlight Tower
                $airport = Airport::find($this->subject_id);
                break;

            case 12: // Stand
                $stand = Stand::find($this->subject_id);
                $airport = $stand->airport;
                break;
            case 13: // Aerodrome Beacon
                $airport = Airport::find($this->subject_id);
                break;
            case 14: // WDI
                $airport = Airport::find($this->subject_id);
                break;
            default:
                $airport = null;
                break;
        }
        return $airport;
    }

    public function getVor(){
        if ($this->type->subject_type->id == 5) {// 5 -> VOR
            $vor = Vor::find($this->subject_id);
        }else{
            $vor = null;
        }

        return $vor;
    }

    public function getTaxiways(){
        $taxi_tasks = [];
        $taxi_names = "";
        $tasks = $this->tasks;

        foreach($tasks as $task){
            array_push($taxi_tasks, array(
                'name' => $task['description'],
            ));
        }
        foreach($taxi_tasks as $taxi_task){
            if($taxi_names == ""){
                $taxi_names = $taxi_names . $taxi_task['name'];
            }else{
                $taxi_names = $taxi_names . ", " . $taxi_task['name'];
            }
        }
        if($taxi_names == null){
            $taxi_names = null;
        }

        return $taxi_names;
    }

    public function getAprons(){
        $apron_tasks = [];
        $apron_names = "";
        $tasks = $this->tasks;

        foreach($tasks as $task){
            array_push($apron_tasks, array(
                'name' => $task['description'],
            ));
        }
        foreach($apron_tasks as $apron_task){
            if($apron_names == ""){
                $apron_names = $apron_names . $apron_task['name'];
            }else{
                $apron_names = $apron_names . ", " . $apron_task['name'];
            }
        }
        if($apron_names == null){
            $apron_names = null;
        }

        return $apron_names;
    }

    public function getEtods(){
        $etod_tasks = [];
        $etod_names = "";
        $tasks = $this->tasks;

        foreach($tasks as $task){
            array_push($etod_tasks, array(
                'name' => $task['description'],
            ));
        }
        foreach($etod_tasks as $etod_task){
            if($etod_names == ""){
                $etod_names = $etod_names . $etod_task['name'];
            }else{
                $etod_names = $etod_names . ", " . $etod_task['name'];
            }
        }
        if($etod_names == null){
            $etod_names = null;
        }

        return $etod_names;
    }

    public function getSurveillances(){
        $svllc_tasks = [];
        $svllc_names = "";
        $tasks = $this->tasks;

        foreach($tasks as $task){
            array_push($svllc_tasks, array(
                'name' => $task['description'],
            ));
        }
        foreach($svllc_tasks as $svllc_task){
            if($svllc_names == ""){
                $svllc_names = $svllc_names . $svllc_task['name'];
            }else{
                $svllc_names = $svllc_names . ", " . $svllc_task['name'];
            }
        }
        if($svllc_names == null){
            $svllc_names = null;
        }

        return $svllc_names;
    }

    public function getStand()
    {
        if ($this->type->subject_type->id == 12) {
            $stand = Stand::find($this->subject_id);
            return $stand ? $stand->name : null;
        }
        return null;
    }

    public function getApronFloodlights(){
        $floodlight_tasks = [];
        $floodlight_names = "";
        $tasks = $this->tasks;

        foreach($tasks as $task){
            array_push($floodlight_tasks, array(
                'name' => $task['description'],
            ));
        }
        foreach($floodlight_tasks as $floodlight_task){
            if($floodlight_names == ""){
                $floodlight_names = $floodlight_names . $floodlight_task['name'];
            }else{
                $floodlight_names = $floodlight_names . ", " . $floodlight_task['name'];
            }
        }
        if($floodlight_names == null){
            $floodlight_names = null;
        }

        return $floodlight_names;
    }

    public function getLocationName(){

        switch ($this->type->subject_type->id) {
            case 1: // Header
                $airport = $this->getAirport();
                $location = $airport ? $airport->name : 'Unknown Location';
                break;

            case 2: // Threshold
                $airport = $this->getAirport();
                $location = $airport ? $airport->name : 'Unknown Location';
                break;

            case 3: // Runway
                $airport = $this->getAirport();
                $location = $airport ? $airport->name : 'Unknown Location';
                break;

            case 4: // Airport
                $airport = $this->getAirport();
                $location = $airport ? $airport->name : 'Unknown Location';
                break;

            case 5: // VOR
                $location = VOR::find($this->subject_id)->name;
                break;

            case 6: // Apron
                $location = Airport::find($this->subject_id)->name;
                break;

            case 8: // Taxiway
                $location = Airport::find($this->subject_id)->name;
                break;

            case 9: // Surveillance
                $location = Airport::find($this->subject_id)->name;
                break;

            case 10: // Etod
                $location = Airport::find($this->subject_id)->name;
                break;
            case 12: // FT
                $location = $this->getAirport()->name;
                break;
            case 13: // AerodromeBeacon
                $location = $this->getAirport()->name;
                break;
            case 14: // WDI
                $location = Airport::find($this->subject_id)->name;
                break;
            default:
                $location = null;
                break;
        }

        return $location;
    }

    public function getCountry(){
        switch ($this->type->subject_type->id) {
            case 1: // Header
                $header = Header::find($this->subject_id);
                $country = $header?->runway?->airport?->country ?? null;
                break;

            case 2: // Threshold
                $country = null;
                break;

            case 3: // Runway
                $country = Runway::find($this->subject_id)?->airport?->country ?? null;
                break;

            case 4: // Airport
                $country = Airport::find($this->subject_id)->country;
                break;

            case 5: // VOR
                $country = Vor::find($this->subject_id)->country;
                break;

            case 6: // APRON
                $country = Airport::find($this->subject_id)->country;
                break;

            case 8: // Taxiway
                $country = Airport::find($this->subject_id)->country;
                break;

            case 9: // Surveillance
                $country = Airport::find($this->subject_id)->country;
                break;

            case 10: // ETOD
                $country = Airport::find($this->subject_id)->country;
                break;

            case 11: // APRON FLoodlight Tower
                $country = Airport::find($this->subject_id)->country;
                break;

            case 12: // Stand
                $country = Stand::find($this->subject_id)->airport->country;
                break;
            case 13: // Beacon
                $country = Airport::find($this->subject_id)->country;
                break;
            case 14: // WDI
                $country = Airport::find($this->subject_id)->country;
                break;
            default:
                $country = null;
                break;
        }

        return $country;
    }

    public function getCountryOptimized(){
        return $this->belongsTo(Country::class, 'country_id');
    }

    public function subject(){
        $subject = null;
        switch ($this->type->subject_type_id) {
            case 1: // Header
                $subject = Header::with('papis', 'ils.channel')->find($this->subject_id);
                break;
            case 3: // Runway
                $subject = Runway::with('headers')->find($this->subject_id);
                break;
            case 4: // Airport
                $subject = Airport::with('runways')->find($this->subject_id);
                break;
            case 5: // Vor
                $subject = Vor::find($this->subject_id);
                break;
            case 6: // APRON
                $subject = Airport::find($this->subject_id);
                break;
            case 7: // Drone
                $subject = Drone::find($this->subject_id);
                break;
            case 8: // Taxiway
                $subject = Airport::find($this->subject_id);
                break;
            case 9: // Surveillance
                $subject = Airport::find($this->subject_id);
                break;
            case 10: // ETOD
                $subject = Airport::find($this->subject_id);
                break;
            case 11: // APRON FLoodlight Tower
                $subject = Airport::find($this->subject_id);
                break;
            case 12: // Stand
                $subject = Stand::find($this->subject_id);
                break;
            case 13: //Aerodrome Beacon
                $subject = Airport::find($this->subject_id);
                break;
            case 14: // WDI
                $subject = Airport::find($this->subject_id);
                break;
            default:
                break;
        }
        return $subject;
    }

    public function processTasks(){
        return $this->hasMany(ProcessTask::class);
    }

    public function stretches(){
        return Stretches::where('subject_id', $this->subject_id)->get();
    }

    public function aircrafts() {
        return $this->belongsTo(Aircraft::class);
    }

    public function operation_aircraft()
    {
        return $this->hasOne(OperationAircraft::class);
    }

    public function operationReports()
    {
        return $this->hasMany(OperationReports::class);
    }

    public static function getFolderMapping()
    {
        return [
            1 => 'PAPI',
            2 => 'PAPI',
            3 => 'PAPI',
            4 => 'PAPI',
            5 => 'ILS',
            6 => 'ILS',
            7 => 'VOR',
            8 => 'ALS',
            9 => 'PCI',
            10 => 'Lights',
            11 => 'Lights',
            12 => 'Lights',
            13 => 'PCI',
            14 => 'PCI',
            15 => 'ETOD',
            16 => 'FOD',
            17 => 'Surveillance',
            18 => 'FloodlightTower',
            19 => 'AcMaint',
            20 => 'FlightTurn',
            21 => 'AerodromeBeacon',
            22 => 'Markings',
            23 => 'WDI',
        ];
    }

    public function companies()
    {
        return $this->belongsToMany(Company::class, 'company_operations', 'operation_id', 'company_id');
    }

    public function getWdis(){
        $airport = $this->getAirport();

        if (!$airport) {
            return null;
        }

        return $airport->name;
    }

}
