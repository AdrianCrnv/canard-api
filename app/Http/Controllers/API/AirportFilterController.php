<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Airport;
use App\Models\Country;
use App\Models\EtodAreas;
use App\Models\Operator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AirportFilterController extends Controller
{
    public function filterMasterNoAdmin($airports, array $airports_ids, ?string $given_country, ?string $given_operator): mixed
    {
        return Airport::where(function ($query) use ($given_country, $airports) {
            if ($given_country !== null) {
                $opInCountry = $this->countryFilter($airports, $given_country);
                $query->whereIn('country_id', $opInCountry);
            }
        })->whereIn('id', $airports_ids);
    }

    public function filterMasterAdmin($airports, array $airports_ids, ?string $given_country, ?string $given_operator): mixed
    {
        return Airport::where(function ($query) use ($given_country, $airports) {
            if ($given_country !== null) {
                $opInCountry = $this->countryFilter($airports, $given_country);
                $query->whereIn('country_id', $opInCountry);
            }
        })->where(function ($query) use ($given_operator, $airports) {
            if ($given_operator !== null) {
                $opInOperator = $this->operatorFilter($airports, $given_operator);
                $query->whereIn('id', $opInOperator);
            }
        });
    }

    public function countryFilter($airports, string $given_country): array
    {
        return $airports
            ->whereHas('country', function ($query) use ($given_country) {
                $query->where('name', $given_country);
            })
            ->pluck('country_id')
            ->toArray();
    }

    public function operatorFilter($airports, string $given_operator): array
    {
        $airportInOperator = [];
        $operator          = Operator::where('name', $given_operator)->first();

        $operatorAirportIds = DB::table('operator_airport')
            ->where('operator_id', $operator->id)
            ->where('subject_type_id', 4)
            ->get();

        foreach ($operatorAirportIds as $airport) {
            $airportInOperator[] = $airport->subject_id;
        }

        return $airportInOperator;
    }

    public function countrySelect($airports, bool $allOptions = false): array
    {
        if (Auth::user()->hasRole('company')) {
            $countriesIds          = $airports->pluck('country_id');
            $countries             = Country::whereIn('id', $countriesIds)->get();
            $countriesAllAirports  = $countries->pluck('name')->toArray();
            $repetitionCountries   = array_count_values($countriesAllAirports);
        } else {
            $countriesAllAirports = [];
            foreach ($airports as $airport) {
                $countriesAllAirports[] = $airport->country->name;
            }
            $repetitionCountries = array_count_values($countriesAllAirports);
        }

        // País del operador primero
        $userOperator = Auth::user()->operator;
        $userCountry  = $userOperator ? optional(Country::find($userOperator->country_id))->name : null;

        if ($userCountry && isset($repetitionCountries[$userCountry])) {
            $operatorEntry  = [$userCountry => $repetitionCountries[$userCountry]];
            $otherCountries = array_filter(
                $repetitionCountries,
                fn ($key) => $key !== $userCountry,
                ARRAY_FILTER_USE_KEY
            );
            ksort($otherCountries);
            return $operatorEntry + $otherCountries;
        }

        ksort($repetitionCountries);
        return $repetitionCountries;
    }

    public function operatorSelect($airports, bool $allOptions = false): array
    {
        $airportFilterOperator = $allOptions ? $airports : $airports->get();

        if (count($airportFilterOperator) != 0) {
            $operatorsAllAirports = $airportFilterOperator[0]->operator();
        } else {
            $operatorsAllAirports = [];
        }

        $operatorsNames = [];
        foreach ($operatorsAllAirports as $operator) {
            $operatorsNames[] = $operator->name;
        }

        return array_count_values($operatorsNames);
    }

    public function orderEtodAreas($etod_areas, $etods): array
    {
        $etods_array = [];
        foreach ($etods as $key => $etod) {
            $areaName = $etod_areas[$etod['area_id'] - 1]->name;
            if (!isset($etods_array[$areaName])) {
                $etods_array[$areaName] = [];
            }
            array_push($etods_array[$areaName], $etod);
        }
        return $etods_array;
    }

    public function getSortedCountries(?int $operatorCountryId = null): mixed
    {
        $countries = Country::orderBy('name', 'asc')->get();

        if (!$operatorCountryId) {
            return $countries;
        }

        $operatorCountry = $countries->firstWhere('id', $operatorCountryId);
        $otherCountries  = $countries->filter(fn ($c) => $c->id !== $operatorCountryId)->values();

        return collect([$operatorCountry])->merge($otherCountries);
    }
}
