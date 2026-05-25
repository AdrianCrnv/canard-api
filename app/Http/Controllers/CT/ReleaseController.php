<?php

namespace App\Http\Controllers\CT;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;

class ReleaseController extends Controller
{
    // =========================================================================
    //  CALIBRATION TOOL
    // =========================================================================

    #[OA\Get(
        path: '/api/ct/releases/calibration-tool/version',
        summary: 'Obtiene el código y nombre de la última versión de la Calibration Tool',
        security: [['bearerAuth' => []]],
        tags: ['CT - Releases'],
        responses: [
            new OA\Response(response: 200, description: 'Versión encontrada'),
            new OA\Response(response: 400, description: 'No hay versiones disponibles'),
        ]
    )]
    public function versionCodeCalibrationTool(Request $request): JsonResponse
    {
        $version = DB::table('releases_app')->latest('version_code')->first();

        if ($version) {
            return response()->json([
                'message'     => $version->version_name,
                'versionCode' => $version->version_code,
            ], 200);
        }

        return response()->json(['message' => 'error'], 400);
    }

    #[OA\Get(
        path: '/api/ct/releases/calibration-tool/download',
        summary: 'Genera una URL temporal (10 min) para descargar el APK de la Calibration Tool desde S3',
        security: [['bearerAuth' => []]],
        tags: ['CT - Releases'],
        responses: [
            new OA\Response(response: 200, description: 'URL temporal generada'),
            new OA\Response(response: 404, description: 'Archivo no encontrado en S3'),
        ]
    )]
    public function sendCalibrationToolApk(Request $request): JsonResponse
    {
        if ($request->request->count() === 0) {
            // Obtener la última versión del APK desde la base de datos
            $versionName = DB::table('releases_app')->latest('version_code')->first()->version_name;

            // Verificar si el archivo existe en S3
            if (Storage::disk('s3')->exists('versions_calibration_tool/' . $versionName . '.apk')) {
                // Generar una URL temporal para el archivo en S3 que expira en 10 minutos
                $fileUrl = Storage::disk('s3')->temporaryUrl(
                    'versions_calibration_tool/' . $versionName . '.apk',
                    now()->addMinutes(10)
                );

                return response()->json([
                    'url'         => $fileUrl,
                    'folder_name' => 'versions_calibration_tool',
                ], 200);
            }

            return response()->json(['message' => 'File not found'], 404);
        }

        return response()->json(['message' => 'Bad request'], 400);
    }

    // =========================================================================
    //  PNA APP
    // =========================================================================

    #[OA\Get(
        path: '/api/ct/releases/pna-app/version',
        summary: 'Obtiene el código y nombre de la última versión de la PNA App',
        security: [['bearerAuth' => []]],
        tags: ['CT - Releases'],
        responses: [
            new OA\Response(response: 200, description: 'Versión encontrada'),
            new OA\Response(response: 400, description: 'No hay versiones disponibles'),
            new OA\Response(response: 403, description: 'Sin permiso'),
        ]
    )]
    public function versionCodePnaApp(Request $request): JsonResponse
    {
        $user        = Auth::user();
        $permissions = $user->getAllPermissions();

        if ($permissions->contains('name', 'pna_app_update')) {
            $version = DB::table('releases_pna_app')->latest('version_code')->first();

            if ($version) {
                return response()->json([
                    'message'     => $version->version_name,
                    'versionCode' => $version->version_code,
                ], 200);
            }

            return response()->json(['message' => 'error'], 400);
        }

        return response()->json(['status' => 'error', 'message' => 'Permission denied'], 403);
    }

    #[OA\Get(
        path: '/api/ct/releases/pna-app/download',
        summary: 'Genera una URL temporal (10 min) para descargar el APK de la PNA App desde S3',
        security: [['bearerAuth' => []]],
        tags: ['CT - Releases'],
        responses: [
            new OA\Response(response: 200, description: 'URL temporal generada'),
            new OA\Response(response: 400, description: 'No hay versiones disponibles'),
            new OA\Response(response: 403, description: 'Sin permiso'),
            new OA\Response(response: 404, description: 'Archivo no encontrado en S3'),
        ]
    )]
    public function sendPnaAppApk(Request $request): JsonResponse
    {
        $user        = Auth::user();
        $permissions = $user->getAllPermissions();

        if ($permissions->contains('name', 'pna_app_update')) {
            $version_name = DB::table('releases_pna_app')->latest('id')->first();

            if ($version_name) {
                if ($request->request->count() === 0) {
                    // Verificar si el archivo existe en S3
                    if (Storage::disk('s3')->exists('versions_pna_android_app/' . $version_name->version_name . '.apk')) {
                        // Generar una URL temporal para el archivo en S3 que expira en 10 minutos
                        $fileUrl = Storage::disk('s3')->temporaryUrl(
                            'versions_pna_android_app/' . $version_name->version_name . '.apk',
                            now()->addMinutes(10)
                        );

                        return response()->json([
                            'url'         => $fileUrl,
                            'folder_name' => 'versions_pna_android_app',
                        ], 200);
                    }

                    return response()->json(['message' => 'File not found'], 404);
                }

                return response()->json(['message' => 'Bad request'], 400);
            }

            return response()->json(['message' => 'error'], 400);
        }

        return response()->json(['status' => 'error', 'message' => 'Permission denied'], 403);
    }

    // =========================================================================
    //  PNA RECEIVER
    // =========================================================================

    #[OA\Get(
        path: '/api/ct/releases/pna-receiver/version',
        summary: 'Obtiene el código y nombre de la última versión de la PNA Receiver App',
        security: [['bearerAuth' => []]],
        tags: ['CT - Releases'],
        responses: [
            new OA\Response(response: 200, description: 'Versión encontrada'),
            new OA\Response(response: 400, description: 'No hay versiones disponibles'),
            new OA\Response(response: 403, description: 'Sin permiso'),
        ]
    )]
    public function versionCodePnaReceiverApp(Request $request): JsonResponse
    {
        $user        = Auth::user();
        $permissions = $user->getAllPermissions();

        if ($permissions->contains('name', 'pna_app_update')) {
            $version = DB::table('releases_pna_receiver_app')->latest('version_code')->first();

            if ($version) {
                return response()->json([
                    'message'     => $version->version_name,
                    'versionCode' => $version->version_code,
                ], 200);
            }

            return response()->json(['message' => 'error'], 400);
        }

        return response()->json(['status' => 'error', 'message' => 'Permission denied'], 403);
    }

    #[OA\Get(
        path: '/api/ct/releases/pna-receiver/download',
        summary: 'Descarga el ZIP de la última versión de la PNA Receiver App desde almacenamiento local',
        security: [['bearerAuth' => []]],
        tags: ['CT - Releases'],
        responses: [
            new OA\Response(response: 200, description: 'Archivo ZIP devuelto como descarga'),
            new OA\Response(response: 400, description: 'No hay versiones disponibles'),
            new OA\Response(response: 403, description: 'Sin permiso'),
        ]
    )]
    public function sendPnaReceiverAppZip(Request $request): mixed
    {
        $user        = Auth::user();
        $permissions = $user->getAllPermissions();

        if ($permissions->contains('name', 'pna_app_update')) {
            $version_name = DB::table('releases_pna_receiver_app')->latest('id')->first();

            if ($version_name) {
                return response()->file(
                    '../storage/app/releasesPnaReceiverApp/' . $version_name->version_name,
                    [
                        'Content-Type'        => 'application/zip',
                        'Content-Disposition' => 'attachment; filename="latest.zip"',
                        'Length'              => $version_name->length,
                    ]
                );
            }

            return response()->json(['message' => 'error'], 400);
        }

        return response()->json(['status' => 'error', 'message' => 'Permission denied'], 403);
    }
}
