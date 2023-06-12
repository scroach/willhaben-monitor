<?php

namespace App\Controller;

use App\Entity\Listing;
use App\Message\FetchAllListingsMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

class DashboardController extends AbstractController
{
    #[Route('/', name: 'dashboard')]
    public function index(EntityManagerInterface $em): Response
    {
        $listings = $em->getRepository(Listing::class)->findBy([], ['lastSeen' => 'DESC']);

        return $this->render('dashboard.twig', ['listings' => $listings]);
    }

    #[Route('/dispatch', name: 'dispatch')]
    public function dispatch(MessageBusInterface $bus, EntityManagerInterface $em): Response
    {
        $bus->dispatch(new FetchAllListingsMessage('manual'));
        $em->flush();

        return $this->redirectToRoute('dashboard');
    }
}
