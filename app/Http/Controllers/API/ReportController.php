<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateReportJob;
use App\Models\ActivityLog;
use App\Models\Operation;
use App\Models\OperationReports;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;

class ReportController extends Controller
{
    // ── Generate ──────────────────────────────────────────────────────────────

    #[OA\Post(
        path: '/api/reports/{operation}/generate/{language}/{angle_unit}',
        summary: 'Dispatch a background report generation job for an operation',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        parameters: [
            new OA\Parameter(name: 'operation',   in: 'path', required: true,  schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'language',    in: 'path', required: false, schema: new OA\Schema(type: 'string', enum: ['en', 'fr', 'pt', 'es', 'es-aena'], default: 'en')),
            new OA\Parameter(name: 'angle_unit',  in: 'path', required: false, schema: new OA\Schema(type: 'string', default: 'da')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Report generation started'),
            new OA\Response(response: 400, description: 'Language not supported'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function generate(Operation $operation, string $language = 'en', string $angle_unit = 'da'): JsonResponse
    {
        $user        = Auth::user();
        $permissions = $user->getAllPermissions();

        if (!$permissions->contains('name', 'report_create')) {
            return response()->json(['error' => 'Permission denied'], 403);
        }

        if (!$user->hasRole('admin') && $operation->operator != $user->operator) {
            return response()->json(['error' => 'Permission denied'], 403);
        }

        $supported_langs = ['en', 'fr', 'pt', 'es', 'es-aena'];
        if (!in_array($language, $supported_langs)) {
            return response()->json(['error' => 'Language not supported'], 400);
        }

        $currentCount = OperationReports::where('operation_id', $operation->id)->count();

        GenerateReportJob::dispatch($operation, $language, $angle_unit, $user->id);

        return response()->json([
            'message'       => 'Report generation started in background',
            'current_count' => $currentCount,
        ]);
    }

    // ── Status ────────────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/reports/{operationId}/status',
        summary: 'Poll report generation status for an operation',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        parameters: [
            new OA\Parameter(name: 'operationId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Report count and any error message'),
        ]
    )]
    public function status(int $operationId): JsonResponse
    {
        $error = Cache::pull("report_error_{$operationId}");
        $count = OperationReports::where('operation_id', $operationId)->count();

        return response()->json(['count' => $count, 'error' => $error]);
    }

    // ── View report ───────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/reports/{id}/view',
        summary: 'Get a pre-signed S3 URL to view a report PDF',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Pre-signed URL'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'File not found'),
        ]
    )]
    public function viewReport(int $id): JsonResponse
    {
        $user        = Auth::user();
        $permissions = $user->getAllPermissions();

        if (!$permissions->contains('name', 'report_view')) {
            return response()->json(['error' => 'Permission denied'], 403);
        }

        $report    = OperationReports::where('id', $id)->first();
        $operation = Operation::find($report->operation_id);

        if (!$user->hasRole('admin') && !$user->hasRole('company') && $operation->operator != $user->operator) {
            return response()->json(['error' => 'Permission denied'], 403);
        }

        $folderMapping = Operation::getFolderMapping();
        $folderType    = $folderMapping[$operation->type_id] ?? null;
        $filePath      = "$folderType/{$operation->id}/reports/{$report->name}";

        if (!Storage::disk('s3')->exists($filePath)) {
            return response()->json(['error' => 'File not found in S3'], 404);
        }

        $url = Storage::disk('s3')->temporaryUrl($filePath, Carbon::now()->addMinutes(10));

        return response()->json(['url' => $url]);
    }

    // ── Destroy ───────────────────────────────────────────────────────────────

    #[OA\Delete(
        path: '/api/reports/{id}',
        summary: 'Delete a report from S3 and the database',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Report deleted'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Operation not found'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function destroy(int $id): JsonResponse
    {
        $this->authorize('report_delete');

        $report    = OperationReports::findOrFail($id);
        $operation = Operation::find($report->operation_id);

        if (!$operation) {
            return response()->json(['error' => 'Operation not found'], 404);
        }

        $folderMapping = Operation::getFolderMapping();
        $folderType    = $folderMapping[$operation->type_id] ?? 'Unknown';
        $filePath      = "$folderType/$operation->id/reports/{$report->name}";

        try {
            if (Storage::disk('s3')->exists($filePath)) {
                Storage::disk('s3')->delete($filePath);
            }

            ActivityLog::log('delete_report', 'Operation', $operation->id, "Deleted report '{$report->name}' from operation #{$operation->id} ({$operation->type->name})");

            $report->delete();

            return response()->json(['message' => 'Reporte eliminado con éxito']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al eliminar el reporte: ' . $e->getMessage()], 500);
        }
    }
}
