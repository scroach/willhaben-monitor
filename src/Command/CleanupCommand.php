<?php

namespace App\Command;

use App\Entity\Listing;
use App\Entity\ListingData;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:cleanup')]
class CleanupCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $totalDuplicates = 0;
        $lastListingId = 0;
        while (true) {
            /** @var Listing|null $listing */
            $listing = $this->em->getRepository(Listing::class)
                ->createQueryBuilder('l')
                ->andWhere('l.id > :lastId')
                ->setParameter('lastId', $lastListingId)
                ->setMaxResults(1)
                ->getQuery()->getOneOrNullResult();

            if (!$listing) {
                break;
            }

            $lastListingId = $listing->getId();

            $removable = $this->getRemovableListingData($listing);
            $totalDuplicates += count($removable);
            $output->writeln('Removable listing duplicate data: '.count($removable));
            array_walk($removable, fn (ListingData $d) => $this->em->remove($d));
            $this->em->flush();

            // clear em to prevent memory errors
            $this->em->clear();
        }

        $output->writeln('Final removable count: '.$totalDuplicates);
        $output->writeln('<info>DONE DONE!</info>');

        return Command::SUCCESS;
    }

    private function getRemovableListingData(Listing $listing): array
    {
        $listingData = $listing->getListingData()->toArray();
        usort($listingData, fn (ListingData $a, ListingData $b) => $a->getCreatedAt() <=> $b->getCreatedAt());

        $removables = [];
        $firstSame = null;
        $lastSame = null;
        foreach ($listingData as $current) {
            if($firstSame === null) {
                $firstSame = $current;
                continue;
            }

            if ($firstSame->getData() === $current->getData()) {
                if ($lastSame) {
                    // in between is removable, current becomes the new last
                    $removables[] = $lastSame;
                }
                $lastSame = $current;
            } else {
                $firstSame = $current;
                $lastSame = null;
            }
        }

        return $removables;
    }

}