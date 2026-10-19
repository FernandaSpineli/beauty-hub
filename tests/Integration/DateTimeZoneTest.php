<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

it('stores the intended instant whatever the time zone of the date object', function (string $input): void {
    $stored = DB::scalar('SELECT extract(epoch FROM ?::timestamptz)::bigint', [Carbon::parse($input)]);

    expect((int) $stored)->toBe(Carbon::parse('2026-12-01T03:00:00Z')->getTimestamp());
})->with([
    'UTC' => '2026-12-01T03:00:00Z',
    'Brasília' => '2026-12-01T00:00:00-03:00',
    'Lisbon' => '2026-12-01T03:00:00+00:00',
    'Tokyo' => '2026-12-01T12:00:00+09:00',
]);

it('reads dates back as the same instant', function (): void {
    $value = DB::scalar("SELECT '2026-12-01 00:00:00-03'::timestamptz");
    $model = new class extends Model
    {
        protected $casts = ['at' => 'datetime'];
    };
    $model->setRawAttributes(['at' => $value]);

    expect($model->getAttribute('at')->getTimestamp())->toBe(Carbon::parse('2026-12-01T03:00:00Z')->getTimestamp());
});
