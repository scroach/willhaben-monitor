<?php

declare(strict_types=1);

namespace App;

use App\Entity\Listing;
use App\Message\DownloadImagesMessage;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\MessageBusInterface;

class WillhabenScraper
{
    private const MAX_RETRIES = 3;
    private const MAX_RETRIES_PER_PROXY = 3;
    private string $debugDir;
    private string $imageDir;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private array $proxies,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
        Kernel $kernel,
    ) {
        $this->debugDir = $kernel->getProjectDir().'/var/debug/';
        $this->imageDir = $kernel->getProjectDir().'/public/willhaben_images/';
    }

    public function scrape()
    {
        $this->searchPages();
    }

    private function fetchRandomProxy(): string
    {
        if (!$this->proxies) {
            echo "\n\nno proxies left... fetching new ones...\n\n";
            $this->proxies = $this->fetchProxies();
        }

        return $this->proxies[array_rand($this->proxies)];
    }

    private function fetchProxies(): array
    {
        $client = new Client([]);

        $proxis = $client->get('https://spys.me/proxy.txt');
        $contents = $proxis->getBody()->getContents();

        // fetch all proxies that have the Anonymous or High Anonymous flag

        preg_match_all('/([\d.:]+) \w{2}-[AH]!?/', $contents, $matches);

        echo "got ".count($matches[1])." proxies\r\n";

        return $matches[1];
    }

    private function blacklistProxy(string $proxy): void
    {
        unset($this->proxies[array_search($proxy, $this->proxies, true)]);
        echo "\n\nbanned proxy, got ".count($this->proxies)." left\n\n";
        $this->logProxy($proxy, false);
    }

    private function logProxy(string $proxy, bool $isGood): void
    {
        try {
            if(!file_exists('proxies_stats.txt')) {
                touch('proxies_stats.txt');
                chmod('proxies_stats.txt', 0777);
            }
            $proxyStats = json_decode(file_get_contents('proxies_stats.txt'), true);
            $proxyStats = $proxyStats ?: [];
            $proxyStats[$proxy] ??= ['good' => 0, 'bad' => 0];
            $proxyStats[$proxy][$isGood ? 'good' : 'bad']++;

            file_put_contents('proxies_stats.txt', json_encode($proxyStats, JSON_PRETTY_PRINT));
        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage());
        }
    }

    private function searchPages()
    {
        $currentPage = 1;

        do {
            $this->entityManager->clear();
            $listings = [];
            $listingsResult = $this->doSearchRequest($currentPage);
            $count = count($listingsResult->getListings());
            echo "got search response, found $count listings on page {$listingsResult->getCurrentPage()} of {$listingsResult->getMaxPage()}\r\n";

            foreach ($listingsResult->getListings() as $listing) {
                $existing = $this->entityManager->getRepository(Listing::class)->findOneBy(['willhabenId' => $listing->getWillhabenId()]);
                if ($existing) {
                    //TODO process update, add more metadata directly
                    $existing->addListingData($listing->getListingData()->first());
                    $existing->setLastSeen(new \DateTimeImmutable());
                    $listing = $existing;
                }

                $listings[] = $listing;
                $this->entityManager->persist($listing);
            }
            $this->entityManager->flush();

            foreach ($listings as $listing) {
                if ($this->hasMissingImages($listing)) {
                    $this->bus->dispatch(new DownloadImagesMessage($listing->getId()));
                }
            }

            $maxPage = $listingsResult->getMaxPage();
            $currentPage++;
        } while ($currentPage <= $maxPage);

        die('done!');
    }

    function doSearchRequest(int $currentPage): ListingsResult
    {
        $client = new Client([
            'timeout' => 30,
            'verify' => false,
        ]);

        $requestId = time();
        $requestRetry = 0;
        do {
            $proxyRetry = 0;
            $randomProxy = $this->fetchRandomProxy();
            echo "using proxy: $randomProxy\r\n";
            do {
                try {
                    // https://www.willhaben.at/iad/immobilien/haus-kaufen/haus-angebote?0%5BareaId%5D=6&1%5BNO_OF_ROOMS_BUCKET%5D=4X4&2%5BNO_OF_ROOMS_BUCKET%5D=5X5&3%5BNO_OF_ROOMS_BUCKET%5D=6X9&4%5BESTATE_SIZE%2FLIVING_AREA_FROM%5D=95&5%5Brows%5D=200&6%5Bpage%5D=1
                    // https://www.willhaben.at/iad/immobilien/haus-kaufen/haus-angebote?0%5BareaId%5D=6&1%5BNO_OF_ROOMS_BUCKET%5D=4X4&2%5BNO_OF_ROOMS_BUCKET%5D=5X5&3%5BNO_OF_ROOMS_BUCKET%5D=6X9&4%5BESTATE_SIZE/LIVING_AREA_FROM%5D=95&5%5Brows%5D=200&6%5Bpage%5D=1&sfId=60919f25-e533-4c18-80d9-6fcea1d01901&rows=30&isNavigation=true&areaId=6&page=1
                    $url = 'https://www.willhaben.at/iad/immobilien/haus-kaufen/haus-angebote';
                    $params = [
                        'areaId' => 6,
                        'NO_OF_ROOMS_BUCKET' => ['4X4', '5X5', '6X9'],
                        'ESTATE_SIZE/LIVING_AREA_FROM' => 95,
                        'rows' => 200,
                        'page' => $currentPage,
                    ];

                    $query = \GuzzleHttp\Psr7\Query::build($params);

                    $response = $client->get($url, [
                        'proxy' => $randomProxy,
                        'query' => $query,
                    ]);

                    $this->debugLog($requestId, (string)$response->getBody(), 'Response Success');
                    $this->logProxy($randomProxy, true);

                    $result = (string)$response->getBody();
                    $result = explode('<script id="__NEXT_DATA__" type="application/json">', $result)[1];
                    $result = explode('</script>', $result)[0];
                    $result = json_decode($result, true);

                    return ListingsResult::fromJson($result);
                } catch (ConnectException $exception) {
                    $this->debugLog($requestId, $exception->getMessage(), 'Request failed');
                    echo "request failed, retrying in 10... ".$exception->getMessage()."\r\n";
                    $proxyRetry++;
                    sleep(10);
                } catch (RequestException $exception) {
                    $failedBody = (string) $exception->getResponse()->getBody();
                    $this->debugLog($requestId, $exception->getMessage(), 'Request failed');
                    $this->debugLog($requestId, $failedBody, 'Response Body');
                    $this->debugLog($requestId, json_encode($exception->getResponse()->getHeaders(), JSON_PRETTY_PRINT), 'Response Body');

                    if (str_contains($failedBody, 'IP address is blocked') || str_contains($failedBody, 'IP-Adresse wurde blockiert')) {
                        echo "IP / proxy is blocked by willhaben, no need to retry...\r\n";
                        $proxyRetry = self::MAX_RETRIES_PER_PROXY;
                    } else {
                        echo "request failed, retrying in 10... ".$exception->getMessage()."\r\n";
                        $proxyRetry++;
                        sleep(10);
                    }
                } catch (GuzzleException $exception) {
                    $proxyRetry++;
                    rt($exception);
                    sleep(10);
                }
            } while ($proxyRetry < self::MAX_RETRIES_PER_PROXY);

            $this->blacklistProxy($randomProxy);
            echo "proxy: $randomProxy is no good :(\r\n";
        } while ($requestRetry < self::MAX_RETRIES);
    }

    public function fetchImage(Listing $listing)
    {
        $this->logger->info('Started fetching listing images', ['listingId' => $listing->getId()]);

        $imageUrls = $listing->getCurrentListingData()->getImages();
        $imageBaseUrl = 'https://cache.willhaben.at/mmo/';

        $downloadedSomething = false;
        $filesystem = new Filesystem();
        foreach ($imageUrls as $i => $imageUrl) {
            $localPath = $this->imageDir.$imageUrl;
            $remoteUrl = $imageBaseUrl.$imageUrl;

            if (!$filesystem->exists($localPath)) {
                //TODO try catch
                //TODO guzzle with proxy?
                $this->logger->info('Downloading image '.$imageUrl.' ('.($i+1).'/'.count($imageUrls).')');
                $filesystem->appendToFile($localPath, file_get_contents($remoteUrl));
                $downloadedSomething = true;
            } else {
                $this->logger->info('Skipping existing image '.$imageUrl);
            }
        }

        if($downloadedSomething) {
            sleep(rand(10, 30));
        }
    }

    private function hasMissingImages(Listing $listing): bool
    {
        $imageUrls = $listing->getCurrentListingData()->getImages();

        $filesystem = new Filesystem();
        foreach ($imageUrls as $imageUrl) {
            $localPath = $this->imageDir.$imageUrl;

            if (!$filesystem->exists($localPath)) {
                return true;
            }
        }
        return false;
    }

    private function debugLog(int $requestId, string $content, string $header = ''): void
    {
        $filesystem = new Filesystem();
        $logFile = $this->debugDir.'request_'.$requestId.'.log';

        if ($header) {
            $filesystem->appendToFile($logFile, "###############################################\r\n");
            $filesystem->appendToFile($logFile, $header."\r\n");
            $filesystem->appendToFile($logFile, "###############################################\r\n");
        }
        $filesystem->appendToFile($logFile, $content."\r\n");


    }

}
