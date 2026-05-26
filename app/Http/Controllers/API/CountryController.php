<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Country;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use OpenApi\Attributes as OA;

class CountryController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    #[OA\Get(
        path: '/api/countries/by-subject-type/{subject_type_id}',
        summary: 'Devuelve los países que tienen sujetos del tipo indicado (cabecera, pista, aeropuerto, etc.)',
        security: [['bearerAuth' => []]],
        tags: ['Countries'],
        parameters: [
            new OA\Parameter(
                name: 'subject_type_id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer'),
                description: '1=Header, 2=Threshold, 3=Runway, 4=Airport, 5=VOR, 6=Apron, 7=Drone'
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Listado de países para el tipo de sujeto indicado'),
        ]
    )]
    public function getCountriesWithSubjectsOfType(int $subject_type_id): JsonResponse
    {
        $result = match ($subject_type_id) {
            1 => Country::getCountriesWithHeaders(),
            2 => [],  // Not implemented yet
            3 => Country::getCountriesWithRunways(),
            4 => Country::getCountriesWithAirports(),
            5 => Country::has('vors')->get(),
            6 => [],  // Not implemented yet
            7 => [],  // Not implemented yet
            default => [],
        };

        return response()->json($result);
    }

    #[OA\Get(
        path: '/api/countries/with-airport',
        summary: 'Devuelve los países que tienen aeropuertos. Los no-admin reciben solo los de su operador',
        security: [['bearerAuth' => []]],
        tags: ['Countries'],
        responses: [
            new OA\Response(response: 200, description: 'Listado de países con aeropuertos'),
        ]
    )]
    public function withAirport(): JsonResponse
    {
        if (Auth::user()->hasRole('admin')) {
            $countries = Country::getCountriesWithAirports();
        } else {
            $countries = Country::getCountriesWithAirportsToOperator();
        }

        return response()->json($countries);
    }

    #[OA\Get(
        path: '/api/countries',
        summary: 'Devuelve todos los países',
        security: [['bearerAuth' => []]],
        tags: ['Countries'],
        responses: [
            new OA\Response(response: 200, description: 'Listado completo de países'),
        ]
    )]
    public function allCountries(): JsonResponse
    {
        return response()->json(Country::all());
    }
}
