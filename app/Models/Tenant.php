<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TenantStatus;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A store on the platform, reached at {slug}.{central domain}.
 *
 * @property string $id
 * @property string $slug
 * @property string $name
 * @property TenantStatus $status
 * @property int $min_shelf_life_days
 * @property string|null $stripe_account_id
 * @property bool $stripe_charges_enabled
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['slug', 'name', 'status', 'min_shelf_life_days'])]
final class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, HasUuids;

    /**
     * Mirrors the column defaults, so a freshly created store has them.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
        'min_shelf_life_days' => 30,
        'stripe_charges_enabled' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'min_shelf_life_days' => 'integer',
            'stripe_charges_enabled' => 'boolean',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === TenantStatus::Active;
    }
}
