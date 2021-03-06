<?php

declare(strict_types=1);

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Enterprise License (PEL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 * @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 * @license    http://www.pimcore.org/license     GPLv3 and PEL
 */

namespace Pimcore\Documentation\Console;

use GitWrapper\GitWrapper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

class PrepareCommand extends Command
{
    /**
     * @var string
     */
    private $configPath;

    /**
     * @var string
     */
    private $buildPath;

    /**
     * @var SymfonyStyle
     */
    private $io;

    /**
     * @var Filesystem
     */
    private $fs;

    /**
     * @var array
     */
    private $availableConfigs;

    public function __construct($name = null)
    {
        $this->fs = new Filesystem();
        $this->initPaths();

        parent::__construct($name);
    }

    private function initPaths()
    {
        $basePath = realpath(__DIR__ . '/../../../..');

        $this->configPath = $basePath . '/config';
        $this->buildPath  = $basePath . '/build';
    }

    protected function configure()
    {
        $this
            ->setName('prepare')
            ->setDescription('Prepare filesystem to generate docs')
            ->addArgument(
                'sourcePath',
                InputArgument::REQUIRED,
                'Path to doc/ directory in the repository'
            );

        $this->addConfigOption();
    }

    private function addConfigOption()
    {
        $configs = $this->getAvailableConfigs();

        $description   = 'The config version to use.';
        $defaultConfig = null;

        if (!empty($configs)) {
            $description   = sprintf('%s Available configs: %s', $description, implode(', ', $configs));
            $defaultConfig = $configs[count($configs) - 1];
        }

        $this->addOption(
            'config', 'c',
            InputOption::VALUE_REQUIRED,
            $description,
            $defaultConfig
        );
    }

    private function getAvailableConfigs(): array
    {
        if (null !== $this->availableConfigs) {
            return $this->availableConfigs;
        }

        $this->availableConfigs = [];

        $finder = new Finder();
        $finder
            ->directories()
            ->in($this->configPath)
            ->depth(0)
            ->sortByName()
            ->ignoreDotFiles(true)
            ->ignoreUnreadableDirs(true);

        $configs    = [];
        $lastConfig = null;

        foreach ($finder as $dir) {
            $configs[] = $lastConfig = $dir->getFilename();
        }

        $this->availableConfigs = $configs;

        return $this->availableConfigs;
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->io = new SymfonyStyle($input, $output);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (empty($this->availableConfigs)) {
            $this->io->error('No available configs were found');

            return 1;
        }

        $sourcePath = $input->getArgument('sourcePath');
        if ($this->fs->exists($sourcePath)) {
            $this->io->writeln(sprintf('Using source path <comment>%s</comment>', $sourcePath));
        } else {
            $this->io->error(sprintf('The source path was not found in %s', $sourcePath));

            return 2;
        }

        $config     = $input->getOption('config');
        $configPath = $this->configPath . '/' . $config;
        $repositoryConfigPath = $sourcePath . '/.daux/' . $config;

        if (!$this->fs->exists($configPath) && !$this->fs->exists($repositoryConfigPath)) {
            $this->io->error(sprintf('The config paths %s do not exist, at least one has to be available.', $configPath . " and " . $repositoryConfigPath));

            return 3;
        }

        if ($this->fs->exists($this->buildPath)) {
            $this->io->writeln(sprintf('Cleaning up build dir <comment>%s</comment>', $this->buildPath));

            $this->fs->remove($this->buildPath);
        }

        $this->fs->mkdir($this->buildPath);

        $this->copyDocs($sourcePath, $this->buildPath);
        $this->createConfigFile($sourcePath, $this->buildPath, $this->configPath, $config);
    }

    private function copyDocs(string $docsPath, string $workDir)
    {
        $this->io->writeln(sprintf('Copying <comment>%s</comment> to work dir', $docsPath));

        $target = $workDir . '/docs';

        $this->fs->mkdir($target);
        $this->fs->mirror($docsPath, $target, null, [
            'override' => true,
            'delete'   => true
        ]);

        $this->renameReadmeFiles($target);
        $this->rewriteReadmeLinks($target);
    }

    private function renameReadmeFiles(string $directory)
    {
        $this->writeSection('Renaming README.md files to _index.md');

        $finder = new Finder();
        $finder
            ->files()
            ->in($directory)
            ->name('README.md');

        foreach ($finder as $file) {
            $source = $file->getPathname();
            $target = $file->getPath() . '/_index.md';

            if ($this->fs->exists($target)) {
                throw new \RuntimeException(sprintf(
                    'File %s already exists (tried to rename from %s)',
                    $target,
                    $source
                ));
            }

            $this->io->writeln(sprintf(
                'Moving <comment>%s</comment> to <comment>%s</comment>',
                $this->makePathRelative($source, $directory),
                $this->makePathRelative($target, $directory, true)
            ));

            $this->fs->rename($source, $target);
        }
    }

    private function rewriteReadmeLinks(string $directory)
    {
        $this->writeSection('Rewriting links to README.md files to _index.md');

        $finder = new Finder();
        $finder
            ->files()
            ->in($directory)
            ->name('*.md');

        foreach ($finder as $file) {
            $changed  = false;
            $contents = file_get_contents($file->getRealPath());

            // match all links to an internal README.md and rewrite them to _index.md
            if (preg_match_all('/\[(?P<text>([^\]]*))\]\((?P<link>([^\)]*)README\.md)\)/', $contents, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    // do not touch links with a scheme (e.g. external links to README.md files)
                    if (false !== strpos($match['link'], '://')) {
                        continue;
                    }

                    $link = preg_replace('/README.md$/', '_index.md', $match['link']);

                    $this->io->writeln(sprintf(
                        'Rewriting link from <comment>%s</comment> to <comment>%s</comment> in <comment>%s</comment>',
                        $match['link'],
                        $link,
                        $this->makePathRelative($file->getRealPath(), $directory)
                    ));

                    $replacement = str_replace($match['link'], $link, $match[0]);
                    $contents    = str_replace($match[0], $replacement, $contents);
                    $changed     = true;
                }
            }

            if ($changed) {
                $this->fs->dumpFile($file->getRealPath(), $contents);
            }
        }
    }

    private function createConfigFile(string $sourceDir, string $workDir, string $configDir, string $config)
    {
        $this->writeSection('Creating config file');

        $targetConfigFile   = $workDir . '/config.json';
        $defaultConfigFile  = $configDir . '/default.json';
        $selectedConfigFiles[] = $configDir . '/' . $config . '/config.json';
        $selectedConfigFiles[] = $sourceDir . '/.daux/' . $config . '/config.json';

        $versionConfig = $this->createVersionConfig($sourceDir, $configDir);
        $defaultConfig = $this->readJson($defaultConfigFile);
        $config        = array_merge($versionConfig, $defaultConfig);

        foreach($selectedConfigFiles  as $selectedConfigFile) {

            if ($this->fs->exists($selectedConfigFile)) {
                $this->io->writeln(sprintf(
                    'Merging config file <comment>%s</comment> with default config file <comment>%s</comment>',
                    $this->makePathRelative($selectedConfigFile, $configDir),
                    $this->makePathRelative($defaultConfigFile, $configDir)
                ));

                $selectedConfig = $this->readJson($selectedConfigFile);
                $config         = $this->array_overlay($config, $selectedConfig);
            }
        }

        $this->fs->dumpFile(
            $targetConfigFile,
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    private function array_overlay($a1,$a2)
    {
        foreach($a1 as $k => $v) {
            if (array_key_exists($k, $a2) && $a2[$k] == "::delete::") {
                echo "unset";
                unset($a1[$k]);
                continue;
            }
            if(!array_key_exists($k,$a2)) {
                continue;
            }

            if(is_array($v) && is_array($a2[$k])) {
                $a1[$k] = $this->array_overlay($v,$a2[$k]);
            } else {
                $a1[$k] = $a2[$k];
            }

        }

        foreach($a2 as $k => $v) {
            if(!array_key_exists($k, $a1) && $v !== "::delete::") {
                $a1[$k] = $v;
            }
        }

        return $a1;
    }

    private function createVersionConfig(string $sourceDir, string $configDir): array
    {
        return [
            'build_versions' => [
                'source' => $this->readGitVersionInfo($sourceDir),
                'docs'   => $this->readGitVersionInfo($configDir)
            ]
        ];
    }

    private function readGitVersionInfo(string $dir): string
    {
        $git = new GitWrapper();
        $git = $git->workingCopy($dir);

        return trim($git->run(['rev-parse', 'HEAD'])->getOutput());
    }

    private function readJson(string $path): array
    {
        if (!file_exists($path)) {
            throw new \InvalidArgumentException(sprintf('File %s does not exist', $path));
        }

        $content = file_get_contents($path);
        if (!$content) {
            throw new \RuntimeException(sprintf('Failed to read content from file %s', $path));
        }

        $json = json_decode($content, true);
        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new \RuntimeException(sprintf(
                'Failed to decode JSON from file %s: %s',
                $path,
                json_last_error_msg()
            ));
        }

        return $json;
    }

    private function makePathRelative(string $endPath, string $startPath, bool $forceFile = false): string
    {
        $path = $this->fs->makePathRelative($endPath, $startPath);

        if (is_file($endPath) || $forceFile) {
            $path = rtrim($path, '/');
        }

        return $path;
    }

    private function writeSection($message)
    {
        $this->io->section(sprintf('<fg=white>%s</>', $message));
    }
}

