<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Platform operations, from the command line on purpose: there is no
 * platform-wide admin API to attack.
 *
 *   php artisan stores list
 *   php artisan stores suspend bella
 *   php artisan stores activate bella
 */
#[Signature('stores {action : list, suspend or activate} {slug? : The store, for suspend/activate}')]
#[Description('List stores, or suspend and reactivate one')]
final class ManageStores extends Command
{
    public function handle(): int
    {
        return match ($this->argument('action')) {
            'list' => $this->list(),
            'suspend' => $this->setStatus(TenantStatus::Suspended),
            'activate' => $this->setStatus(TenantStatus::Active),
            default => $this->fail('Unknown action: use list, suspend or activate.'),
        };
    }

    private function list(): int
    {
        $this->table(
            ['Slug', 'Name', 'Status', 'Payments', 'Orders', 'Created'],
            Tenant::query()->orderBy('created_at')->get()->map(fn (Tenant $t): array => [
                $t->slug,
                $t->name,
                $t->status->value,
                $t->stripe_charges_enabled ? 'enabled' : ($t->stripe_account_id ? 'onboarding' : '-'),
                $t->last_order_number,
                $t->created_at->toDateString(),
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function setStatus(TenantStatus $status): int
    {
        $slug = $this->argument('slug');
        $store = is_string($slug) ? Tenant::query()->where('slug', $slug)->first() : null;

        if ($store === null) {
            $this->error('Store not found.');

            return self::FAILURE;
        }

        $store->update(['status' => $status]);
        $this->info("{$store->slug} is now {$status->value}.");

        return self::SUCCESS;
    }
}
