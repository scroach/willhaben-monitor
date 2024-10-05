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
        $result = $em->getRepository(Listing::class)->createQueryBuilder('l')
            ->select('l.priceCurrent, l.area, l.zip, l.id')
            ->andWhere('l.priceCurrent IS NOT NULL')
            ->andWhere('l.priceCurrent BETWEEN 1000 AND 1000000')
            ->andWhere('l.area IS NOT NULL')
            ->andWhere('l.area BETWEEN 90 AND 500')
            ->andWhere('l.lastSeen IS NOT NULL')
            ->addOrderBy('l.zip', 'ASC')
            ->getQuery()->getResult();

        $data = [];
        foreach ($result as $row) {
            $zip = substr($row['zip'], 0, 2).'xx';
            $data[$zip][] = ['price' => $row['priceCurrent'], 'area' => $row['area'], 'id' => $row['id']];
        }

        $listing->updateAggregatedData();
        $em->flush();


        return $this->render('details.twig', ['listing' => $listing, 'scatterData' => $data]);
    }

    #[Route('/listings/{id}/fetch-images', name: 'fetch-images')]
    public function fetchImages(Listing $listing, MessageBusInterface $bus): Response
    {
        $bus->dispatch(new DownloadImagesMessage($listing->getId()));
        $this->addFlash('info', 'fetching of images triggered');

        return $this->redirectToRoute('details', ['id' => $listing->getId()]);
    }

}
