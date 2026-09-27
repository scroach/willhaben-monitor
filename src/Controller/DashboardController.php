<?php

namespace App\Controller;

use App\Entity\Listing;
use App\Message\FetchAllListingsMessage;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

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

        $totalCount = $em->getRepository(Listing::class)->count();
        $activeLastMonth = $em
            ->getRepository(Listing::class)->createQueryBuilder('l')
            ->select('count(l.id)')
            ->andWhere('l.lastSeen > :month')
            ->setParameter('month', new \DateTime('-1 month'))
            ->getQuery()->getSingleScalarResult();
        $soldLastMonth = $em
            ->getRepository(Listing::class)->createQueryBuilder('l')
            ->select('count(l.id)')
            ->andWhere('l.lastSeen > :month and l.lastSeen < :week')
            ->setParameter('month', new \DateTime('-1 month'))
            ->setParameter('week', new \DateTime('-1 week'))
            ->getQuery()->getSingleScalarResult();

        return $this->render('dashboard.twig', [
            'totalCount' => $totalCount,
            'activeLastMonth' => $activeLastMonth,
            'soldLastMonth' => $soldLastMonth,
            'form' => $form->createView(),
        ]);
    }
    #[Route('/starred', name: 'starred')]
    public function starred(EntityManagerInterface $em, Request $request): Response
    {
        return $this->render('listings.twig', [
            'listings' => $em->getRepository(Listing::class)->findBy(['isStarred' => true]),
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

    #[Route('/render-listing-rows', name: 'render_listing_rows')]
    public function renderListingRows(Request $request, EntityManagerInterface $em, Packages $assetManager): Response
    {
        $draw = $request->query->getInt('draw', 1);
        $start = $request->query->getInt('start', 0);
        $length = $request->query->getInt('length', 10);

        // Get search term from DataTables AJAX request
        $searchArray = $request->query->all('search');
        $searchValue = $searchArray['value'] ?? '';

        $orderArray = $request->query->all('order');
        $columnsArray = $request->query->all('columns');

        $qb = $em
            ->getRepository(Listing::class)->createQueryBuilder('l')
            ->select('l')
            ->setFirstResult($start)
            ->setMaxResults($length);

        if (!empty($searchValue)) {
            $qb->andWhere('l.title LIKE :search')
                ->setParameter('search', '%'.$searchValue.'%');
        }


        // 4. Handle Dynamic Ordering
        if (!empty($orderArray)) {
            foreach ($orderArray as $col) {
                $columnIndex = (int) $col['column']; // Index of the sorted column
                $sortDirection = $col['dir'] === 'desc' ? 'DESC' : 'ASC';
                // Map DataTables column configuration 'data' key to entity properties
                $sortColumnName = $columnsArray[$columnIndex]['data'] ?? null;

                // Whitelist valid columns to prevent DQL injection
                $allowedSortColumns = [
                    'id' => 'l.id',
                    'title' => 'l.title',
                    'firstSeen' => 'l.firstSeen',
                    'lastSeen' => 'l.lastSeen',
                    'price' => 'l.priceCurrent',
                    'area' => 'l.area',
                ];
                //TODO order by sale reduction is not working

                if (array_key_exists($sortColumnName, $allowedSortColumns)) {
                    $qb->addOrderBy($allowedSortColumns[$sortColumnName], $sortDirection);
                }
            }
        } else {
            $qb->orderBy('l.lastSeen', 'DESC');
        }


        $paginator = new Paginator($qb, fetchJoinCollection: true);
        // Total count without pagination limits
        $totalRecords = count($paginator);

        $data = [];
        /** @var Listing $listing */
        foreach ($paginator as $listing) {
            $url = $this->generateUrl('details', ['id' => $listing->getId()]);
            $imgUrl = $assetManager->getUrl('willhaben_images/'.$listing->getTitleImage());

            $title = <<<HTML
                <a href="$url">{$listing->getTitle()}</a>
                HTML;
            $img = <<<HTML
                <img alt="{$listing->getTitleImage()}" src="{$imgUrl}" style="max-width: 100px" loading="lazy" />
                HTML;

            $data[] = [
                'id' => $listing->getId(),
                'title' => $title,
                'area' => $listing->getArea(),
                'price' => $listing->getPriceCurrent(),
                'sale' => $listing->getSaleReduction(),
                'pricePerArea' => $listing->getPriceCurrentPerSqm(),
                'pricePerAreaMax' => $listing->getPriceCurrentPerSqm(),
                'pricePerAreaMin' => $listing->getPriceCurrentPerSqm(),
                'priceMax' => $listing->getPriceMax(),
                'priceMin' => $listing->getPriceMin(),
                'firstSeen' => $listing->getFirstSeen()->format('d.m.Y H:i:s'),
                'lastSeen' => $listing->getLastSeen()->format('d.m.Y H:i:s'),
                'ageInWeeks' => $listing->getAgeInWeeks(),
                'pics' => $img,
            ];
        }

        return new JsonResponse([
            'draw' => $draw,
            'recordsTotal' => $em->getRepository(Listing::class)->count([]),
            'recordsFiltered' => $totalRecords,
            'data' => $data,
        ]);
    }
}
