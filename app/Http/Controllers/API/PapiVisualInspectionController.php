<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ResultPapiVisualInspection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class PapiVisualInspectionController extends Controller
{
    #[OA\Get(
        path: '/api/papi/inspection/{operation_id}',
        summary: 'Get the visual inspection record for an operation',
        security: [['bearerAuth' => []]],
        tags: ['PAPI Visual Inspection'],
        parameters: [
            new OA\Parameter(name: 'operation_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Inspection record or null'),
        ]
    )]
    public function getInspection(int $operation_id): JsonResponse
    {
        $record = ResultPapiVisualInspection::where('operation_id', $operation_id)->first();

        return response()->json($record);
    }

    #[OA\Post(
        path: '/api/papi/inspection/save',
        summary: 'Save satisfactory and comments for a single inspection field',
        security: [['bearerAuth' => []]],
        tags: ['PAPI Visual Inspection'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['operation_id', 'field'],
                properties: [
                    new OA\Property(property: 'operation_id', type: 'integer'),
                    new OA\Property(property: 'field',        type: 'string', enum: ['symmetry', 'intensity', 'transition']),
                    new OA\Property(property: 'satisfactory', type: 'string', enum: ['ok', 'not_ok'], nullable: true),
                    new OA\Property(property: 'comments',     type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Saved successfully'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function saveInspection(Request $request): JsonResponse
    {
        $request->validate([
            'operation_id' => 'required|integer|exists:operations,id',
            'field'        => 'required|in:symmetry,intensity,transition',
            'satisfactory' => 'nullable|in:ok,not_ok',
            'comments'     => 'nullable|string|max:1000',
        ]);

        $field = $request->field;

        ResultPapiVisualInspection::updateOrCreate(
            ['operation_id' => $request->operation_id],
            [
                "{$field}_satisfactory" => $request->satisfactory ?: null,
                "{$field}_comments"     => $request->comments     ?: null,
            ]
        );

        return response()->json(['success' => true]);
    }

    #[OA\Post(
        path: '/api/papi/observations/save',
        summary: 'Save general observations for an operation inspection',
        security: [['bearerAuth' => []]],
        tags: ['PAPI Visual Inspection'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['operation_id'],
                properties: [
                    new OA\Property(property: 'operation_id', type: 'integer'),
                    new OA\Property(property: 'observations', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Saved successfully'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function saveObservations(Request $request): JsonResponse
    {
        $request->validate([
            'operation_id' => 'required|integer|exists:operations,id',
            'observations' => 'nullable|string|max:5000',
        ]);

        ResultPapiVisualInspection::updateOrCreate(
            ['operation_id' => $request->operation_id],
            ['observations' => $request->observations ?: null]
        );

        return response()->json(['success' => true]);
    }
}
