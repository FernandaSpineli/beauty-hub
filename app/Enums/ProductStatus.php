<?php

declare(strict_types=1);

namespace App\Enums;

enum ProductStatus: string
{
    /** Being prepared; not visible in the storefront. */
    case Draft = 'draft';

    case Active = 'active';

    /** No longer sold; kept for past orders. */
    case Archived = 'archived';
}
