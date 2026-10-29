<?php

namespace App\Message;

use App\Entity\ListingSource;

class FetchAllListingsMessage
{
    public function __construct(
        public readonly ListingSource $source,
    ) {
    }

}