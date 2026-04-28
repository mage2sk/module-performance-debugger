<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */

declare(strict_types=1);

namespace Panth\PerformanceDebugger\Console\Command;

use Panth\PerformanceDebugger\Cron\CleanupRuns;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CleanupCommand extends Command
{
    public function __construct(
        private readonly CleanupRuns $cleanup
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('panth:perf:cleanup')
            ->setDescription('Delete profiler runs older than the configured retention.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->cleanup->execute();
        $output->writeln('<info>Profiler runs older than retention have been removed.</info>');
        return Command::SUCCESS;
    }
}
