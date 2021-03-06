<?php
/**
 * @file
 * Contains \Drupal\Console\Command\Config\ImportCommand.
 */

namespace Drupal\Console\Command\Config;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Drupal\Console\Core\Command\Command;
use Drupal\Core\Config\CachedStorage;
use Drupal\Core\Config\ConfigManager;
use Drupal\Console\Core\Style\DrupalStyle;
use Drupal\Core\Config\ConfigImporterException;
use Drupal\Core\Config\ConfigImporter;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\StorageComparerInterface;

class ImportCommand extends Command
{
    /**
     * @var CachedStorage
     */
    protected $configStorage;

    /**
     * @var ConfigManager
     */
    protected $configManager;

    /**
     * ImportCommand constructor.
     *
     * @param CachedStorage $configStorage
     * @param ConfigManager $configManager
     */
    public function __construct(
        CachedStorage $configStorage,
        ConfigManager $configManager
    ) {
        $this->configStorage = $configStorage;
        $this->configManager = $configManager;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('config:import')
            ->setDescription($this->trans('commands.config.import.description'))
            ->addOption(
                'file',
                null,
                InputOption::VALUE_OPTIONAL,
                $this->trans('commands.config.import.options.file')
            )
            ->addOption(
                'directory',
                null,
                InputOption::VALUE_OPTIONAL,
                $this->trans('commands.config.import.options.directory')
            )
            ->addOption(
                'remove-files',
                null,
                InputOption::VALUE_NONE,
                $this->trans('commands.config.import.options.remove-files')
            )
            ->addOption(
                'skip-uuid',
                null,
                InputOption::VALUE_NONE,
                $this->trans('commands.config.import.options.skip-uuid')
            )
            ->setAliases(['ci']);
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new DrupalStyle($input, $output);
        $directory = $input->getOption('directory');
        $skipUuid = $input->getOption('skip-uuid');

        if ($directory) {
            $configSyncDir = $directory;
        } else {
            $configSyncDir = config_get_config_directory(
                CONFIG_SYNC_DIRECTORY
            );
        }

        $source_storage = new FileStorage($configSyncDir);

        $storageComparer = '\Drupal\Core\Config\StorageComparer';
        if ($skipUuid) {
            $storageComparer = '\Drupal\Console\Override\StorageComparer';
        }

        $storage_comparer = new $storageComparer(
            $source_storage,
            $this->configStorage,
            $this->configManager
        );

        if (!$storage_comparer->createChangelist()->hasChanges()) {
            $io->success($this->trans('commands.config.import.messages.nothing-to-do'));
        }

        if ($this->configImport($io, $storage_comparer)) {
            $io->success($this->trans('commands.config.import.messages.imported'));
        } else {
            return 1;
        }
    }


    private function configImport(DrupalStyle $io, StorageComparerInterface $storage_comparer)
    {
        $config_importer = new ConfigImporter(
            $storage_comparer,
            \Drupal::service('event_dispatcher'),
            \Drupal::service('config.manager'),
            \Drupal::lock(),
            \Drupal::service('config.typed'),
            \Drupal::moduleHandler(),
            \Drupal::service('module_installer'),
            \Drupal::service('theme_handler'),
            \Drupal::service('string_translation')
        );

        if ($config_importer->alreadyImporting()) {
            $io->success($this->trans('commands.config.import.messages.already-imported'));
        } else {
            try {
                $io->info($this->trans('commands.config.import.messages.importing'));
                $config_importer->import();
                return true;
            } catch (ConfigImporterException $e) {
                $io->error(
                    sprintf(
                        $this->trans('commands.site.import.local.messages.error-writing'),
                        implode("\n", $config_importer->getErrors())
                    )
                );
            } catch (\Exception $e) {
                $io->error(
                    sprintf(
                        $this->trans('commands.site.import.local.messages.error-writing'),
                        $e->getMessage()
                    )
                );
            }
        }
    }
}
