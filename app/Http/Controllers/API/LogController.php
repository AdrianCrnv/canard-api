<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\ActivityLog;
use App\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class LogController extends Controller
{
    #[OA\Get(
        path: '/api/logs',
        summary: 'Listar logs de actividad con filtros opcionales',
        security: [['bearerAuth' => []]],
        tags: ['Logs'],
        parameters: [
            new OA\Parameter(name: 'entity',  in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'action',  in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'search',  in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort',    in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page',    in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Listado paginado de logs'),
            new OA\Response(response: 403, description: 'Acceso denegado'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        abort_unless(auth()->user()->hasRole('admin'), 403);

        $given_entity = $request->input('entity');
        $given_action = $request->input('action');
        $given_search = $request->input('search');

        $query = ActivityLog::with('user')->sortable();

        if ($given_entity) {
            $query->where('model_type', $given_entity);
        }

        if ($given_action) {
            $query->where('action', $given_action);
        }

        if ($given_search) {
            $term = $given_search;
            $query->where(function ($q) use ($term) {
                $q->where('action', 'like', "%{$term}%")
                  ->orWhere('model_type', 'like', "%{$term}%")
                  ->orWhere('model_id', 'like', "%{$term}%")
                  ->orWhere('description', 'like', "%{$term}%")
                  ->orWhereHas('user', function ($u) use ($term) {
                      $u->where('name', 'like', "%{$term}%");
                  });
            });
        }

        if (!$request->has('sort')) {
            $query->orderBy('created_at', 'desc');
        }

        $logs = $query->paginate(50);

        $entities = ActivityLog::select('model_type')->distinct()->orderBy('model_type')->pluck('model_type');
        $actions  = ActivityLog::select('action')->distinct()->orderBy('action')->pluck('action');

        return response()->json([
            'logs'         => $logs,
            'entities'     => $entities,
            'actions'      => $actions,
            'given_entity' => $given_entity,
            'given_action' => $given_action,
            'given_search' => $given_search,
        ]);
    }

    #[OA\Get(
        path: '/api/logs/suggestions',
        summary: 'Obtener sugerencias de búsqueda para el buscador de logs',
        security: [['bearerAuth' => []]],
        tags: ['Logs'],
        parameters: [
            new OA\Parameter(name: 'term', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista de sugerencias'),
            new OA\Response(response: 403, description: 'Acceso denegado'),
        ]
    )]
    public function suggestions(Request $request): JsonResponse
    {
        abort_unless(auth()->user()->hasRole('admin'), 403);

        $term = $request->input('term', '');

        $users = User::where('name', 'like', "%{$term}%")->limit(5)->pluck('name');

        $actions = ActivityLog::select('action')->distinct()
            ->where('action', 'like', "%{$term}%")->pluck('action');

        $types = ActivityLog::select('model_type')->distinct()
            ->where('model_type', 'like', "%{$term}%")->pluck('model_type');

        $descs = ActivityLog::where('description', 'like', "%{$term}%")
            ->limit(5)->pluck('description')
            ->map(fn($d) => strlen($d) > 80 ? substr($d, 0, 80) . '...' : $d);

        $results = collect()
            ->merge($users)
            ->merge($actions)
            ->merge($types)
            ->merge($descs)
            ->unique()
            ->values()
            ->take(10);

        return response()->json($results);
    }
}
