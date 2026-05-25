<?php

namespace App\Http\Controllers\CT;

use App\Http\Controllers\Controller;
use App\Http\Resources\OperationLiteResource;
use App\Http\Resources\OperationResource;
use App\Models\Operation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use OpenApi\Attributes as OA;

class OperationController extends Controller
{
    #[OA\Get(
        path: '/api/ct/operations',
        summary: 'Lista las operaciones activas del operador autenticado',
        security: [['bearerAuth' => []]],
        tags: ['CT - Operations'],
        responses: [
            new OA\Response(response: 200, description: 'Listado de operaciones'),
            new OA\Response(response: 403, description: 'Sin permiso'),
        ]
    )]
    public function index(): mixed
    {
        $user        = Auth::user();
        $permissions = $user->getAllPermissions();

        if ($permissions->contains('name', 'operation_view')) {

            if ($user->operator_id == 1) { // ITE
                // Return all operations when the operator_id is 1
                $operations = Operation::whereIn('status_id', [1, 2])
                    ->orderBy('id', 'desc')->get();
            } else {
                // Return only the operations associated with the user's operator (from newer to older)
                $operations = Operation::where('operator_id', $user->operator_id)
                    ->whereIn('status_id', [1, 2])->orderBy('id', 'desc')->get();
            }

            // Return a collection of Operation Resources
            return OperationLiteResource::collection($operations);
        }

        return response()->json(['status' => 'error', 'message' => 'Permission denied'], 403);
    }

    #[OA\Get(
        path: '/api/ct/operations/{operation}',
        summary: 'Obtiene el detalle de una operación',
        security: [['bearerAuth' => []]],
        tags: ['CT - Operations'],
        parameters: [
            new OA\Parameter(name: 'operation', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detalle de la operación'),
            new OA\Response(response: 401, description: 'No autorizado'),
            new OA\Response(response: 403, description: 'Sin permiso'),
        ]
    )]
    public function show(Operation $operation): mixed
    {
        $user        = Auth::user();
        $permissions = $user->getAllPermissions();

        if ($permissions->contains('name', 'operation_view')) {

            // Check if the operation belongs to the user's operator
            if ($user->operator_id == $operation->operator_id || $user->hasRole('admin')) {
                OperationResource::withoutWrapping(); // Removes "data" wrap from the JSON response
                return new OperationResource($operation);
            }

            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        return response()->json(['status' => 'error', 'message' => 'Permission denied'], 403);
    }

    #[OA\Get(
        path: '/api/ct/operations/{operation}/report',
        summary: 'Genera el informe de una operación',
        security: [['bearerAuth' => []]],
        tags: ['CT - Operations'],
        parameters: [
            new OA\Parameter(name: 'operation', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Nombre del tipo de operación'),
        ]
    )]
    public function generateReport(Operation $operation): JsonResponse
    {
        return response()->json($operation->type->name, 200);
    }

    public function testTimeOut(): string
    {
        sleep(60);
        return "desperte";
    }
}
