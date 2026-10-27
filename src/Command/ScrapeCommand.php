<?php

namespace App\Command;

use App\ImmobilienScout24Scraper;
use App\WillhabenScraper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:scrape')]
class ScrapeCommand extends Command
{

    public function __construct(
        private WillhabenScraper $willhabenScraper,
        private ImmobilienScout24Scraper $immoscoutScraper,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Source to scrape: willhaben, immoscout or all', 'willhaben')
            ->addOption('max-pages', null, InputOption::VALUE_REQUIRED, 'Maximum pages per search for ImmoScout24 (0 = all pages)', '5')
            ->addOption('delay', null, InputOption::VALUE_REQUIRED, 'Minimum delay in seconds between ImmoScout24 requests', '2');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $source = (string) $input->getOption('source');

        if (!in_array($source, ['willhaben', 'immoscout', 'all'], true)) {
            $output->writeln('<error>Invalid source. Use willhaben, immoscout or all.</error>');

            return Command::INVALID;
        }

        if (in_array($source, ['willhaben', 'all'], true)) {
            $this->willhabenScraper->scrape();
        }

        if (in_array($source, ['immoscout', 'all'], true)) {
            $maxPages = max(0, (int) $input->getOption('max-pages'));
            $minDelay = max(0.0, (float) $input->getOption('delay'));

            $this->immoscoutScraper->scrape($maxPages, $minDelay, $minDelay + 2.0);
        }

        // return this if there was no problem running the command
        // (it's equivalent to returning int(0))
        return Command::SUCCESS;

        // or return this if some error happened during the execution
        // (it's equivalent to returning int(1))
        // return Command::FAILURE;

        // or return this to indicate incorrect command usage; e.g. invalid options
        // or missing arguments (it's equivalent to returning int(2))
        // return Command::INVALID
    }

}
