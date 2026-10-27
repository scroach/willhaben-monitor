<?php

declare(strict_types=1);

namespace App\Entity;

enum ListingSource: string
{
    case WILLHABEN = 'willhaben';
    case IMMOBILIENSCOUT24 = 'immobilienscout24';
}
