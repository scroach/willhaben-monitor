<?php

namespace App\MessageHandler;

use App\Entity\Listing;
use App\Entity\ListingSource;
use App\ImmobilienScout24Scraper;
use App\Message\DownloadImagesMessage;
use App\Message\FetchAllListingsMessage;
use App\WillhabenScraper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

class ScrapeMessageHandler
{
    public function __construct(
        private readonly WillhabenScraper $scraper,
        private readonly ImmobilienScout24Scraper $immoscoutScraper,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[AsMessageHandler]
    public function handleFetchNotification(FetchAllListingsMessage $message): void
    {
        match ($message->source) {
            ListingSource::WILLHABEN => $this->scraper->scrape(),
            ListingSource::IMMOBILIENSCOUT24 => $this->immoscoutScraper->scrape(0),
            default => throw new \InvalidArgumentException('Unknown listing source'),
        };
    }

    #[AsMessageHandler]
    public function handleDownloadNotification(DownloadImagesMessage $message): void
    {
        $listing = $this->em->find(Listing::class, $message->listingId);
        if (!$listing) {
            return;
        }

        if ($listing->isImmoscout24()) {
            $this->immoscoutScraper->fetchImage($listing);

            return;
        }

        $this->scraper->fetchImage($listing);
    }

}