<?php

declare(strict_types=1);

namespace App;

use App\Entity\Listing;
use App\Entity\ListingData;
use App\Entity\ListingSource;
use App\Entity\ListingType;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;

class ImmobilienScout24Scraper
{
    private const MAX_RETRIES = 3;

    private const REGION = 'steiermark';

    private const SEARCH_PATHS = [
        'house' => '/haus-kaufen',
        'land' => '/grundstuecke',
    ];

    private string $imageDir;
    private ?Client $client = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        Kernel $kernel,
    ) {
        $this->imageDir = $kernel->getProjectDir().'/public/immoscout_images/';
    }

    /**
     * @param int $maxPages Maximum pages per listing type, 0 means all pages
     */
    public function scrape(int $maxPages = 5, float $minDelay = 2.0, float $maxDelay = 4.0): void
    {
        foreach (ListingType::cases() as $type) {
            $url = sprintf(
                'https://www.immobilienscout24.at/regional/%s%s',
                self::REGION,
                self::SEARCH_PATHS[$type->value],
            );

            $this->searchPages($url, $type, $maxPages, $minDelay, $maxDelay);
        }
    }

    private function searchPages(string $url, ListingType $type, int $maxPages, float $minDelay, float $maxDelay): void
    {
        $currentPage = 1;

        while (true) {
            $this->entityManager->clear();

            $result = $this->doSearchRequest($url, $currentPage);
            $hits = $result->getHits();

            $this->logger->info(sprintf(
                'ImmoScout24: got %d listings for %s on page %d of %d (%d total)',
                count($hits),
                $type->value,
                $result->getCurrentPage(),
                $result->getMaxPage(),
                $result->getTotalHits(),
            ));

            foreach ($hits as $hit) {
                $this->importHit($hit, $type);
            }

            $this->entityManager->flush();

            $maxPage = $result->getMaxPage();

            if ($hits === [] || $currentPage >= $maxPage) {
                break;
            }

            if ($maxPages > 0 && $currentPage >= $maxPages) {
                $this->logger->info(sprintf('ImmoScout24: reached page limit of %d for %s', $maxPages, $type->value));
                break;
            }

            $this->sleep($minDelay, $maxDelay);
            $currentPage++;
        }

        $this->logger->info(sprintf('ImmoScout24: done scraping %s', $type->value));
    }

    public function doSearchRequest(string $baseUrl, int $currentPage = 1): ImmobilienScout24ListingsResult
    {
        $url = $currentPage <= 1
            ? $baseUrl
            : rtrim($baseUrl, '/').'/seite-'.$currentPage;

        $client = $this->getClient();
        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $response = $client->get($url, [
                    'headers' => [
                        'Referer' => 'https://www.immobilienscout24.at/',
                    ],
                ]);

                return ImmobilienScout24ListingsResult::fromHtml((string) $response->getBody());
            } catch (GuzzleException $exception) {
                $lastException = $exception;
                $statusCode = $exception instanceof RequestException
                    ? $exception->getResponse()?->getStatusCode()
                    : null;

                $waitSeconds = in_array($statusCode, [403, 429], true)
                    ? 30 * $attempt
                    : 5 * $attempt;

                $this->logger->warning(sprintf(
                    'ImmoScout24 request failed (attempt %d/%d, status %s): %s',
                    $attempt,
                    self::MAX_RETRIES,
                    $statusCode ?? 'n/a',
                    $exception->getMessage(),
                ));

                if ($attempt < self::MAX_RETRIES) {
                    sleep($waitSeconds);
                }
            } catch (\RuntimeException $exception) {
                $lastException = $exception;
                $this->logger->warning(sprintf(
                    'ImmoScout24 response could not be parsed (attempt %d/%d): %s',
                    $attempt,
                    self::MAX_RETRIES,
                    $exception->getMessage(),
                ));

                if ($attempt < self::MAX_RETRIES) {
                    sleep(5 * $attempt);
                }
            }
        }

        throw new \RuntimeException(sprintf('Could not fetch ImmoScout24 page %s.', $url), 0, $lastException);
    }

    private function importHit(array $hit, ListingType $type): void
    {
        $exposeId = (string) ($hit['exposeId'] ?? '');
        if ($exposeId === '') {
            return;
        }

        $payload = $this->buildListingData($hit, $type, $exposeId);
        $listingData = new ListingData($payload);

        $existing = $this->entityManager
            ->getRepository(Listing::class)
            ->findOneBy(['immoscoutId' => $exposeId]);

        if ($existing) {
            $listingData->setListing($existing);
            $this->entityManager->persist($listingData);
            $existing->addListingData($listingData);
            $existing->setLastSeen(new \DateTimeImmutable());
            $existing->setType($type);

            return;
        }

        $listing = new Listing();
        $listing->setSource(ListingSource::IMMOBILIENSCOUT24);
        $listing->setImmoscoutId($exposeId);
        $listing->setType($type);
        $listing->setTitle($payload['description']);
        $listing->getListingData()->add($listingData);
        $listing->addListingData($listingData);

        $this->entityManager->persist($listingData);
        $this->entityManager->persist($listing);
    }

    private function buildListingData(array $hit, ListingType $type, string $exposeId): array
    {
        $headline = $this->cleanHeadline((string) ($hit['headline'] ?? ''));
        $addressString = trim((string) ($hit['addressString'] ?? ''));
        [$zip, $city] = $this->parseAddress($addressString);

        $price = $this->toFloat($hit['primaryPrice'] ?? null);
        $area = $this->toFloat($hit['primaryArea'] ?? null) ?? $this->areaFromKeyFacts($hit['mainKeyFacts'] ?? []);
        $rooms = $this->toFloat($hit['numberOfRooms'] ?? null) ?? $this->roomsFromKeyFacts($hit['mainKeyFacts'] ?? []);
        $image = (string) ($hit['primaryPictureImageProps']['src'] ?? '');
        $url = (string) ($hit['links']['absoluteURL'] ?? '');
        $provider = (string) ($hit['realtorContact']['company'] ?? $hit['realtorContact']['name'] ?? '');
        $dateCreated = (string) ($hit['dateCreated'] ?? '');

        $attributes = [
            ['name' => 'PRICE', 'values' => [$price ?? 0]],
            ['name' => 'EXPOSE_ID', 'values' => [$exposeId]],
            ['name' => 'EXPOSE_URL', 'values' => [$url]],
            ['name' => 'SOURCE', 'values' => ['immobilienscout24']],
            ['name' => 'ESTATE_TYPE', 'values' => [$type->value]],
            ['name' => 'TRANSFER_TYPE', 'values' => [(string) ($hit['meta']['transferType'] ?? '')]],
            ['name' => 'PROVIDER', 'values' => [$provider]],
            ['name' => 'DATE_CREATED', 'values' => [$dateCreated]],
            ['name' => 'ADDRESS', 'values' => [$addressString]],
        ];

        if ($area !== null) {
            $attributes[] = ['name' => 'ESTATE_SIZE', 'values' => [$area]];
        }

        if ($rooms !== null) {
            $attributes[] = ['name' => 'NUMBER_OF_ROOMS', 'values' => [$rooms]];
        }

        if ($city !== null && $city !== '') {
            $attributes[] = ['name' => 'LOCATION', 'values' => [$city]];
        }

        if ($zip !== null && $zip !== '') {
            $attributes[] = ['name' => 'POSTCODE', 'values' => [$zip]];
        }

        if ($image !== '') {
            $attributes[] = ['name' => 'ALL_IMAGE_URLS', 'values' => [$image]];
        }

        return [
            'id' => $exposeId,
            'description' => $headline,
            'source' => 'immobilienscout24',
            'attributes' => [
                'attribute' => $attributes,
            ],
            'raw' => $hit,
        ];
    }

    private function cleanHeadline(string $headline): string
    {
        $headline = html_entity_decode(strip_tags($headline), ENT_QUOTES | ENT_HTML5);

        return trim(str_replace('**', '', $headline));
    }

    private function parseAddress(string $address): array
    {
        if ($address === '') {
            return [null, null];
        }

        if (preg_match('/(\d{4})\s+(.+)$/u', $address, $matches)) {
            return [$matches[1], trim($matches[2])];
        }

        return [null, $address];
    }

    private function areaFromKeyFacts(array $keyFacts): ?float
    {
        foreach ($keyFacts as $keyFact) {
            $value = (string) ($keyFact['value'] ?? '');
            if (str_contains($value, 'm²')) {
                return $this->toFloat($value);
            }
        }

        return null;
    }

    private function roomsFromKeyFacts(array $keyFacts): ?float
    {
        foreach ($keyFacts as $keyFact) {
            $label = (string) ($keyFact['label'] ?? '');
            $value = (string) ($keyFact['value'] ?? '');
            if (str_contains($label, 'Zi') || str_contains($value, 'Zi')) {
                return $this->toFloat($value);
            }
        }

        return null;
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        if (!preg_match('/\d[\d.,]*/', $value, $matches)) {
            return null;
        }

        $number = str_replace(' ', '', $matches[0]);
        $hasComma = str_contains($number, ',');
        $hasDot = str_contains($number, '.');

        if ($hasComma && $hasDot) {
            $number = str_replace(',', '.', str_replace('.', '', $number));
        } elseif ($hasComma) {
            $number = str_replace(',', '.', $number);
        } elseif ($hasDot && preg_match('/^\d{1,3}(\.\d{3})+$/', $number)) {
            $number = str_replace('.', '', $number);
        }

        return is_numeric($number) ? (float) $number : null;
    }

    /**
     * Loads the full gallery URLs from the expose page without downloading the images.
     * Called lazily when a listing detail page is opened, because the search result
     * only contains the teaser image.
     */
    public function enrichImages(Listing $listing): void
    {
        if (!$listing->isImmoscout24()) {
            return;
        }

        $listingData = $listing->getCurrentListingData();
        if (!$listingData) {
            return;
        }

        if ($listingData->getAttribute('ALL_IMAGE_FILES') !== null
            || $listingData->getAttribute('IMAGE_GALLERY_LOADED') !== null
        ) {
            return;
        }

        if (count($listingData->getImages()) > 1) {
            return;
        }

        $urls = $this->fetchExposeImageUrls((string) $listing->getImmoscoutId());
        if ($urls === []) {
            return;
        }

        $data = $listingData->getData();
        $attributes = $data['attributes']['attribute'] ?? [];
        $attributes = array_values(array_filter(
            $attributes,
            fn (array $attribute) => ($attribute['name'] ?? null) !== 'ALL_IMAGE_URLS',
        ));
        $attributes[] = ['name' => 'ALL_IMAGE_URLS', 'values' => [implode(';', $urls)]];
        $attributes[] = ['name' => 'IMAGE_GALLERY_LOADED', 'values' => ['1']];
        $data['attributes']['attribute'] = $attributes;
        $listingData->setData($data);

        $this->logger->info(sprintf(
            'ImmoScout24: loaded %d gallery images for listing %d',
            count($urls),
            $listing->getId(),
        ));

        $this->entityManager->flush();
    }

    /**
     * Downloads the image gallery of an expose page. This is only triggered on demand,
     * because fetching every expose page during a scrape would cause a lot of requests.
     */
    public function fetchImage(Listing $listing): void
    {
        if (!$listing->isImmoscout24()) {
            return;
        }

        $listingData = $listing->getCurrentListingData();
        if (!$listingData) {
            return;
        }

        $urls = array_values(array_filter(
            $listingData->getImages(),
            fn (mixed $image) => is_string($image) && str_starts_with($image, 'http'),
        ));

        // Only ask the expose page if the gallery has not been loaded yet.
        if (count($urls) <= 1) {
            $urls = $this->fetchExposeImageUrls((string) $listing->getImmoscoutId()) ?: $urls;
        }

        $filesystem = new Filesystem();
        $filesystem->mkdir($this->imageDir);

        $files = [];
        foreach ($urls as $index => $url) {
            $filename = sha1($url).'.'.$this->imageExtension($url);
            $path = $this->imageDir.$filename;

            if (!$filesystem->exists($path)) {
                try {
                    $response = $this->getClient()->get($url, [
                        'headers' => [
                            'Referer' => 'https://www.immobilienscout24.at/expose/'.$listing->getImmoscoutId(),
                        ],
                    ]);
                    $filesystem->dumpFile($path, (string) $response->getBody());
                    $this->logger->info(sprintf(
                        'ImmoScout24: downloaded image %d/%d for listing %d',
                        $index + 1,
                        count($urls),
                        $listing->getId(),
                    ));
                    $this->sleep(0.5, 1.5);
                } catch (GuzzleException $exception) {
                    $this->logger->error('ImmoScout24 image download failed: '.$exception->getMessage());
                    continue;
                }
            }

            $files[] = $filename;
        }

        if ($files === []) {
            return;
        }

        $data = $listingData->getData();
        $attributes = $data['attributes']['attribute'] ?? [];
        $attributes = array_values(array_filter(
            $attributes,
            fn (array $attribute) => ($attribute['name'] ?? null) !== 'ALL_IMAGE_FILES',
        ));
        $attributes[] = ['name' => 'ALL_IMAGE_FILES', 'values' => [implode(';', $files)]];
        $data['attributes']['attribute'] = $attributes;
        $listingData->setData($data);

        $this->entityManager->flush();
    }

    private function fetchExposeImageUrls(string $exposeId): array
    {
        if ($exposeId === '') {
            return [];
        }

        try {
            $response = $this->getClient()->get('https://www.immobilienscout24.at/expose/'.$exposeId, [
                'headers' => [
                    'Referer' => 'https://www.immobilienscout24.at/',
                ],
            ]);
        } catch (GuzzleException $exception) {
            $this->logger->error('ImmoScout24 expose request failed: '.$exception->getMessage());

            return [];
        }

        $html = (string) $response->getBody();

        // The Apollo state contains the complete gallery, while the JSON-LD only has a teaser.
        $urls = $this->extractApolloPictureUrls($html, $exposeId);
        if ($urls !== []) {
            return $urls;
        }

        return $this->extractLdJsonPictureUrls($html);
    }

    private function extractApolloPictureUrls(string $html, string $exposeId): array
    {
        try {
            $state = ImmobilienScout24State::extract($html, 'window.__APOLLO_STATE__');
        } catch (\RuntimeException) {
            return [];
        }

        $expose = $state['Expose:'.$exposeId] ?? null;
        if (!is_array($expose) || !is_array($expose['pictures'] ?? null)) {
            return [];
        }

        $urls = [];
        foreach ($expose['pictures'] as $picture) {
            if (is_array($picture) && is_string($picture['url'] ?? null) && $picture['url'] !== '') {
                $urls[] = $picture['url'];
            }
        }

        return array_values(array_unique($urls));
    }

    private function extractLdJsonPictureUrls(string $html): array
    {
        if (!preg_match_all('#<script[^>]*type="application/ld\+json"[^>]*>(.*?)</script>#is', $html, $matches)) {
            return [];
        }

        foreach ($matches[1] as $json) {
            try {
                $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            $nodes = isset($data['@graph']) && is_array($data['@graph']) ? $data['@graph'] : [$data];
            foreach ($nodes as $node) {
                if (!is_array($node) || ($node['@type'] ?? null) !== 'Product') {
                    continue;
                }

                $images = $node['image'] ?? [];
                if (is_string($images)) {
                    $images = [$images];
                }

                if (is_array($images) && $images !== []) {
                    return array_values(array_filter($images, 'is_string'));
                }
            }
        }

        return [];
    }

    private function imageExtension(string $url): string
    {
        if (str_contains($url, 'format/webp')) {
            return 'webp';
        }

        $path = parse_url($url, PHP_URL_PATH);
        $extension = is_string($path) ? strtolower(pathinfo($path, PATHINFO_EXTENSION)) : '';

        return in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true) ? $extension : 'jpg';
    }

    private function sleep(float $minSeconds, float $maxSeconds): void
    {
        $minMicroseconds = (int) ($minSeconds * 1_000_000);
        $maxMicroseconds = (int) ($maxSeconds * 1_000_000);

        usleep($maxMicroseconds > $minMicroseconds
            ? random_int($minMicroseconds, $maxMicroseconds)
            : $minMicroseconds);
    }

    private function getClient(): Client
    {
        return $this->client ??= $this->createClient();
    }

    private function createClient(): Client
    {
        return new Client([
            'allow_redirects' => [
                'max' => 10,
                'strict' => false,
                'referer' => true,
                'protocols' => ['http', 'https'],
                'track_redirects' => true,
            ],
            'cookies' => new CookieJar(),
            'timeout' => 30.0,
            'connect_timeout' => 10.0,
            'decode_content' => true,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language' => 'de-AT,de;q=0.9,en;q=0.8',
                'Accept-Encoding' => 'gzip, deflate',
                'Upgrade-Insecure-Requests' => '1',
                'Sec-Fetch-Dest' => 'document',
                'Sec-Fetch-Mode' => 'navigate',
                'Sec-Fetch-Site' => 'none',
                'Sec-Fetch-User' => '?1',
            ],
        ]);
    }
}
