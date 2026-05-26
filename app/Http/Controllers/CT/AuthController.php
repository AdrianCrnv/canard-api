<?php

namespace App\Http\Controllers\CT;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use OpenApi\Attributes as OA;

class AuthController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum')->only(['logout', 'status']);
    }

    #[OA\Post(
        path: '/api/ct/login',
        summary: 'Autentica al usuario y devuelve el token de acceso (Calibration Tool)',
        tags: ['CT Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(property: 'email',    type: 'string', format: 'email'),
                    new OA\Property(property: 'password', type: 'string', format: 'password'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Login correcto, devuelve nombre, email, operador, estado, timestamp y token'),
            new OA\Response(response: 401, description: 'Credenciales incorrectas'),
        ]
    )]
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->only('email', 'password');

        if (Auth::attempt($credentials)) {
            $user  = Auth::user();
            $token = $user->createToken('Calibration Tool App')->plainTextToken;

            return response()->json([
                'name'      => $user->name,
                'email'     => $user->email,
                'operator'  => $user->operator->name,
                'is_active' => $user->is_active,
                'timestamp' => time(),
                'token'     => $token,
            ]);
        }

        return response()->json(['message' => 'Incorrect user or password'], 401);
    }

    #[OA\Post(
        path: '/api/ct/logout',
        summary: 'Cierra la sesión del usuario e invalida su token actual',
        security: [['bearerAuth' => []]],
        tags: ['CT Auth'],
        responses: [
            new OA\Response(response: 200, description: 'Sesión cerrada correctamente'),
            new OA\Response(response: 401, description: 'No autenticado'),
        ]
    )]
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'User logged out'], 200);
    }

    #[OA\Get(
        path: '/api/ct/status',
        summary: 'Devuelve los datos del usuario autenticado si el token es válido',
        security: [['bearerAuth' => []]],
        tags: ['CT Auth'],
        responses: [
            new OA\Response(response: 200, description: 'Datos del usuario autenticado'),
            new OA\Response(response: 401, description: 'Token inválido o expirado'),
        ]
    )]
    public function status(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }
}
