<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Turns "still referenced" database errors into a 409 the client can show.
 */
final class ForeignKeyGuard
{
    /** foreign_key_violation (NO ACTION) and restrict_violation (RESTRICT). */
    private const array STILL_REFERENCED = ['23503', '23001'];

    public static function delete(Closure $delete, string $code, string $message): void
    {
        try {
            // A savepoint when nested: the failed DELETE must not poison an
            // enclosing transaction.
            DB::transaction(fn () => $delete());
        } catch (QueryException $e) {
            if (in_array($e->getCode(), self::STILL_REFERENCED, true)) {
                throw new ApiException(409, $code, $message);
            }

            throw $e;
        }
    }
}
