<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

class ResetPasswordController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->middleware('guest');
    }

    #[OA\Post(
        path: '/api/auth/reset-password',
        summary: 'Restablece la contraseña usando el token recibido por email',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['token', 'email', 'password', 'password_confirmation'],
                properties: [
                    new OA\Property(property: 'token', type: 'string', example: 'abc123token'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'john@example.com'),
                    new OA\Property(property: 'password', type: 'string', minLength: 6, example: 'nuevaPassword123'),
                    new OA\Property(property: 'password_confirmation', type: 'string', example: 'nuevaPassword123'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Contraseña restablecida correctamente'),
            new OA\Response(response: 422, description: 'Token inválido, expirado o error de validación'),
        ]
    )]
    public function reset(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'token'    => 'required|string',
            'email'    => 'required|string|email',
            'password' => 'required|string|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Error de validación',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill([
                    'password'       => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
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
