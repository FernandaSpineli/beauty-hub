<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/**
 * Sanctum's token, stored per store. Lookups run under the request's tenant,
 * so another store's token is never found.
 */
final class PersonalAccessToken extends SanctumToken
{
    use BelongsToTenant, HasUuids;
}
