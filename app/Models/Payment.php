<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Stripe Checkout session opened for an order.
 *
 * @property string $id
 * @property string $order_id
 * @property string $checkout_session_id
 * @property string|null $payment_intent_id
 * @property string $checkout_url
 * @property PaymentStatus $status
 * @property int $amount_cents
 * @property int $application_fee_cents
 * @property Carbon $expires_at
 * @property string|null $note
 * @property-read Order $order
 */
final class Payment extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['id', 'tenant_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount_cents' => 'integer',
            'application_fee_cents' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
