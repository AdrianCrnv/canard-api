<?php

namespace App\Http\Controllers\CT;

use App\Http\Controllers\Controller;
use App\Models\Operation;
use Aws\S3\S3Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

class S3Controller extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    #[OA\Post(
        path: '/api/ct/s3/multipart/initiate',
        summary: 'Inicia un Multipart Upload en S3 y devuelve el upload_id y la ruta destino',
        security: [['bearerAuth' => []]],
        tags: ['CT - S3'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['operation_id', 'operation_type_id', 'task_id', 'filename', 'file_size', 'file_type'],
                properties: [
                    new OA\Property(property: 'operation_id',      type: 'integer', example: 1),
                    new OA\Property(property: 'operation_type_id', type: 'integer', example: 10),
                    new OA\Property(property: 'task_id',           type: 'integer', example: 5),
                    new OA\Property(property: 'runway_id',         type: 'integer', nullable: true),
                    new OA\Property(property: 'side',              type: 'string',  nullable: true, maxLength: 10),
                    new OA\Property(property: 'filename',          type: 'string',  example: 'video.mp4'),
                    new OA\Property(property: 'file_size',         type: 'integer', example: 104857600),
                    new OA\Property(property: 'file_type',         type: 'string',  enum: ['video', 'srt', 'operation_file', 'image']),
                    new OA\Property(property: 'run',               type: 'integer', nullable: true, minimum: 1),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Multipart upload iniciado correctamente'),
            new OA\Response(response: 422, description: 'Error de validación'),
            new OA\Response(response: 500, description: 'Error al iniciar el upload'),
        ]
    )]
    public function initiateMultipartUpload(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'operation_id'      => 'required|integer|exists:operations,id',
            'operation_type_id' => 'required|integer',
            'task_id'           => 'required|integer|exists:tasks,id',
            'runway_id'         => 'nullable|integer|exists:runways,id',
            'side'              => 'nullable|string|max:10',
            'filename'          => 'required|string',
            'file_size'         => 'required|integer',
            'file_type'         => 'required|string|in:video,srt,operation_file,image',
            'run'               => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $operationId     = $request->input('operation_id');
            $operationTypeId = $request->input('operation_type_id');
            $taskId          = $request->input('task_id');
            $side            = $request->input('side');

            $folderMapping = Operation::getFolderMapping();
            $baseFolder    = $folderMapping[$operationTypeId] ?? 'Unknown';

            if ($request->has('run')) {
                $run = $request->input('run');
            } else {
                $run = $this->determineNextRun($baseFolder, $operationId, $taskId, $side);
            }

            if ($request->file_type === 'operation_file') {
                $run = null;
            }

            $filePath = $this->buildS3Path(
                $baseFolder,
                $operationId,
                $taskId,
                $run,
                $side,
                $request->input('filename')
            );

            $s3Client = $this->makeS3Client();
            $bucket   = env('AWS_BUCKET');

            $contentType = match ($request->file_type) {
                'video'  => 'video/mp4',
                'srt'    => 'application/x-subrip',
                default  => 'application/octet-stream',
            };

            $result   = $s3Client->createMultipartUpload([
                'Bucket'      => $bucket,
                'Key'         => $filePath,
                'ContentType' => $contentType,
            ]);

            $uploadId = $result['UploadId'];

            return response()->json([
                'success'     => true,
                'upload_id'   => $uploadId,
                's3_path'     => $filePath,
                'run'         => $run,
                'base_folder' => $baseFolder,
            ]);

        } catch (\Exception $e) {
            Log::error('=== INITIATE MULTIPART UPLOAD ERROR ===', [
                'error'       => $e->getMessage(),
                'error_class' => get_class($e),
                'trace'       => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error initiating upload: ' . $e->getMessage(),
            ], 500);
        }
    }

    #[OA\Post(
        path: '/api/ct/s3/multipart/part-url',
        summary: 'Genera una URL pre-firmada (2 h) para subir una parte del Multipart Upload',
        security: [['bearerAuth' => []]],
        tags: ['CT - S3'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['upload_id', 's3_path', 'part_number'],
                properties: [
                    new OA\Property(property: 'upload_id',   type: 'string',  example: 'abc123uploadId'),
                    new OA\Property(property: 's3_path',     type: 'string',  example: 'Lights/1/5/1/left/video.mp4'),
                    new OA\Property(property: 'part_number', type: 'integer', minimum: 1, maximum: 10000, example: 1),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'URL pre-firmada generada'),
            new OA\Response(response: 422, description: 'Error de validación'),
            new OA\Response(response: 500, description: 'Error al generar la URL'),
        ]
    )]
    public function getPartUploadUrl(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'upload_id'   => 'required|string',
            's3_path'     => 'required|string',
            'part_number' => 'required|integer|min:1|max:10000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $s3Client = $this->makeS3Client();
            $bucket   = env('AWS_BUCKET');

            $cmd = $s3Client->getCommand('UploadPart', [
                'Bucket'     => $bucket,
                'Key'        => $request->s3_path,
                'UploadId'   => $request->upload_id,
                'PartNumber' => $request->part_number,
            ]);

            $presignedRequest = $s3Client->createPresignedRequest($cmd, '+2 hours');
            $presignedUrl     = (string) $presignedRequest->getUri();

            return response()->json([
                'success'     => true,
                'upload_url'  => $presignedUrl,
                'part_number' => $request->part_number,
            ]);

        } catch (\Exception $e) {
            Log::error('Error generating part upload URL', [
                'error'       => $e->getMessage(),
                'part_number' => $request->part_number,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error generating upload URL: ' . $e->getMessage(),
            ], 500);
        }
    }

    #[OA\Post(
        path: '/api/ct/s3/multipart/complete',
        summary: 'Completa un Multipart Upload en S3 ensamblando todas las partes',
        security: [['bearerAuth' => []]],
        tags: ['CT - S3'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['upload_id', 's3_path', 'parts'],
                properties: [
                    new OA\Property(property: 'upload_id', type: 'string', example: 'abc123uploadId'),
                    new OA\Property(property: 's3_path',   type: 'string', example: 'Lights/1/5/1/left/video.mp4'),
                    new OA\Property(
                        property: 'parts',
                        type: 'array',
                        items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'PartNumber', type: 'integer', example: 1),
                                new OA\Property(property: 'ETag',       type: 'string',  example: '"abc123etag"'),
                            ]
                        )
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Upload completado correctamente'),
            new OA\Response(response: 422, description: 'Error de validación'),
            new OA\Response(response: 500, description: 'Error al completar el upload'),
        ]
    )]
    public function completeMultipartUpload(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'upload_id'           => 'required|string',
            's3_path'             => 'required|string',
            'parts'               => 'required|array',
            'parts.*.PartNumber'  => 'required|integer',
            'parts.*.ETag'        => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $s3Client = $this->makeS3Client();
            $bucket   = env('AWS_BUCKET');

            $result = $s3Client->completeMultipartUpload([
                'Bucket'          => $bucket,
                'Key'             => $request->s3_path,
                'UploadId'        => $request->upload_id,
                'MultipartUpload' => [
                    'Parts' => $request->parts,
                ],
            ]);

            return response()->json([
                'success'  => true,
                'message'  => 'Upload completed successfully',
                's3_path'  => $request->s3_path,
                'location' => $result['Location'] ?? null,
            ]);

        } catch (\Exception $e) {
            Log::error('=== COMPLETE MULTIPART UPLOAD ERROR ===', [
                'error'       => $e->getMessage(),
                'error_class' => get_class($e),
                'trace'       => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error completing upload: ' . $e->getMessage(),
            ], 500);
        }
    }

    // =========================================================================
    //  Helpers privados
    // =========================================================================

    private function makeS3Client(): S3Client
    {
        return new S3Client([
            'version'     => 'latest',
            'region'      => env('AWS_DEFAULT_REGION'),
            'credentials' => [
                'key'    => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
            ],
        ]);
    }

    private function buildS3Path(
        string $baseFolder,
        int    $operationId,
        int    $taskId,
        ?int   $run,
        ?string $side,
        string $filename
    ): string {
        $parts = [$baseFolder, $operationId, $taskId];

        if ($run) {
            $parts[] = $run;
        }

        if ($side) {
            $parts[] = $side;
        }

        $parts[] = $filename;

        return implode('/', $parts);
    }

    private function determineNextRun(
        string  $baseFolder,
        int     $operationId,
        int     $taskId,
        ?string $side = null
    ): int {
        $basePath = "{$baseFolder}/{$operationId}/{$taskId}";

        try {
            $directories = Storage::disk('s3')->directories($basePath);

            if (empty($directories)) {
                return 1;
            }

            $maxRun = 0;

            foreach ($directories as $dir) {
                $runNumber = (int) basename($dir);

                if ($side) {
                    $sidePath = "{$dir}/{$side}";
                    if (Storage::disk('s3')->exists($sidePath)) {
                        $maxRun = max($maxRun, $runNumber);
                    }
                } else {
                    $maxRun = max($maxRun, $runNumber);
                }
            }

            return $maxRun + 1;

        } catch (\Exception $e) {
            Log::warning('Error determining next run, defaulting to 1', [
                'error' => $e->getMessage(),
            ]);

            return 1;
        }
    }
}
