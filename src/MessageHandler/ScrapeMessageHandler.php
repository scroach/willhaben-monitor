<?php

namespace App\MessageHandler;

use App\Entity\Listing;
use App\Message\DownloadImagesMessage;
use App\Message\FetchAllListingsMessage;
use App\WillhabenScraper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

class ScrapeMessageHandler
{
    public function __construct(
        private readonly WillhabenScraper $scraper,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[AsMessageHandler]
    public function handleFetchNotification(FetchAllListingsMessage $message): void
    {
        $this->scraper->scrape();
    }

    #[AsMessageHandler]
    public function handleDownloadNotification(DownloadImagesMessage $message): void
    {
        $this->scraper->fetchImage($this->em->find(Listing::class, $message->listingId));
    }

}