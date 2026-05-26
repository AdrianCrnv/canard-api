<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Reference;
use App\Models\Vor;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class VorReferenceController extends Controller
{
    // ── Form data for create ──────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/vors/{vor}/references/form-data',
        summary: 'Get data needed to build the create VOR reference form',
        security: [['bearerAuth' => []]],
        tags: ['VOR References'],
        parameters: [
            new OA\Parameter(name: 'vor', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'VOR data for the form'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function create(Vor $vor): JsonResponse
    {
        $this->authorize('airport_create');

        return response()->json(['vor' => $vor]);
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    #[OA\Post(
        path: '/api/vors/{vor}/references',
        summary: 'Create a new reference for a VOR',
        security: [['bearerAuth' => []]],
        tags: ['VOR References'],
        parameters: [
            new OA\Parameter(name: 'vor', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'Reference created'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function store(Request $request, Vor $vor): JsonResponse
    {
        $this->authorize('airport_create');

        $request->validate([
            'name' => [
                'required',
                Rule::unique('references')->where(fn($q) => $q->where('subject_id', request('subject_id'))->where('subject_type_id', 5)),
            ],
            'description'             => 'nullable|string|max:255',
            'reference_latitude'      => 'required|numeric|between:-90,90',
            'reference_longitude'     => 'required|numeric|between:-180,180',
            'ort_reference_elevation' => 'required|numeric|between:0,9999.999',
            'survey_date'             => 'nullable|date',
        ]);

        if ($request['survey_date']) {
            $request['survey_date'] = Carbon::parse($request['survey_date']);
        }

        try {
            $reference = Reference::create([
                'subject_id'          => $request['subject_id'],
                'subject_type_id'     => 5,
                'name'                => $request['name'],
                'description'         => $request['description'],
                'reference_latitude'  => $request['reference_latitude'],
                'reference_longitude' => $request['reference_longitude'],
                'reference_elevation' => $request['ort_reference_elevation'],
                'survey_date'         => $request['survey_date'],
            ]);

            $vor = Vor::find($request['subject_id']);
            ActivityLog::log('create', 'VOR', (int) $request['subject_id'], "New reference '{$reference->name}' for VOR '{$vor->name}' ({$vor->code})");
        } catch (\Exception $e) {
            ActivityLog::log('error', 'VOR', null, 'Error in store: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }

        return response()->json($reference, 201);
    }

    // ── Form data for edit ────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/vor-references/{reference}/edit-data',
        summary: 'Get reference data and related VOR for the edit form',
        security: [['bearerAuth' => []]],
        tags: ['VOR References'],
        parameters: [
            new OA\Parameter(name: 'reference', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Reference with VOR'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function edit(Reference $reference): JsonResponse
    {
        $this->authorize('airport_edit');

        $vor = Vor::where('id', $reference->subject_id)->first();

        return response()->json(['reference' => $reference, 'vor' => $vor]);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    #[OA\Put(
        path: '/api/vor-references/{reference}',
        summary: 'Update a VOR reference',
        security: [['bearerAuth' => []]],
        tags: ['VOR References'],
        parameters: [
            new OA\Parameter(name: 'reference', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Reference updated'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function update(Request $request, Reference $reference): JsonResponse
    {
        $this->authorize('airport_edit');

        $request->validate([
            'subject_id'              => 'required',
            'name'                    => [
                'required',
                Rule::unique('references')->ignore($reference->id)->where(fn($q) => $q->where('subject_id', request('subject_id'))->where('subject_type_id', 5)),
            ],
            'description'             => 'nullable|string|max:255',
            'reference_latitude'      => 'required|numeric|between:-90,90',
            'reference_longitude'     => 'required|numeric|between:-180,180',
            'ort_reference_elevation' => 'required|numeric|between:0,9999.999',
            'survey_date'             => 'date',
        ]);

        if ($request['survey_date']) {
            $request['survey_date'] = Carbon::parse($request['survey_date']);
        }

        try {
            $before = [
                'Name'        => $reference->name,
                'Description' => $reference->description,
                'Latitude'    => $reference->reference_latitude,
                'Longitude'   => $reference->reference_longitude,
                'Elevation'   => $reference->reference_elevation,
                'Survey date' => $reference->survey_date,
            ];

            $reference->subject_id          = $request['subject_id'];
            $reference->name                = $request['name'];
            $reference->description         = $request['description'];
            $reference->reference_latitude  = $request['reference_latitude'];
            $reference->reference_longitude = $request['reference_longitude'];
            $reference->reference_elevation = $request['ort_reference_elevation'];
            $reference->survey_date         = $request['survey_date'];
            $reference->save();

            $after = [
                'Name'        => $reference->name,
                'Description' => $reference->description,
                'Latitude'    => $reference->reference_latitude,
                'Longitude'   => $reference->reference_longitude,
                'Elevation'   => $reference->reference_elevation,
                'Survey date' => $reference->survey_date,
            ];

            $changes = [];
            foreach ($before as $field => $oldVal) {
                if ((string) $oldVal !== (string) $after[$field]) {
                    $changes[] = "{$field}: '{$oldVal}' → '{$after[$field]}'";
                }
            }

            $vor         = Vor::find($request['subject_id']);
            $description = "Updated reference '{$reference->name}' for VOR '{$vor->name}' ({$vor->code})"
                . (count($changes) > 0 ? ': ' . implode(', ', $changes) : '');
            ActivityLog::log('update', 'VOR', (int) $request['subject_id'], $description);
        } catch (\Exception $e) {
            ActivityLog::log('error', 'VOR', null, 'Error in update: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }

        return response()->json($reference->fresh());
    }

    // ── References in VOR ─────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/vors/{vor}/references',
        summary: 'Get all references for a VOR',
        security: [['bearerAuth' => []]],
        tags: ['VOR References'],
        parameters: [
            new OA\Parameter(name: 'vor', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of references'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function getReferencesInVor(Vor $vor): JsonResponse
    {
        $this->authorize('airport_view');

        return response()->json(
            Reference::where('subject_id', $vor->id)->where('subject_type_id', 5)->get()
        );
    }
}
