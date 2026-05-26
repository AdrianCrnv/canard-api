<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Operation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ProcessVideoController extends Controller
{
    // ── Index ─────────────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/process-video',
        summary: 'Get operation and its QuickTime video files',
        security: [['bearerAuth' => []]],
        tags: ['Process Video'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation and video files'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('report_create');

        $operation = Operation::find($request->id);
        $files     = Media::where(['model_id' => $request->id, 'mime_type' => 'video/quicktime'])->get();

        return response()->json([
            'operation' => $operation,
            'files'     => $files,
        ]);
    }

    // ── Get SRT name ──────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/process-video/srt',
        summary: 'Get SRT subtitle file matching a video name',
        security: [['bearerAuth' => []]],
        tags: ['Process Video'],
        parameters: [
            new OA\Parameter(name: 'video_name', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Matching SRT media records'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function getSrtName(Request $request): JsonResponse
    {
        $this->authorize('report_create');

        $results = Media::where(['name' => $request->video_name, 'mime_type' => 'application/x-subrip'])->get();

        return response()->json($results);
    }
}
