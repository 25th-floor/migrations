<?php

namespace TwentyFifth\Migrations\Command;

use Symfony\Component\Console;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use TwentyFifth\Migrations\Exception\RuntimeException;
use TwentyFifth\Migrations\Manager\ConfigManager\ConfigInterface;

class Apply
    extends AbstractCommand
{
    public function __construct(ConfigInterface $configManager, $fileManager)
    {
        parent::__construct($configManager, $fileManager, 'apply');

        $this->setDescription('Do ze magic migration stuff (aka. executing SQL scripts)');

        $this->addArgument(
            'what',
            InputArgument::REQUIRED,
            'What can I do for you? ["all", "next", <specific migration name>]',
            null
        );

        $this->addOption('only-mark', null, InputOption::VALUE_NONE, 'Only mark migration as done without executing it');
    }

    public function execute(Console\Input\InputInterface $input, Console\Output\OutputInterface $output)
    {
        try {
            parent::execute($input, $output);

            $target = $input->getArgument('what');
            $only_mark = $input->getOption('only-mark');

            switch ($target) {
                case 'next':
                    $this->migrateNext($output, $only_mark);
                    break;
                case 'all':
                    $this->migrateAll($output, $only_mark);
                    break;
                default:
                    $this->migrateByName($output, $only_mark, $target);
                    break;
            }
        } catch (RuntimeException $e) {
            return 1;
        }
    }

    protected function migrateNext(Console\Output\OutputInterface $output, $only_mark)
    {
        $missing_migrations = $this->getMissingMigrations();

        if (0 === count($missing_migrations)) {
            $output->writeln("All migration files are already applied.\nNothing to do");
            return;
        }

        // Choose next migration configuration
        $next_migration_short_name = array_shift(array_keys($missing_migrations));
        $next_migration_path = $missing_migrations[$next_migration_short_name];

        $sql = file_get_contents($next_migration_path);
        if ($only_mark) {
            $this->schema_manager->markMigration($next_migration_short_name);
            $output->writeln(sprintf('Migration %s marked as applied.', $next_migration_short_name));
        } else {
            $this->schema_manager->executeMigration($next_migration_short_name, $sql, $output);
        }
    }

    protected function migrateAll(Console\Output\OutputInterface $output, $only_mark)
    {
        $missing_migrations = $this->getMissingMigrations();

        if (0 === count($missing_migrations)) {
            $output->writeln("All migration files are already applied.\nNothing to do");
            return;
        }

        foreach ($missing_migrations as $shortname => $path) {
            $sql = file_get_contents($path);
            if ($only_mark) {
                $this->schema_manager->markMigration($shortname, $output);
                $output->writeln(sprintf('Migration %s marked as applied.', $shortname));
            } else {
                $this->schema_manager->executeMigration($shortname, $sql, $output);
            }
        }
    }

    protected function migrateByName(Console\Output\OutputInterface $output, $only_mark, $target)
    {
        $all_migrations = $this->file_manager->getOrderedFileList();

        if (!array_key_exists($target, $all_migrations)) {
            throw new RuntimeException("Migration $target was not found");
        }

        $missing_migrations = $this->schema_manager->getNotAppliedMigrations($all_migrations);

        if (!array_key_exists($target, $missing_migrations)) {
            throw new RuntimeException("Migration $target exists but is already applied.");
        }

        $sql = file_get_contents($missing_migrations[$target]);
        if ($only_mark) {
            $this->schema_manager->markMigration($target, $output);
            $output->writeln(sprintf('Migration %s marked as applied.', $target));
        } else {
            $this->schema_manager->executeMigration($target, $sql, $output);
        }
    }
}
