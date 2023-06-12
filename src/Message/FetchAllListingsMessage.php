<?php

namespace App\Message;

class FetchAllListingsMessage
{
    public function __construct(
        private readonly string $message
    ) {
    }

    public function getMessage(): string
    {
        return $this->message;
    }

}