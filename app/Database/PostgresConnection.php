<?php

declare(strict_types=1);

namespace App\Database;

use Illuminate\Database\PostgresConnection as BaseConnection;
use Illuminate\Database\Query\Grammars\PostgresGrammar;

/**
 * Sends dates to PostgreSQL with their UTC offset.
 *
 * Laravel formats dates as "Y-m-d H:i:s" with no offset, in whatever time
 * zone the Carbon instance happens to have. A date parsed from
 * "2026-12-01T03:00:00Z" would then be stored three hours off, read by
 * PostgreSQL as local (America/Sao_Paulo) time. With the offset included
 * the stored instant is always the intended one. Values read back carry an
 * offset too ("2026-12-01 00:00:00-03"), which Eloquent parses as usual.
 */
final class PostgresConnection extends BaseConnection
{
    protected function getDefaultQueryGrammar(): PostgresGrammar
    {
        return new class($this) extends PostgresGrammar
        {
            public function getDateFormat(): string
            {
                return 'Y-m-d H:i:sP';
            }
        };
    }
}
