<?php

namespace App\Controller;

use App\Entity\Listing;
use App\Entity\ListingType;
use App\ImmobilienScout24Scraper;
use App\Message\DownloadImagesMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

class ListingsController extends AbstractController
{
    #[Route('/listings', name: 'app_listings')]
    public function index(): JsonResponse
    {
        return $this->json([
            'message' => 'Welcome to your new controller!',
            'path' => 'src/Controller/ListingsController.php',
        ]);
    }

    #[Route('/listings/{id}', name: 'details')]
    public function details(
        Listing $listing,
        EntityManagerInterface $em,
        LoggerInterface $logger,
        ImmobilienScout24Scraper $immoscoutScraper,
    ): Response {
        $immoscoutScraper->enrichImages($listing);

        $listing->updateAggregatedDataFull();
        $em->flush();

        return $this->render('details.twig', ['listing' => $listing]);
    }

    #[Route('/listings/{id}/star', name: 'star_listing')]
    public function star(Listing $listing, EntityManagerInterface $em): Response
    {
        $listing->setIsStarred(!$listing->isStarred());
        $em->flush();

        return $this->redirectToRoute('details', ['id' => $listing->getId()]);
    }

    #[Route('/listings/{id}/fetch-images', name: 'fetch-images')]
    public function fetchImages(Listing $listing, MessageBusInterface $bus): Response
    {
        $bus->dispatch(new DownloadImagesMessage($listing->getId()));
        $this->addFlash('info', 'fetching of images triggered');

        return $this->redirectToRoute('details', ['id' => $listing->getId()]);
    }

    #[Route('/listings/scatter-chart', name: 'scatter-chart')]
    public function scatterChart(
        EntityManagerInterface $em,
        Request $request,
        ?int $maxMonths = null,
        ?Listing $listing = null,
    ): Response {
        $type = $listing?->getType() ?? $this->resolveListingType($request);
        $bounds = $this->chartBounds($type);

        $queryBuilder = $em->getRepository(Listing::class)->createQueryBuilder('l')
            ->select('l.priceCurrent, l.area, l.zip, l.id')
            ->andWhere('l.type = :type')
            ->andWhere('l.priceCurrent IS NOT NULL')
            ->andWhere('l.priceCurrent BETWEEN :minPrice AND :maxPrice')
            ->andWhere('l.area IS NOT NULL')
            ->andWhere('l.area BETWEEN :minArea AND :maxArea')
            ->andWhere('l.lastSeen IS NOT NULL')
            ->setParameter('type', $type->value)
            ->setParameter('minPrice', $bounds['minPrice'])
            ->setParameter('maxPrice', $bounds['maxPrice'])
            ->setParameter('minArea', $bounds['minArea'])
            ->setParameter('maxArea', $bounds['maxArea'])
            ->addOrderBy('l.zip', 'ASC');

        if ($maxMonths) {
            $queryBuilder
                ->andWhere('l.lastSeen >= :minDate')
                ->setParameter('minDate', (new \DateTime())->modify("-$maxMonths months"));
        }

        $result = $queryBuilder->getQuery()->getResult();

        $data = [];
        foreach ($result as $row) {
            $zip = substr((string) $row['zip'], 0, 2).'xx';
            $data[$zip][] = ['price' => $row['priceCurrent'], 'area' => $row['area'], 'id' => $row['id']];
        }

        return $this->render('_scatter_chart.twig', [
            'scatterData' => $data,
            'listing' => $listing,
            'typeLabel' => $type->pluralLabel(),
            'areaLabel' => $type === ListingType::LAND ? 'Grundfläche m²' : 'Wohnfläche m²',
            'minArea' => $bounds['minArea'],
            'maxPrice' => $bounds['maxPrice'],
        ]);
    }

