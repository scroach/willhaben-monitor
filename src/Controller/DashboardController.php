<?php

namespace App\Controller;

use App\Entity\Listing;
use App\Message\FetchAllListingsMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

class DashboardController extends AbstractController
{
    #[Route('/', name: 'dashboard')]
    public function index(EntityManagerInterface $em, Request $request): Response
    {
        $form = $this->createFormBuilder()
            ->add('willhabenId', TextType::class, ['attr' => ['class' => 'form-control', 'placeholder' => 'Willhaben ID']])
            ->add('submit', SubmitType::class, ['label' => 'Go', 'attr' => ['class' => 'btn btn-primary']])
            ->getForm();
        $form->handleRequest($request);
        if($form->isSubmitted() && $form->isValid()){
            $data = $form->getData();

            $listing = $em->getRepository(Listing::class)->findOneBy(['willhabenId' => $data['willhabenId']], ['lastSeen' => 'DESC']);
            if($listing) {
                return $this->redirectToRoute('details', ['id' => $listing->getId()]);
            } else {
                $this->addFlash('danger', 'no listing found');
            }
        }

        $showAll = $request->query->getBoolean('showAll');
        $sortBySale = $request->query->getBoolean('sortBySale');
        $showFresh = $request->query->getBoolean('showFresh');
        $showStarred = $request->query->getBoolean('showStarred');
        $sortByFirstSeen = $request->query->getBoolean('sortByFirstSeen');

        $from = (new \DateTime())->modify('-1 month');
        $to = (new \DateTime());
        if ($showStarred) {
            $listings = $em->getRepository(Listing::class)->findBy(['isStarred' => true]);
        } else if ($showAll) {
            $listings = $em->getRepository(Listing::class)->findBy([], ['lastSeen' => 'DESC']);
        } else if ($showFresh) {
            $listings = $em->getRepository(Listing::class)
                ->createQueryBuilder('l')
                ->andWhere('l.lastSeen > :fresh')->setParameter(':fresh', (new \DateTime())->modify('-7days'))
                ->orderBy('l.firstSeen', 'ASC')
                ->getQuery()->getResult();
        } else {
            $listings = $em->getRepository(Listing::class)->createQueryBuilder('l')
                ->andWhere('l.lastSeen >= :lastSeen')->setParameter('lastSeen', $from)
                ->orderBy('l.lastSeen', 'DESC')
                ->getQuery()->getResult();
        }

        if ($sortBySale) {
            usort($listings, fn (Listing $a, Listing $b) => $b->getSaleReduction() <=> $a->getSaleReduction());
        }

        return $this->render('dashboard.twig', [
            'listings' => $listings,
            'from' => $from,
            'to' => $to,
            'showAll' => $showAll,
            'showFresh' => $showFresh,
            'showStarred' => $showStarred,
            'sortByFirstSeen' => $sortByFirstSeen,
            'sortBySale' => $sortBySale,
            'form' => $form->createView(),
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
        array_walk($listings, fn(Listing $listing) => $listing->updateAggregatedDataFull());
        $em->flush();

        return $this->json(['count' => count($listings)]);
    }
}
