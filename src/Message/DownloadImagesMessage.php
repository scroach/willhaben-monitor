<?php

namespace App\Message;

class DownloadImagesMessage
{
    public function __construct(
        public readonly int $listingId
    ) {
    }
}