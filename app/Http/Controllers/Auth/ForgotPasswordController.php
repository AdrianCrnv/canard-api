<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

class ForgotPasswordController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->middleware('guest');
    }

    #[OA\Post(
        path: '/api/auth/forgot-password',
        summary: 'Envía el enlace de restablecimiento de contraseña al correo indicado',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'john@example.com'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Enlace de restablecimiento enviado'),
            new OA\Response(response: 422, description: 'Error de validación o email no encontrado'),
        ]
    )]
    public function sendResetLinkEmail(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Error de validación',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $status = Password::sendResetLink(
            $request->only('email')
        );

        if ($status === Password::RESET_LINK_SENT) {
            return response()->json([
                'message' => __($status),
            ], 200);
        }

        return response()->json([
            'message' => 'Error de validación',
            'errors'  => ['email' => [__($status)]],
        ], 422);
    }
}