    #[Route('/listings/scatter-chart-stats', name: 'scatter-chart-stats')]
    public function scatterChartStats(EntityManagerInterface $em, Request $request): Response
    {
        $type = $this->resolveListingType($request);
        $bounds = $this->chartBounds($type);

        $result = $em->getRepository(Listing::class)->createQueryBuilder('l')
            ->select('l.priceCurrent, l.area, l.firstSeen, l.lastSeen, l.id')
            ->andWhere('l.type = :type')
            ->andWhere('l.priceCurrent IS NOT NULL')
            ->andWhere('l.priceCurrent BETWEEN :minPrice AND :maxPrice')
            ->andWhere('l.area IS NOT NULL')
            ->andWhere('l.area BETWEEN :minArea AND :maxArea')
            ->andWhere('l.lastSeen IS NOT NULL')
            ->setParameter('type', $type->value)
            ->setParameter('minPrice', $bounds['minPrice'])
            ->setParameter('maxPrice', $bounds['maxPrice'])
            ->setParameter('minArea', $bounds['minArea'])
            ->setParameter('maxArea', $bounds['maxArea'])
            ->getQuery()->getResult();

        $data = ['fresh' => [], 'sold' => []];
        foreach ($result as $row) {
            $pricePerSqm = $row['priceCurrent'] / $row['area'];
            $ageInWeeks = $row['firstSeen']->diff($row['lastSeen'])->days / 7;

            $single = ['pricePerSqm' => $pricePerSqm, 'ageInWeeks' => $ageInWeeks, 'id' => $row['id']];
            if ($row['lastSeen'] > (new \DateTime())->modify('-7days')) {
                $data['fresh'][] = $single;
            } else {
                $data['sold'][] = $single;
            }
        }

        return $this->render('_scatter_chart_stats.twig', [
            'scatterData' => $data,
            'typeLabel' => $type->pluralLabel(),
            'zoomLimit' => $bounds['maxPricePerSqm'],
        ]);
    }

    #[Route('/listings/price-trend', name: 'price-trend')]
    public function priceTrend(EntityManagerInterface $em, Request $request): Response
    {
        $type = $this->resolveListingType($request);
        $bounds = $this->chartBounds($type);
        $from = (new \DateTimeImmutable('-24 months'))->modify('first day of this month')->setTime(0, 0);

        $rows = $em->getRepository(Listing::class)->createQueryBuilder('l')
            ->select('l.firstSeen AS firstSeen', 'l.priceCurrent AS priceCurrent', 'l.priceCurrentPerSqm AS priceCurrentPerSqm')
            ->andWhere('l.type = :type')
            ->andWhere('l.firstSeen >= :from')
            ->andWhere('l.priceCurrent BETWEEN :minPrice AND :maxPrice')
            ->andWhere('l.area BETWEEN :minArea AND :maxArea')
            ->andWhere('l.priceCurrentPerSqm IS NOT NULL')
            ->setParameter('type', $type->value)
            ->setParameter('from', $from)
            ->setParameter('minPrice', $bounds['minPrice'])
            ->setParameter('maxPrice', $bounds['maxPrice'])
            ->setParameter('minArea', $bounds['minArea'])
            ->setParameter('maxArea', $bounds['maxArea'])
            ->orderBy('l.firstSeen', 'ASC')
            ->getQuery()->getArrayResult();

        $buckets = [];
        foreach ($rows as $row) {
            $firstSeen = $row['firstSeen'];
            if ($firstSeen instanceof \DateTimeInterface) {
                $month = $firstSeen->format('Y-m');
            } elseif (is_string($firstSeen) && strlen($firstSeen) >= 7) {
                $month = substr($firstSeen, 0, 7);
            } else {
                continue;
            }

            $buckets[$month]['price'][] = (float) $row['priceCurrent'];
            $buckets[$month]['pricePerSqm'][] = (float) $row['priceCurrentPerSqm'];
        }

        ksort($buckets);

        $labels = [];
        $avgPrice = [];
        $avgPricePerSqm = [];
        foreach ($buckets as $month => $values) {
            $labels[] = (new \DateTimeImmutable($month.'-01'))->format('m/Y');
            $avgPrice[] = round(array_sum($values['price']) / count($values['price']), 2);
            $avgPricePerSqm[] = round(array_sum($values['pricePerSqm']) / count($values['pricePerSqm']), 2);
        }

        return $this->render('_price_trend.twig', [
            'trendData' => [
                'labels' => $labels,
                'avgPrice' => $avgPrice,
                'avgPricePerSqm' => $avgPricePerSqm,
            ],
            'typeLabel' => $type->pluralLabel(),
        ]);
    }

    private function resolveListingType(Request $request): ListingType
    {
        $value = $request->attributes->get('type');

        if ($value instanceof ListingType) {
            return $value;
        }

        if (!is_string($value)) {
            $value = $request->query->getString('type', '');
        }

        return ListingType::tryFrom($value) ?? ListingType::HOUSE;
    }

    /**
     * @return array{minArea: float, maxArea: float, minPrice: float, maxPrice: float, maxPricePerSqm: float}
     */
    private function chartBounds(ListingType $type): array
    {
        return $type === ListingType::LAND
            ? [
                'minArea' => 100.0,
                'maxArea' => 20000.0,
                'minPrice' => 1000.0,
                'maxPrice' => 5000000.0,
                'maxPricePerSqm' => 2000.0,
            ]
            : [
                'minArea' => 90.0,
                'maxArea' => 500.0,
                'minPrice' => 1000.0,
                'maxPrice' => 1000000.0,
                'maxPricePerSqm' => 20000.0,
            ];
    }
}
