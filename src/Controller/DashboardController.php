<?php

namespace App\Controller;

use App\Entity\Listing;
use App\Message\FetchAllListingsMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

class DashboardController extends AbstractController
{
    #[Route('/', name: 'dashboard')]
    public function index(EntityManagerInterface $em, Request $request): Response
    {
        $showAll = false;
        $from = (new \DateTime())->modify('-1 month');
        $to = (new \DateTime());
        if ($request->query->has('showAll')) {
            $showAll = true;
            $listings = $em->getRepository(Listing::class)->findBy([], ['lastSeen' => 'DESC']);
        } else {
            $listings = $em->getRepository(Listing::class)->createQueryBuilder('l')
                ->andWhere('l.lastSeen >= :lastSeen')->setParameter('lastSeen', $from)
                ->orderBy('l.lastSeen', 'DESC')
                ->getQuery()->getResult();
        }

        return $this->render('dashboard.twig', [
            'listings' => $listings,
            'from' => $from,
            'to' => $to,
            'showAll' => $showAll,
        ]);
    }

    #[Route('/dispatch', name: 'dispatch')]
    public function dispatch(MessageBusInterface $bus, EntityManagerInterface $em): Response
    {
        $bus->dispatch(new FetchAllListingsMessage('manual'));
        $em->flush();

        return $this->redirectToRoute('dashboard');
    }

    #[Route('/update-aggregated-data', name: 'updateaggregateddata')]
    public function updateAggregatedData(EntityManagerInterface $em): Response
    {
        $listings = $em->getRepository(Listing::class)->findBy(['city' => null], [], 100);
        array_walk($listings, fn(Listing $listing) => $listing->updateAggregatedData());
        $em->flush();

        return $this->json(['count' => count($listings)]);
    }
}
