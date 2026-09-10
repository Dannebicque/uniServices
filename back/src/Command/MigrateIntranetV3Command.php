<?php

namespace App\Command;

use App\Migration\IntranetV3\MigrationContext;
use App\Migration\IntranetV3\MigrationRegistry;
use App\Migration\IntranetV3\MigrationRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:migrate-intranet-v3',
    description: 'Migrates data from intranet V3 to uniServices.',
)]
final class MigrateIntranetV3Command extends Command
{
    public function __construct(
        private readonly MigrationRunner $runner,
        private readonly MigrationRegistry $registry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('migration', InputArgument::OPTIONAL, 'Migration name. Omit to run all migrations.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Run without writing data.')
            ->addOption('list', null, InputOption::VALUE_NONE, 'List available migrations.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('list')) {
            $names = array_keys($this->registry->all());
            sort($names);

            $io->listing($names ?: ['No migration registered.']);

            return Command::SUCCESS;
        }

        $migration = $input->getArgument('migration');
        $context = new MigrationContext(
            dryRun: (bool) $input->getOption('dry-run'),
            verbose: $output->isVerbose(),
        );

        try {
            $results = $this->runner->run($migration, $context);
        } catch (\InvalidArgumentException|\LogicException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        if ($results === []) {
            $io->warning('No migration is registered yet.');

            return Command::SUCCESS;
        }

        $rows = [];
        $hasFailure = false;
        foreach ($results as $name => $result) {
            $rows[] = [$name, $result->created, $result->updated, $result->skipped, $result->failed, $result->total()];
            $hasFailure = $hasFailure || $result->failed > 0;

            if ($context->verbose) {
                foreach ($result->messages as $message) {
                    $io->writeln(sprintf('<comment>%s:</comment> %s', $name, $message));
                }
            }
        }

        $io->table(['Migration', 'Created', 'Updated', 'Skipped', 'Failed', 'Total'], $rows);

        if ($context->dryRun) {
            $io->note('Dry-run enabled: migrators must not persist changes.');
        }

        return $hasFailure ? Command::FAILURE : Command::SUCCESS;
    }
}
