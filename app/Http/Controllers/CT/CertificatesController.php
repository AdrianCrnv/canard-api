<?php

namespace App\Http\Controllers\CT;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class CertificatesController extends Controller
{
    #[OA\Get(
        path: '/api/ct/certificates/{id}',
        summary: 'Obtiene un certificado activo por su ID',
        security: [['bearerAuth' => []]],
        tags: ['CT - Certificates'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Certificado encontrado'),
            new OA\Response(response: 404, description: 'Certificado no encontrado'),
            new OA\Response(response: 500, description: 'Error interno del servidor'),
        ]
    )]
    public function getCertificateById(int $id): JsonResponse
    {
        try {
            $certificate = DB::table('certificates')
                ->where('id', $id)
                ->where('is_active', true)
                ->first();

            if (!$certificate) {
                return response()->json([
                    'success' => false,
                    'message' => 'Certificado no encontrado con ID: ' . $id,
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data'    => [
                    'id'                => $certificate->id,
                    'consumer'          => $certificate->consumer,
                    'privateKey'        => $certificate->privateKey,
                    'certificate'       => $certificate->certificate,
                    'description'       => $certificate->description,
                    'keystoreName'      => $certificate->keystoreName,
                    'keystorePassword'  => $certificate->keystorePassword,
                    'keystoreAlias'     => $certificate->keystoreAlias,
                    'created_at'        => $certificate->created_at,
                ],
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener el certificado: ' . $e->getMessage(),
            ], 500);
        }
    }
}
