<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

class LoginController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->middleware('guest')->except('logout');
    }

    #[OA\Post(
        path: '/api/auth/login',
        summary: 'Inicio de sesión y obtención de token Sanctum',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'john@example.com'),
                    new OA\Property(property: 'password', type: 'string', example: 'secret123'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Login correcto, token generado'),
            new OA\Response(response: 401, description: 'Credenciales incorrectas o usuario inactivo'),
            new OA\Response(response: 422, description: 'Error de validación'),
        ]
    )]
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email'    => 'required|string|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Error de validación',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // Verifica credenciales incluyendo que el usuario esté activo
        if (!Auth::attempt($this->credentials($request))) {
            return response()->json([
                'message' => 'Credenciales incorrectas o usuario inactivo',
            ], 401);
        }

        /** @var User $user */
        $user  = Auth::user();
        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'message' => 'Login correcto',
            'token'   => $token,
            'user'    => $user,
        ], 200);
    }

    #[OA\Post(
        path: '/api/auth/logout',
        summary: 'Cierre de sesión y revocación del token actual',
        security: [['bearerAuth' => []]],
        tags: ['Auth'],
        responses: [
            new OA\Response(response: 200, description: 'Sesión cerrada correctamente'),
            new OA\Response(response: 401, description: 'No autenticado'),
        ]
    )]
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Sesión cerrada correctamente',
        ], 200);
    }

    /**
     * Get the needed authorization credentials from the request.
     * Incluye la comprobación de is_active para rechazar usuarios inactivos.
     */
    protected function credentials(Request $request): array
    {
        return [
            'email'     => $request->email,
            'password'  => $request->password,
            'is_active' => 1,
        ];
    }
}
