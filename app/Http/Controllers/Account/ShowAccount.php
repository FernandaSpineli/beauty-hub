<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Resources\UserResource;
use Illuminate\Http\Request;

final class ShowAccount
{
    public function __invoke(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
