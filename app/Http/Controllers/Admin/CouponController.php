<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\CouponKind;
use App\Exceptions\ApiException;
use App\Http\Resources\CouponResource;
use App\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Coupons that have been used are deactivated, not deleted, so past orders
 * keep their reference.
 */
final class CouponController
{
    public function index(): AnonymousResourceCollection
    {
        return CouponResource::collection(Coupon::query()->latest()->get());
    }

    public function store(Request $request): CouponResource
    {
        return new CouponResource(Coupon::query()->create($this->validated($request)));
    }

    public function update(Request $request, Coupon $coupon): CouponResource
    {
        $coupon->update($this->validated($request, $coupon));

        return new CouponResource($coupon);
    }

    public function destroy(Coupon $coupon): Response
    {
        if ($coupon->times_redeemed > 0) {
            throw new ApiException(409, 'COUPON_IN_USE', 'This coupon has been used; deactivate it instead.');
        }

        $coupon->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Coupon $coupon = null): array
    {
        $required = $coupon ? 'sometimes' : 'required';
        if (is_string($request->input('code'))) {
            $request->merge(['code' => mb_strtoupper(trim($request->input('code')))]);
        }
        $kind = $request->input('kind', $coupon?->kind->value);

        /** @var array<string, mixed> */
        return $request->validate([
            'code' => [$required, 'string', 'regex:/\A[A-Z0-9]{3,30}\z/', Rule::unique('coupons', 'code')->ignore($coupon?->id)],
            'kind' => [$required, Rule::enum(CouponKind::class)],
            'value' => [$required, 'integer', 'min:1', $kind === CouponKind::Percent->value ? 'max:100' : 'max:100000000'],
            'min_subtotal_cents' => ['sometimes', 'integer', 'min:0'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after:starts_at'],
            'max_redemptions' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'max_per_customer' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
