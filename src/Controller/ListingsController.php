<?php

namespace App\Controller;

use App\Entity\Listing;
use App\Message\DownloadImagesMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

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
    public function details(Listing $listing, EntityManagerInterface $em, LoggerInterface $logger): Response
    {
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
    public function scatterChart(EntityManagerInterface $em, ?int $maxMonths = null, ?Listing $listing = null): Response
    {
        $queryBuilder = $em->getRepository(Listing::class)->createQueryBuilder('l')
            ->select('l.priceCurrent, l.area, l.zip, l.id')
            ->andWhere('l.priceCurrent IS NOT NULL')
            ->andWhere('l.priceCurrent BETWEEN 1000 AND 1000000')
            ->andWhere('l.area IS NOT NULL')
            ->andWhere('l.area BETWEEN 90 AND 500')
            ->andWhere('l.lastSeen IS NOT NULL')
            ->addOrderBy('l.zip', 'ASC');

        if ($maxMonths) {
            $queryBuilder
                ->andWhere('l.lastSeen >= :minDate')
                ->setParameter('minDate', (new \DateTime())->modify("-$maxMonths months"));
        }

        $result = $queryBuilder
            ->getQuery()->getResult();

        $data = [];
        foreach ($result as $row) {
            $zip = substr($row['zip'], 0, 2).'xx';
            $data[$zip][] = ['price' => $row['priceCurrent'], 'area' => $row['area'], 'id' => $row['id']];
        }

        return $this->render('_scatter_chart.twig', ['scatterData' => $data, 'listing' => $listing]);
    }

    #[Route('/listings/scatter-chart-stats', name: 'scatter-chart-stats')]
    public function scatterChartStats(EntityManagerInterface $em): Response
    {
        $result = $em->getRepository(Listing::class)->createQueryBuilder('l')
            ->select('l.priceCurrent, l.area, l.firstSeen, l.lastSeen, l.id')
            ->andWhere('l.priceCurrent IS NOT NULL')
            ->andWhere('l.priceCurrent BETWEEN 1000 AND 1000000')
            ->andWhere('l.area IS NOT NULL')
            ->andWhere('l.area BETWEEN 90 AND 500')
            ->andWhere('l.lastSeen IS NOT NULL')
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

        return $this->render('_scatter_chart_stats.twig', ['scatterData' => $data]);
    }

}
