<?php

namespace App\Message;

class ScrapeMessage
{

    public function __construct(
        private string $message
    ) {
    }

    public function getMessage(): string
    {
        return $this->message;
    }

}