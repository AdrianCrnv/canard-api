<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\ItemCategory;
use App\ItemType;
use App\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class ItemTypeController extends Controller
{
    #[OA\Get(
        path: '/api/item-types',
        summary: 'List all item types',
        security: [['bearerAuth' => []]],
        tags: ['Inventory'],
        responses: [
            new OA\Response(response: 200, description: 'Item types list'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function index(): JsonResponse
    {
        $this->authorize('inventory_view');

        return response()->json(ItemType::with('category')->orderBy('name')->get());
    }

    #[OA\Post(
        path: '/api/item-types',
        summary: 'Create a new item type (optionally creating a new category)',
        security: [['bearerAuth' => []]],
        tags: ['Inventory'],
        responses: [
            new OA\Response(response: 201, description: 'Item type created'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('inventory_create');

        $request->validate([
            'itemCategory' => 'required',
            'item'         => 'required',
            'nameCategory' => 'sometimes|required|unique:item_categories,name',
            'maintenance'  => 'required',
            'mtn_value'    => 'nullable',
            'mtn_unit_id'  => 'nullable',
        ]);

        try {
            $category = null;
            if ($request->has('nameCategory')) {
                $category = ItemCategory::create(['name' => $request->nameCategory]);
            }

            $periodOptionId = null;
            if ($request->maintenance === 'yes' && $request->filled('period_option')) {
                $row = DB::table('time_units')->where('id', $request->period_option)->first();
                $periodOptionId = $row?->id;
            }

            $itemType = ItemType::create([
                'name'        => $request->item,
                'category_id' => $category->id ?? $request->itemCategory,
                'maintenance' => $request->maintenance === 'yes' ? 1 : 0,
                'mtn_value'   => $request->period_num  !== '' ? $request->period_num  : null,
                'mtn_unit_id' => $periodOptionId,
            ]);

            ActivityLog::log('create', 'Item Type', $itemType->id, "New item type '{$itemType->name}'");

        } catch (\Exception $e) {
            ActivityLog::log('error', 'Item Type', null, 'Error in storeItemType: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }

        return response()->json($itemType->load('category'), 201);
    }
}
