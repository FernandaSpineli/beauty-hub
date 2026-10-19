<?php

declare(strict_types=1);

use App\Inventory\StockAllocator;

it('refuses to change stock outside the caller\'s transaction', function (string $method): void {
    $allocator = app(StockAllocator::class);
    $call = $method === 'reserve'
        ? fn () => $allocator->reserve(['0199f0c4-0000-7000-8000-000000000001' => 1], 'test')
        : fn () => $allocator->{$method}([], 'test');

    expect($call)->toThrow(LogicException::class, 'transaction');
})->with(['reserve', 'release', 'sell']);
