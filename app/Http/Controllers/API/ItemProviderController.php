<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\ItemProvider;
use App\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ItemProviderController extends Controller
{
    #[OA\Get(
        path: '/api/item-providers',
        summary: 'List all item providers',
        security: [['bearerAuth' => []]],
        tags: ['Inventory'],
        responses: [
            new OA\Response(response: 200, description: 'Providers list'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function index(): JsonResponse
    {
        $this->authorize('inventory_view');

        return response()->json(ItemProvider::orderBy('name')->get());
    }

    #[OA\Post(
        path: '/api/item-providers',
        summary: 'Create a new item provider',
        security: [['bearerAuth' => []]],
        tags: ['Inventory'],
        responses: [
            new OA\Response(response: 201, description: 'Provider created'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'provider' => 'required|string',
        ]);

        try {
            $provider = ItemProvider::create(['name' => $request->provider]);

            ActivityLog::log('create', 'Item Provider', $provider->id, "New item provider '{$provider->name}'");

        } catch (\Exception $e) {
            ActivityLog::log('error', 'Item Provider', null, 'Error in storeItemProvider: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }

        return response()->json($provider, 201);
    }
}
