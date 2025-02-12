<?php

namespace App\Command;

use App\Entity\Listing;
use App\Entity\ListingData;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:check-invalid')]
class CheckCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lastListingId =  0;
        while (true) {
            /** @var Listing|null $listing */
            $listing = $this->em->getRepository(Listing::class)
                ->createQueryBuilder('l')
                ->andWhere('l.id > :lastId')
                ->setParameter('lastId', $lastListingId)
                ->setMaxResults(1)
                ->getQuery()->getOneOrNullResult();

            if(!$listing) {
                break;
            }

            $lastListingId = $listing->getId();

            $ids = [];

            $output->write("Checking listing id {$listing->getId()}...");

            foreach ($listing->getListingData() as $data) {
                $ids[$data->getData()['id']] ??= 0;
                $ids[$data->getData()['id']]++;
            }

            $oldWillhabenId = $listing->getWillhabenId();
            arsort($ids);
            $mostProbableWillhabenId = array_keys($ids)[0];

            if ((int)$mostProbableWillhabenId !== (int)$oldWillhabenId) {
                $output->writeln('<error>NOK</error>');
                $output->write("Found mismatching multiple willhabenIds... using most used $mostProbableWillhabenId (old: $oldWillhabenId) ".json_encode($ids));
                $listing->setWillhabenId($mostProbableWillhabenId);
            } else {
                $output->write('<info>OK</info> ');
            }

            $invalidDatas = $listing->getListingData()->filter(fn(ListingData $listingData) => (int) $listingData->getData()['id'] !== $listing->getWillhabenId());
            if(!$invalidDatas->isEmpty()) {
                $output->writeln("Removing {$invalidDatas->count()} invalid listing datas");
                $invalidDatas->map(fn(ListingData $listingData) => $listing->removeListingData($listingData));
            }

            $output->write("Calculating aggregates...");
            $listing->updateAggregatedDataFull();
            $this->em->flush();
            $output->writeln('<info>DONE!</info>');

            // clear em to prevent memory errors
            $this->em->clear();
        }

        $output->writeln('<info>DONE DONE!</info>');

        return Command::SUCCESS;
    }

}