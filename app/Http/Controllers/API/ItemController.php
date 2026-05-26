<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Item;
use App\ItemCategory;
use App\ItemType;
use App\Drone;
use App\Operator;
use App\Maintenance;
use App\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use OpenApi\Attributes as OA;

class ItemController extends Controller
{
    #[OA\Get(
        path: '/api/inventory',
        summary: 'List inventory items with optional filters',
        security: [['bearerAuth' => []]],
        tags: ['Inventory'],
        parameters: [
            new OA\Parameter(name: 'operator',     in: 'query', required: false, schema: new OA\Schema(type: 'string'),  description: 'Filter by operator name'),
            new OA\Parameter(name: 'operator_id',  in: 'query', required: false, schema: new OA\Schema(type: 'integer'), description: 'Filter by operator ID'),
            new OA\Parameter(name: 'category_id',  in: 'query', required: false, schema: new OA\Schema(type: 'integer'), description: 'Filter by item category ID'),
            new OA\Parameter(name: 'per_page',     in: 'query', required: false, schema: new OA\Schema(type: 'integer'), description: 'Items per page (default 50)'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated item list'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('inventory_view');

        $user          = Auth::user();
        $viewAllItems  = $user->roles->contains('id', 6);
        $operatorId    = null;

        if ($request->filled('operator')) {
            $operator   = Operator::where('name', $request->operator)->first();
            $operatorId = $operator?->id;
        } elseif ($request->filled('operator_id')) {
            $operatorId = (int) $request->operator_id;
        } elseif (!$viewAllItems) {
            $operatorId = $user->operator->id;
        }

        $query = Item::query();

        if ($operatorId) {
            $query->where('operator_id', $operatorId);
        }

        if ($request->filled('category_id')) {
            $typeIds = ItemCategory::findOrFail($request->category_id)->types()->pluck('id');
            $query->whereIn('type_id', $typeIds);
        }

        $items      = $query->sortable('type_id')->paginate($request->integer('per_page', 50));
        $categories = $this->buildCategoryList($query->get());

        return response()->json([
            'items'              => $items,
            'categories'         => $categories->values(),
            'operator_options'   => $this->operatorSelect($query->get()),
            'operator_id_filter' => $operatorId,
        ]);
    }

    #[OA\Get(
        path: '/api/inventory/{item}',
        summary: 'Get a single inventory item',
        security: [['bearerAuth' => []]],
        tags: ['Inventory'],
        parameters: [
            new OA\Parameter(name: 'item', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Item data'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(Item $item): JsonResponse
    {
        $this->authorize('inventory_view');

        // Sync operator_id from drone for all items that have a drone assigned
        Item::whereNotNull('drone_id')->get()->each(function (Item $currentItem) {
            $drone = Drone::find($currentItem->drone_id);
            if ($drone && $drone->operator_id && Operator::find($drone->operator_id)) {
                $currentItem->update(['operator_id' => $drone->operator_id]);
            }
        });

        return response()->json($item->load(['type.category', 'drone', 'provider', 'status', 'operator', 'firmware_version']));
    }

    #[OA\Post(
        path: '/api/inventory',
        summary: 'Create a new inventory item',
        security: [['bearerAuth' => []]],
        tags: ['Inventory'],
        responses: [
            new OA\Response(response: 201, description: 'Item created'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('inventory_create');

        $request->validate([
            'serial_number' => 'required|unique:items',
            'type_id'       => 'required',
            'provider_id'   => 'required',
            'status_id'     => 'required',
            'currency_id'   => 'required_with:price',
            'price'         => 'nullable|numeric|between:0,999999.99',
            'operator_id'   => 'required|numeric',
        ]);

        try {
            $item = Item::create([
                'type_id'             => $request->type_id,
                'drone_id'            => $request->drone_id,
                'serial_number'       => $request->serial_number,
                'provider_id'         => $request->provider_id,
                'currency_id'         => $request->currency_id,
                'operator_id'         => $request->operator_id,
                'price'               => $request->price,
                'firmware_version_id' => $request->firmware_version_id,
                'status_id'           => $request->status_id,
                'notes'               => $request->notes,
                'commission_date'     => Carbon::now(),
            ]);

            ActivityLog::log('create', 'Item', $item->id, "New item '{$item->type->name}' (S/N: {$item->serial_number})");

        } catch (\Exception $e) {
            ActivityLog::log('error', 'Item', null, 'Error in store: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }

        return response()->json($item->load(['type', 'provider', 'status', 'operator']), 201);
    }

    #[OA\Put(
        path: '/api/inventory/{item}',
        summary: 'Update an inventory item',
        security: [['bearerAuth' => []]],
        tags: ['Inventory'],
        parameters: [
            new OA\Parameter(name: 'item', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Item updated'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function update(Request $request, Item $item): JsonResponse
    {
        $this->authorize('inventory_edit');

        $request->validate([
            'serial_number' => 'required|unique:items,serial_number,' . $item->id,
            'provider_id'   => 'required',
            'status_id'     => 'required',
            'currency_id'   => 'required_with:price',
        ]);

        $oldStatusId = $item->status_id;

        try {
            $before = $this->snapshotItem($item);

            if ($item->status_id != 2 && $request->status_id == 2) {
                $item->decommission_date = Carbon::now();
            }

            $item->drone_id            = $request->drone_id;
            $item->serial_number       = $request->serial_number;
            $item->provider_id         = $request->provider_id;
            $item->currency_id         = $request->currency_id;
            $item->price               = $request->price;
            $item->firmware_version_id = $request->firmware_version_id;
            $item->status_id           = $request->status_id;
            $item->notes               = $request->notes;
            $item->operator_id         = $request->operator_id;

            if ($oldStatusId != $request->status_id) {
                $this->syncMaintenancesOnStatusChange($item, (int) $request->status_id);
            }

            $item->save();
            $item->load('type', 'drone', 'provider', 'status', 'operator');

            $after   = $this->snapshotItem($item);
            $changes = [];
            foreach ($before as $field => $oldVal) {
                if ((string) $oldVal !== (string) $after[$field]) {
                    $changes[] = "{$field}: '{$oldVal}' → '{$after[$field]}'";
                }
            }

            $description = "Updated item '{$item->type->name}' (S/N: {$item->serial_number})"
                . (count($changes) > 0 ? ': ' . implode(', ', $changes) : '');
            ActivityLog::log('update', 'Item', $item->id, $description);

        } catch (\Exception $e) {
            ActivityLog::log('error', 'Item', null, 'Error in update: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }

        return response()->json($item);
    }

    #[OA\Delete(
        path: '/api/inventory/{item}',
        summary: 'Delete an inventory item',
        security: [['bearerAuth' => []]],
        tags: ['Inventory'],
        parameters: [
            new OA\Parameter(name: 'item', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Item deleted'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function destroy(Item $item): JsonResponse
    {
        $this->authorize('inventory_delete');

        try {
            $typeName     = $item->type->name;
            $serialNumber = $item->serial_number;
            $itemId       = $item->id;

            $item->delete();

            ActivityLog::log('delete', 'Item', $itemId, "Deleted item '{$typeName}' (S/N: {$serialNumber})");

        } catch (\Exception $e) {
            ActivityLog::log('error', 'Item', null, 'Error in delete: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }

        return response()->json(['message' => 'Item deleted successfully']);
    }

    #[OA\Get(
        path: '/api/item-types/{type}/firmware-versions',
        summary: 'Get firmware versions for an item type',
        security: [['bearerAuth' => []]],
        tags: ['Inventory'],
        parameters: [
            new OA\Parameter(name: 'type', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Firmware versions list'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function getFirmwareVersions(ItemType $type): JsonResponse
    {
        return response()->json($type->firmware_versions()->get());
    }

    // ---------------------------------------------------------------
    // Private helpers
    // ---------------------------------------------------------------

    private function syncMaintenancesOnStatusChange(Item $item, int $newStatusId): void
    {
        if ($newStatusId === 2) { // Decommissioned → cancel pending maintenances
            Maintenance::where('status_id', 1)
                ->where('subject_type', $item->type_id)
                ->where('subject_id', $item->id)
                ->get()
                ->each(fn($m) => $m->update(['status_id' => 4]));

        } elseif ($newStatusId === 1) { // Operational → reactivate most recent cancelled
            $mtn = Maintenance::where('status_id', 4)
                ->where('subject_type', $item->type_id)
                ->where('subject_id', $item->id)
                ->orderByDesc('execution_date')
                ->first();
            $mtn?->update(['status_id' => 1]);

        } elseif ($newStatusId === 3) { // In Repair → suspend pending maintenances
            Maintenance::where('status_id', 1)
                ->where('subject_type', $item->type_id)
                ->where('subject_id', $item->id)
                ->get()
                ->each(fn($m) => $m->update(['status_id' => 3]));
        }
    }

    private function snapshotItem(Item $item): array
    {
        return [
            'Serial number' => $item->serial_number,
            'Type'          => $item->type->name    ?? $item->type_id,
            'Drone'         => $item->drone->name   ?? null,
            'Provider'      => $item->provider->name ?? $item->provider_id,
            'Status'        => $item->status->name   ?? $item->status_id,
            'Operator'      => $item->operator->name ?? $item->operator_id,
            'Price'         => $item->price,
            'Notes'         => $item->notes,
        ];
    }

    private function buildCategoryList($items): \Illuminate\Support\Collection
    {
        return $items->reduce(function ($carry, $item) {
            $cat = $item->type->category;
            if (!$carry->contains($cat)) $carry->push($cat);
            return $carry;
        }, collect())->sortBy('name');
    }

    private function operatorSelect($items): array
    {
        $names = $items->filter(fn($i) => isset($i->operator->name))->map(fn($i) => $i->operator->name)->toArray();
        return array_count_values($names);
    }
}
