<?php

namespace App\MessageHandler;

use App\Message\ScrapeMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class ScrapeMessageHandler
{
    public function __invoke(ScrapeMessage $message)
    {
        echo 'HELLOO: '.$message->getMessage()."\r\n";
    }
}