<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

class RegisterController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->middleware('guest');
    }

    #[OA\Post(
        path: '/api/auth/register',
        summary: 'Registro de nuevo usuario',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'email', 'password', 'password_confirmation'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'John Doe'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', maxLength: 255, example: 'john@example.com'),
                    new OA\Property(property: 'password', type: 'string', minLength: 6, example: 'secret123'),
                    new OA\Property(property: 'password_confirmation', type: 'string', example: 'secret123'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Usuario registrado correctamente'),
            new OA\Response(response: 422, description: 'Error de validación'),
        ]
    )]
    public function register(Request $request): JsonResponse
    {
        $validator = $this->validator($request->all());

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Error de validación',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $user = $this->create($request->all());

        return response()->json([
            'message' => 'Usuario registrado correctamente',
            'user'    => $user,
        ], 201);
    }

    /**
     * Get a validator for an incoming registration request.
     */
    protected function validator(array $data): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($data, [
            'name'     => 'required|string|max:255',
            'email'    => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6|confirmed',
        ]);
    }

    /**
     * Create a new user instance after a valid registration.
     */
    protected function create(array $data): User
    {
        return User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => Hash::make($data['password']),
        ]);
    }
}
