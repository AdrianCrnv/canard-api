<?php

namespace App\Http\Controllers\CT;

use App\Http\Controllers\Controller;
use App\Models\AlsReport;
use App\Models\IlsReport;
use App\Models\Operation;
use App\Models\PapiReport;
use App\Models\RwyLightsReport;
use App\Models\TxyLightsReport;
use App\Models\VorReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use OpenApi\Attributes as OA;

class ReportController extends Controller
{
    #[OA\Get(
        path: '/api/ct/permissions/test',
        summary: 'Comprueba si el usuario autenticado tiene permiso para ver operaciones',
        security: [['bearerAuth' => []]],
        tags: ['CT - Reports'],
        responses: [
            new OA\Response(response: 200, description: 'El usuario tiene permiso'),
            new OA\Response(response: 403, description: 'Sin permiso'),
        ]
    )]
    public function testPermission(): JsonResponse
    {
        $user        = Auth::user();
        $permissions = $user->getAllPermissions();

        if ($permissions->contains('name', 'operation_view')) {
            return response()->json(['status' => 'success', 'message' => 'User has permission to view operations']);
        }

        return response()->json(['status' => 'error', 'message' => 'User does not have permission to view operations'], 403);
    }

    #[OA\Get(
        path: '/api/ct/operations/{operation}/report/{language}/{angle_unit}',
        summary: 'Genera el informe de una operación en el idioma e unidad de ángulo indicados',
        security: [['bearerAuth' => []]],
        tags: ['CT - Reports'],
        parameters: [
            new OA\Parameter(name: 'operation', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(
                name: 'language',
                in: 'path',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['en', 'fr', 'pt', 'es', 'es-aena'], default: 'en')
            ),
            new OA\Parameter(
                name: 'angle_unit',
                in: 'path',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['da', 'deg'], default: 'da')
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Informe generado correctamente'),
            new OA\Response(response: 400, description: 'Idioma no soportado o tipo de operación desconocido'),
            new OA\Response(response: 403, description: 'Sin permiso o la operación no pertenece al usuario'),
        ]
    )]
    public function generate(Operation $operation, string $language = 'en', string $angle_unit = 'da'): mixed
    {
        $user        = Auth::user();
        $permissions = $user->getAllPermissions();

        // Check user's permission
        if (!$permissions->contains('name', 'report_create')) {
            return response()->json(['status' => 'error', 'message' => 'User has no permission to generate reports'], 403);
        }

        // Check if the user is an administrator
        if ($user->hasRole('admin') == false) {
            // Check if the operation's operator is the same as the user's
            if ($operation->operator != $user->operator) {
                return response()->json(['status' => 'error', 'message' => 'Operation does not belong to user'], 403);
            }
        }

        $supported_langs = ['en', 'fr', 'pt', 'es', 'es-aena'];

        // Control that the given language is supported
        if (!in_array($language, $supported_langs)) {
            return response()->json(['status' => 'error', 'message' => 'Language not supported'], 400);
        }

        $report = match ($operation->type_id) {
            1, 2, 3, 4 => new PapiReport,      // PAPI calibration
            5, 6       => new IlsReport,         // Localizer / Glide path ground inspection
            7          => new VorReport,          // VOR ground inspection
            8          => new AlsReport,          // ALS inspection
            10         => new RwyLightsReport,    // Rwy Lights inspection
            11         => new TxyLightsReport,    // Txy Lights inspection
            default    => null,
        };

        if ($report) {
            return $report->generate($operation, $language, $angle_unit);
        }

        return response()->json(['status' => 'error', 'message' => 'Unknown operation type'], 400);
    }
}
