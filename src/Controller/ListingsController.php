<?php

namespace App\Controller;

use App\Entity\Listing;
use App\Message\DownloadImagesMessage;
use Doctrine\ORM\EntityManagerInterface;
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
    public function details(Listing $listing, EntityManagerInterface $em): Response
    {
        $listing->updateAggregatedData();
        $em->flush();


        return $this->render('details.twig', ['listing' => $listing]);
    }

    #[Route('/listings/{id}/fetch-images', name: 'fetch-images')]
    public function fetchImages(Listing $listing, MessageBusInterface $bus): Response
    {
        $bus->dispatch(new DownloadImagesMessage($listing->getId()));
        $this->addFlash('info', 'fetching of images triggered');

        return $this->redirectToRoute('details', ['id' => $listing->getId()]);
    }

}
