<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Parameter;
use App\Models\ParameterType;
use App\Models\SubjectType;
use App\Models\TaskType;
use App\Rules\UniqueParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ParameterController extends Controller
{
    // ── Form data ─────────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/parameters/form-data',
        summary: 'Get subject types, task types and parameter types for the parameter form',
        security: [['bearerAuth' => []]],
        tags: ['Parameters'],
        responses: [
            new OA\Response(response: 200, description: 'Form data'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function manage(): JsonResponse
    {
        $this->authorize('parameter_create');

        return response()->json([
            'subject_types'    => SubjectType::all(),
            'task_types'       => TaskType::all(),
            'parameter_types'  => ParameterType::all(),
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    #[OA\Post(
        path: '/api/parameters',
        summary: 'Create a new parameter',
        security: [['bearerAuth' => []]],
        tags: ['Parameters'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['subject_type_id', 'subject_id', 'parameter_type_id', 'task_type_id', 'value'],
                properties: [
                    new OA\Property(property: 'subject_type_id',   type: 'integer'),
                    new OA\Property(property: 'subject_id',        type: 'integer'),
                    new OA\Property(property: 'parameter_type_id', type: 'integer'),
                    new OA\Property(property: 'task_type_id',      type: 'integer'),
                    new OA\Property(property: 'value',             type: 'number'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Parameter created'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('parameter_create');

        $request->validate([
            'subject_type_id'   => ['required', new UniqueParameter],
            'subject_id'        => ['required', new UniqueParameter],
            'parameter_type_id' => ['required', new UniqueParameter],
            'task_type_id'      => ['required', new UniqueParameter],
            'value'             => 'required|numeric',
        ]);

        $newParam = Parameter::create([
            'subject_type_id'   => $request['subject_type_id'],
            'subject_id'        => $request['subject_id'],
            'parameter_type_id' => $request['parameter_type_id'],
            'task_type_id'      => $request['task_type_id'],
            'value'             => $request['value'],
        ]);

        return response()->json($newParam, 201);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    #[OA\Put(
        path: '/api/parameters/{parameter}',
        summary: 'Update the value of an existing parameter',
        security: [['bearerAuth' => []]],
        tags: ['Parameters'],
        parameters: [
            new OA\Parameter(name: 'parameter', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['value'],
                properties: [
                    new OA\Property(property: 'value', type: 'number'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Parameter updated'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function update(Request $request, Parameter $parameter): JsonResponse
    {
        $this->authorize('parameter_edit');

        $request->validate([
            'value' => 'required|numeric',
        ]);

        $parameter->value = $request['value'];
        $parameter->save();

        return response()->json($parameter);
    }

    // ── Destroy ───────────────────────────────────────────────────────────────

    #[OA\Delete(
        path: '/api/parameters/{parameter}',
        summary: 'Delete a parameter',
        security: [['bearerAuth' => []]],
        tags: ['Parameters'],
        parameters: [
            new OA\Parameter(name: 'parameter', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Deleted'),
            new OA\Response(response: 500, description: 'Delete failed'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function destroy(Parameter $parameter): JsonResponse
    {
        $this->authorize('parameter_delete');

        if ($parameter->delete()) {
            return response()->json(['message' => 'Parameter deleted successfully.']);
        }

        return response()->json(['message' => 'Failed to delete parameter.'], 500);
    }

    // ── By subject ────────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/parameters/{subject_type}/{subject}',
        summary: 'Get all parameters for a given subject',
        security: [['bearerAuth' => []]],
        tags: ['Parameters'],
        parameters: [
            new OA\Parameter(name: 'subject_type', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'subject',      in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of parameters with related data'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function getParametersBySubject(int $subject_type, int $subject): JsonResponse
    {
        $this->authorize('parameter_view');

        $results = Parameter::where('subject_type_id', $subject_type)
            ->where('subject_id', $subject)
            ->get();

        $parameters = $results->map(fn($parameter) => [
            'id'             => $parameter->id,
            'parameter_type' => $parameter->parameter_type,
            'subject_type'   => $parameter->subject_type,
            'subject'        => $parameter->subject(),
            'task_type'      => $parameter->task_type,
            'value'          => $parameter->value,
        ]);

        return response()->json($parameters);
    }
}
