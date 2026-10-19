<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\AdjustmentReason;
use App\Http\Resources\StockLotResource;
use App\Inventory\StockLedger;
use App\Models\ProductVariant;
use App\Models\StockLot;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

final class InventoryController
{
    public function __construct(private readonly StockLedger $ledger) {}

    /**
     * Sellable units and every lot of a variant, soonest expiry first.
     */
    public function show(ProductVariant $variant): JsonResponse
    {
        $stock = $variant->stock()->first();

        return new JsonResponse(['data' => [
            'variant_id' => $variant->id,
            'available' => $stock->available ?? 0,
            'next_expiry' => $stock?->next_expiry?->toDateString(),
            'lots' => StockLotResource::collection(
                $variant->lots()->orderBy('expires_on')->orderBy('id')->get(),
            )->resolve(),
        ]]);
    }

    public function receive(Request $request, ProductVariant $variant): JsonResponse
    {
        /** @var array{lot_code: string, expires_on: string, quantity: int} $data */
        $data = $request->validate([
            'lot_code' => ['required', 'string', 'max:40'],
            'expires_on' => ['required', 'date_format:Y-m-d', 'after:today'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);

        $lot = $this->ledger->receive(
            $variant,
            $data['lot_code'],
            Carbon::parse($data['expires_on']),
            (int) $data['quantity'],
            $this->user($request),
        );

        return (new StockLotResource($lot))->response()->setStatusCode(201);
    }

    public function adjust(Request $request, StockLot $lot): StockLotResource
    {
        /** @var array{delta: int, reason: string, note?: string} $data */
        $data = $request->validate([
            'delta' => ['required', 'integer', 'not_in:0', 'min:-100000', 'max:100000'],
            'reason' => ['required', Rule::enum(AdjustmentReason::class)],
            'note' => ['sometimes', 'string', 'max:150'],
        ]);

        return new StockLotResource($this->ledger->adjust(
            $lot,
            (int) $data['delta'],
            AdjustmentReason::from($data['reason']),
            $data['note'] ?? null,
            $this->user($request),
        ));
    }

    /**
     * Lots with units left that expire within the given window, including
     * those already past the store's minimum shelf life (no longer sold).
     */
    public function expiring(Request $request): AnonymousResourceCollection
    {
        $request->validate(['within_days' => ['sometimes', 'integer', 'min:1', 'max:365']]);

        $lots = StockLot::query()
            ->with('variant.product')
            ->where('quantity_on_hand', '>', 0)
            ->where('expires_on', '<=', today()->addDays($request->integer('within_days', 60)))
            ->orderBy('expires_on')
            ->orderBy('id')
            ->get();

        return StockLotResource::collection($lots);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
