<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CalibrationToolApk;
use App\Models\PnaAppApk;
use App\Models\PnaReceiverAppZip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;

class ReleaseController extends Controller
{
    // ── Index ─────────────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/releases',
        summary: 'List available release versions (filtered by role)',
        security: [['bearerAuth' => []]],
        tags: ['Releases'],
        responses: [
            new OA\Response(response: 200, description: 'Calibration tool, PNA app and PNA receiver versions'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function index(): JsonResponse
    {
        $user = Auth::user();

        if (!$user->hasRole('admin') && !$user->hasRole('pilot')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($user->hasRole('pilot')) {
            $versions_calibration_tool = CalibrationToolApk::orderByDesc('version_code')->limit(1)->get();
            $versions_pna_android      = PnaAppApk::orderByDesc('version_code')->limit(1)->get();
            $versions_pna_receiver     = PnaReceiverAppZip::orderByDesc('version_code')->limit(1)->get();
        } else {
            $versions_calibration_tool = CalibrationToolApk::orderByDesc('version_code')->get();
            $versions_pna_android      = PnaAppApk::orderByDesc('version_code')->get();
            $versions_pna_receiver     = PnaReceiverAppZip::orderByDesc('version_code')->get();
        }

        return response()->json([
            'versions_calibration_tool' => $versions_calibration_tool,
            'versions_pna_android'      => $versions_pna_android,
            'versions_pna_receiver'     => $versions_pna_receiver,
        ]);
    }

    // ── Calibration Tool ──────────────────────────────────────────────────────

    #[OA\Post(
        path: '/api/releases/calibration-tool',
        summary: 'Register a new Calibration Tool APK release',
        security: [['bearerAuth' => []]],
        tags: ['Releases'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['version_code', 'version_name'],
                properties: [
                    new OA\Property(property: 'version_code', type: 'string'),
                    new OA\Property(property: 'version_name', type: 'string'),
                    new OA\Property(property: 'description',  type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Release created'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function storeCalibrationTool(Request $request): JsonResponse
    {
        $user = Auth::user();

        if (!$user->hasRole('admin')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $request->validate([
            'version_code' => 'required|unique:releases_app',
            'version_name' => 'required|unique:releases_app',
        ]);

        try {
            $length  = Storage::disk('s3')->size('versions_calibration_tool/' . $request['version_name'] . '.apk');
            $release = CalibrationToolApk::create([
                'version_name' => $request['version_name'],
                'version_code' => $request['version_code'],
                'length'       => $length,
                'description'  => $request['description'],
            ]);
            ActivityLog::log('create', 'Release', $release->id, "CalibrationTool v{$release->version_name} (code {$release->version_code}) uploaded by '{$user->name}'");
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Release', null, 'Error in storeCalibrationTool: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }

        return response()->json($release, 201);
    }

    #[OA\Get(
        path: '/api/releases/calibration-tool/{version}/download',
        summary: 'Get a pre-signed S3 download URL for a Calibration Tool APK',
        security: [['bearerAuth' => []]],
        tags: ['Releases'],
        parameters: [
            new OA\Parameter(name: 'version', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Pre-signed download URL'),
            new OA\Response(response: 404, description: 'Version not found'),
        ]
    )]
    public function downloadCt(int $version): JsonResponse
    {
        $record = DB::table('releases_app')->where('id', $version)->first();

        if (!$record) {
            Log::error('[CT Download] Version not found in DB', ['version_id' => $version]);
            return response()->json(['message' => 'Version not found.'], 404);
        }

        $s3Path = 'versions_calibration_tool/' . $record->version_name . '.apk';

        if (!Storage::disk('s3')->exists($s3Path)) {
            return response()->json(['message' => 'File not found in storage.'], 404);
        }

        $url = Storage::disk('s3')->temporaryUrl($s3Path, now()->addMinutes(10), [
            'ResponseContentDisposition' => 'attachment; filename="' . $this->buildDownloadName($record->version_name, 'apk') . '"',
        ]);

        ActivityLog::log('download', 'Release', $record->id, "CalibrationTool v{$record->version_name} downloaded by '" . Auth::user()->name . "'");

        return response()->json(['url' => $url]);
    }

    #[OA\Delete(
        path: '/api/releases/calibration-tool/{version_id}',
        summary: 'Delete a Calibration Tool APK from S3 and the database',
        security: [['bearerAuth' => []]],
        tags: ['Releases'],
        parameters: [
            new OA\Parameter(name: 'version_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Deleted'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function deleteCt(int $version_id): JsonResponse
    {
        try {
            $record = CalibrationToolApk::findOrFail($version_id);
            Storage::disk('s3')->delete('versions_calibration_tool/' . $record->version_name . '.apk');
            $record->delete();
            ActivityLog::log('delete', 'Release', $version_id, "CalibrationTool v{$record->version_name} deleted by '" . Auth::user()->name . "'");
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Release', null, 'Error in deleteCt: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }

        return response()->json(['message' => 'CalibrationTool release deleted successfully.']);
    }

    // ── PNA Android App ───────────────────────────────────────────────────────

    #[OA\Post(
        path: '/api/releases/pna-app',
        summary: 'Register a new PNA Android App APK release',
        security: [['bearerAuth' => []]],
        tags: ['Releases'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['version_name_pna_app', 'version_code_pna_app'],
                properties: [
                    new OA\Property(property: 'version_name_pna_app', type: 'string'),
                    new OA\Property(property: 'version_code_pna_app', type: 'string'),
                    new OA\Property(property: 'description_pna_app',  type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Release created'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function storePnaAndroidApp(Request $request): JsonResponse
    {
        $user = Auth::user();

        if (!$user->hasRole('admin')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $request->validate([
            'version_name_pna_app' => 'required|unique:releases_pna_app,version_name',
            'version_code_pna_app' => 'required|unique:releases_pna_app,version_code',
        ]);

        try {
            $length  = Storage::disk('s3')->size('versions_pna_android_app/' . $request['version_name_pna_app'] . '.apk');
            $release = PnaAppApk::create([
                'version_name' => $request['version_name_pna_app'],
                'version_code' => $request['version_code_pna_app'],
                'length'       => $length,
                'description'  => $request['description_pna_app'],
            ]);
            ActivityLog::log('create', 'Release', $release->id, "PNA Android App v{$release->version_name} (code {$release->version_code}) uploaded by '{$user->name}'");
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Release', null, 'Error in storePnaAndroidApp: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }

        return response()->json($release, 201);
    }

    #[OA\Get(
        path: '/api/releases/pna-app/{version}/download',
        summary: 'Get a pre-signed S3 download URL for a PNA Android App APK',
        security: [['bearerAuth' => []]],
        tags: ['Releases'],
        parameters: [
            new OA\Parameter(name: 'version', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Pre-signed download URL'),
            new OA\Response(response: 404, description: 'Version not found'),
        ]
    )]
    public function downloadPnaApp(int $version): JsonResponse
    {
        $record = DB::table('releases_pna_app')->where('id', $version)->first();

        if (!$record) {
            Log::error('[PNA Download] Version not found in DB', ['version_id' => $version]);
            return response()->json(['message' => 'Version not found.'], 404);
        }

        $s3Path = 'versions_pna_android_app/' . $record->version_name . '.apk';

        if (!Storage::disk('s3')->exists($s3Path)) {
            return response()->json(['message' => 'File not found in storage.'], 404);
        }

        $url = Storage::disk('s3')->temporaryUrl($s3Path, now()->addMinutes(10), [
            'ResponseContentDisposition' => 'attachment; filename="' . $this->buildDownloadName($record->version_name, 'apk') . '"',
        ]);

        ActivityLog::log('download', 'Release', $record->id, "PNA Android App v{$record->version_name} downloaded by '" . Auth::user()->name . "'");

        return response()->json(['url' => $url]);
    }

    #[OA\Delete(
        path: '/api/releases/pna-app/{version_id}',
        summary: 'Delete a PNA Android App APK from S3 and the database',
        security: [['bearerAuth' => []]],
        tags: ['Releases'],
        parameters: [
            new OA\Parameter(name: 'version_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Deleted'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function deletePnaApp(int $version_id): JsonResponse
    {
        try {
            $record = PnaAppApk::findOrFail($version_id);
            Storage::disk('s3')->delete('versions_pna_android_app/' . $record->version_name . '.apk');
            $record->delete();
            ActivityLog::log('delete', 'Release', $version_id, "PNA Android App v{$record->version_name} deleted by '" . Auth::user()->name . "'");
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Release', null, 'Error in deletePnaApp: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }

        return response()->json(['message' => 'PNA Android App release deleted successfully.']);
    }

    // ── PNA Receiver App ──────────────────────────────────────────────────────

    #[OA\Post(
        path: '/api/releases/pna-receiver',
        summary: 'Register a new PNA Receiver App ZIP release',
        security: [['bearerAuth' => []]],
        tags: ['Releases'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['version_code_pna', 'version_name_pna'],
                properties: [
                    new OA\Property(property: 'version_name_pna', type: 'string'),
                    new OA\Property(property: 'version_code_pna', type: 'string'),
                    new OA\Property(property: 'description_pna',  type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Release created'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function storePnaReceiverApp(Request $request): JsonResponse
    {
        $user = Auth::user();

        if (!$user->hasRole('admin')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $request->validate([
            'version_code_pna' => 'required|unique:releases_pna_receiver_app,version_code',
            'version_name_pna' => 'required|unique:releases_pna_receiver_app,version_name',
        ]);

        try {
            $length  = Storage::disk('s3')->size('versions_pna_receiver_app/' . $request['version_name_pna'] . '.zip');
            $release = PnaReceiverAppZip::create([
                'version_name' => $request['version_name_pna'],
                'version_code' => $request['version_code_pna'],
                'length'       => $length,
                'description'  => $request['description_pna'],
            ]);
            ActivityLog::log('create', 'Release', $release->id, "PNA Receiver App v{$release->version_name} (code {$release->version_code}) uploaded by '{$user->name}'");
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Release', null, 'Error in storePnaReceiverApp: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }

        return response()->json($release, 201);
    }

    #[OA\Get(
        path: '/api/releases/pna-receiver/{version}/download',
        summary: 'Get a pre-signed S3 download URL for a PNA Receiver App ZIP',
        security: [['bearerAuth' => []]],
        tags: ['Releases'],
        parameters: [
            new OA\Parameter(name: 'version', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Pre-signed download URL'),
            new OA\Response(response: 404, description: 'Version not found'),
        ]
    )]
    public function downloadPnaRf(int $version): JsonResponse
    {
        $record = DB::table('releases_pna_receiver_app')->where('id', $version)->first();

        if (!$record) {
            Log::error('[PNA RF Download] Version not found in DB', ['version_id' => $version]);
            return response()->json(['message' => 'Version not found.'], 404);
        }

        $s3Path = 'versions_pna_receiver_app/' . $record->version_name . '.zip';

        if (!Storage::disk('s3')->exists($s3Path)) {
            return response()->json(['message' => 'File not found in storage.'], 404);
        }

        $url = Storage::disk('s3')->temporaryUrl($s3Path, now()->addMinutes(10), [
            'ResponseContentDisposition' => 'attachment; filename="' . $this->buildDownloadName($record->version_name, 'zip') . '"',
        ]);

        ActivityLog::log('download', 'Release', $record->id, "PNA Receiver App v{$record->version_name} downloaded by '" . Auth::user()->name . "'");

        return response()->json(['url' => $url]);
    }

    #[OA\Delete(
        path: '/api/releases/pna-receiver/{version_id}',
        summary: 'Delete a PNA Receiver App ZIP from S3 and the database',
        security: [['bearerAuth' => []]],
        tags: ['Releases'],
        parameters: [
            new OA\Parameter(name: 'version_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Deleted'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function deletePnaRf(int $version_id): JsonResponse
    {
        try {
            $record = PnaReceiverAppZip::findOrFail($version_id);
            Storage::disk('s3')->delete('versions_pna_receiver_app/' . $record->version_name . '.zip');
            $record->delete();
            ActivityLog::log('delete', 'Release', $version_id, "PNA Receiver App v{$record->version_name} deleted by '" . Auth::user()->name . "'");
        } catch (\Exception $e) {
            ActivityLog::log('error', 'Release', null, 'Error in deletePnaRf: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }

        return response()->json(['message' => 'PNA Receiver App release deleted successfully.']);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function buildDownloadName(string $versionName, string $extension): string
    {
        $env = app()->environment();
        return $env !== 'production'
            ? "{$versionName}_{$env}.{$extension}"
            : "{$versionName}.{$extension}";
    }
}
