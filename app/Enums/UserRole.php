<?php

declare(strict_types=1);

namespace App\Enums;

enum UserRole: string
{
    /** Runs the store: everything staff can do, plus payments and team. */
    case Owner = 'owner';

    /** Manages catalog, stock and orders. */
    case Staff = 'staff';

    /** Shops at the store. */
    case Customer = 'customer';

    public function managesStore(): bool
    {
        return $this !== self::Customer;
    }
}
