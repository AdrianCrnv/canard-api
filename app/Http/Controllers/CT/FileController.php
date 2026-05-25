<?php

namespace App\Http\Controllers\CT;

use App\Http\Controllers\Controller;
use App\Models\Operation;
use App\Models\OperationType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class FileController extends Controller
{
    #[OA\Post(
        path: '/api/ct/operations/{operation}/files',
        summary: 'Sube uno o varios archivos a AWS S3 asociados a una operación',
        security: [['bearerAuth' => []]],
        tags: ['CT - Files'],
        parameters: [
            new OA\Parameter(name: 'operation', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    properties: [
                        new OA\Property(property: 'file', type: 'array', items: new OA\Items(type: 'string', format: 'binary')),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Archivos guardados correctamente'),
            new OA\Response(response: 400, description: 'Error al guardar los archivos'),
        ]
    )]
    public function uploadImageAWS(Request $request): JsonResponse
    {
        $code = 400;
        $isCorrect = false;

        /* Request file is an array of files */
        foreach ($request->file as $file) {
            $name         = $file->getClientOriginalName(); // Get the file name
            $mime_type    = $file->getClientMimeType();     // Get the mime type of each file
            $size         = $file->getSize();               // Get the size of each file
            $id_operation = $request->route('operation');

            $newFile = $this->uploadDataImage($file, $mime_type, $size, $id_operation);
        }

        if ($isCorrect) {
            return response()->json([
                'message' => 'Saved correctly',
            ], 200);
        }

        return response()->json([
            'message' => 'Error',
        ], 400);
    }

    #[OA\Get(
        path: '/api/ct/files/check',
        summary: 'Comprueba si un archivo ya existe en la MediaLibrary',
        security: [['bearerAuth' => []]],
        tags: ['CT - Files'],
        responses: [
            new OA\Response(response: 200, description: 'Resultado de la comprobación'),
        ]
    )]
    public function checkExistingFileAWS($file): mixed
    {
        $name        = $file->getClientOriginalName(); // get name of file
        $name_forDB  = explode('.', $file->getClientOriginalName()); // Split to get name without extension

        $exists = Media::where([
            'file_name' => $name,
            'name'      => $name_forDB[0],
        ])->first();

        return $exists;
    }

    public function uploadDataImage($file, $mime_type, $size, $id): mixed
    {
        $name       = $file->getClientOriginalName(); // get name of file
        $name_forDB = explode('.', $file->getClientOriginalName()); // Split to get name without extension
        $id_op      = $id;

        $operation   = Operation::find($id_op);
        $model_type  = OperationType::find($operation->type_id);
        $model_typeDB = 'App\Operation';

        $exists = Media::where([
            'name' => $name_forDB[0],
        ])->first();

        if ($exists) {
            $existingId = strval($exists->id);
            Storage::disk('s3')->delete($existingId . '/' . $name);
            $exists->delete();
        }

        return $new_file;
    }
}
