<?php

declare(strict_types=1);

namespace App;

use App\Entity\Listing;
use App\Message\ScrapeMessage;
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
    }

    private function searchPages()
    {
        $currentPage = 1;

        do {
            $listingsResult = $this->doSearchRequest($currentPage);
            foreach ($listingsResult->getListings() as $listing) {
                $existing = $this->entityManager->getRepository(Listing::class)->findOneBy(['willhabenId' => $listing->getWillhabenId()]);
                if ($existing) {
                    $existing->addListingData($listing->getListingData()->first());
                    $existing->setLastSeen(new \DateTimeImmutable());
                    $listing = $existing;
                }

                $this->entityManager->persist($listing);
            }
            $this->entityManager->flush();

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
                    $this->debugLog($requestId, $exception->getMessage(), 'Request failed');
                    $this->debugLog($requestId, (string)$exception->getResponse()->getBody(), 'Response Body');
                    $this->debugLog($requestId, json_encode($exception->getResponse()->getHeaders(), JSON_PRETTY_PRINT), 'Response Body');
                    echo "request failed, retrying in 10... ".$exception->getMessage()."\r\n";
                    $proxyRetry++;
                    sleep(10);
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
        $imageUrls = $listing->getListingData()->first()->getAttribute('ALL_IMAGE_URLS');

        $imageUrls = explode(';', $imageUrls);
        $imageBaseUrl = 'https://cache.willhaben.at/mmo/';

        $filesystem = new Filesystem();
        foreach ($imageUrls as $imageUrl) {
            $localPath = $this->imageDir.$imageUrl;
            $remoteUrl = $imageBaseUrl.$imageUrl;

            if (!$filesystem->exists($localPath)) {
                //TODO guzzle with proxy?
                $this->logger->info('Downloading image '.$imageUrl);
                $filesystem->appendToFile($localPath, file_get_contents($remoteUrl));
                sleep(rand(10, 30));
            } else {
                $this->logger->info('Skipping existing image '.$imageUrl);
            }
        }
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
