<?php

declare(strict_types=1);

namespace Rector\RectorGenerator\Command;

use Nette\Utils\Strings;
use Rector\RectorGenerator\Exception\ShouldNotHappenException;
use Rector\RectorGenerator\FileSystem\ConfigFilesystem;
use Rector\RectorGenerator\Finder\TemplateFinder;
use Rector\RectorGenerator\Generator\FileGenerator;
use Rector\RectorGenerator\Guard\OverrideGuard;
use Rector\RectorGenerator\Provider\RectorRecipeProvider;
use Rector\RectorGenerator\TemplateVariablesFactory;
use Rector\RectorGenerator\ValueObject\NamePattern;
use Rector\RectorGenerator\ValueObject\Option;
use Rector\RectorGenerator\ValueObject\RectorRecipe;
use Rector\RectorGenerator\ValueObjectFactory\RectorRecipeInteractiveFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symplify\PackageBuilder\Console\ShellCode;
use Symplify\SmartFileSystem\SmartFileInfo;

/**
 * @see \Rector\RectorGenerator\Tests\RectorGenerator\GenerateCommandInteractiveModeTest
 */
final class GenerateCommand extends Command
{
    /**
     * @var SymfonyStyle
     */
    private $symfonyStyle;

    /**
     * @var TemplateVariablesFactory
     */
    private $templateVariablesFactory;

    /**
     * @var TemplateFinder
     */
    private $templateFinder;

    /**
     * @var ConfigFilesystem
     */
    private $configFilesystem;

    /**
     * @var OverrideGuard
     */
    private $overrideGuard;

    /**
     * @var FileGenerator
     */
    private $fileGenerator;

    /**
     * @var RectorRecipeProvider
     */
    private $rectorRecipeProvider;

    /**
     * @var RectorRecipeInteractiveFactory
     */
    private $rectorRecipeInteractiveFactory;

    public function __construct(
        ConfigFilesystem $configFilesystem,
        FileGenerator $fileGenerator,
        OverrideGuard $overrideGuard,
        SymfonyStyle $symfonyStyle,
        TemplateFinder $templateFinder,
        TemplateVariablesFactory $templateVariablesFactory,
        RectorRecipeProvider $rectorRecipeProvider,
        RectorRecipeInteractiveFactory $rectorRecipeInteractiveFactory
    ) {
        parent::__construct();

        $this->templateVariablesFactory = $templateVariablesFactory;
        $this->templateFinder = $templateFinder;
        $this->configFilesystem = $configFilesystem;
        $this->overrideGuard = $overrideGuard;
        $this->symfonyStyle = $symfonyStyle;
        $this->fileGenerator = $fileGenerator;
        $this->rectorRecipeProvider = $rectorRecipeProvider;
        $this->rectorRecipeInteractiveFactory = $rectorRecipeInteractiveFactory;
    }

    protected function configure(): void
    {
        $this->setDescription('[DEV] Create a new Rector, in a proper location, with new tests');
        $this->addOption(
            Option::INTERACTIVE_MODE,
            'i',
            InputOption::VALUE_NONE,
            'Turns on Interactive Mode - Rector will be generated based on responses to questions instead of using rector-recipe.php',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rectorRecipe = $this->getRectorRecipe($input);

        $templateVariables = $this->templateVariablesFactory->createFromRectorRecipe($rectorRecipe);
        $templateFileInfos = $this->templateFinder->find($rectorRecipe);

        $targetDirectory = getcwd();

        $isUnwantedOverride = $this->overrideGuard->isUnwantedOverride(
            $templateFileInfos,
            $templateVariables,
            $rectorRecipe,
            $targetDirectory
        );

        if ($isUnwantedOverride) {
            $this->symfonyStyle->warning('No files were changed');
            return ShellCode::SUCCESS;
        }

        $generatedFilePaths = $this->fileGenerator->generateFiles(
            $templateFileInfos,
            $templateVariables,
            $rectorRecipe,
            $targetDirectory
        );

        $setFilePath = $rectorRecipe->getSetFilePath();
        if ($setFilePath) {
            $this->configFilesystem->appendRectorServiceToSet(
                $setFilePath,
                $templateVariables,
                NamePattern::RECTOR_FQN_NAME_PATTERN
            );
        }

        $testCaseDirectoryPath = $this->resolveTestCaseDirectoryPath($generatedFilePaths);
        $this->printSuccess($rectorRecipe->getName(), $generatedFilePaths, $testCaseDirectoryPath);

        return ShellCode::SUCCESS;
    }

    private function getRectorRecipe(InputInterface $input): RectorRecipe
    {
        $isInteractive = $input->getOption(Option::INTERACTIVE_MODE);
        if (! $isInteractive) {
            return $this->rectorRecipeProvider->provide();
        }

        return $this->rectorRecipeInteractiveFactory->create();
    }

    /**
     * @param string[] $generatedFilePaths
     */
    private function resolveTestCaseDirectoryPath(array $generatedFilePaths): string
    {
        foreach ($generatedFilePaths as $generatedFilePath) {
            if (! $this->isGeneratedFilePathTestCase($generatedFilePath)) {
                continue;
            }

            $generatedFileInfo = new SmartFileInfo($generatedFilePath);
            return dirname($generatedFileInfo->getRelativeFilePathFromCwd());
        }

        throw new ShouldNotHappenException();
    }

    /**
     * @param string[] $generatedFilePaths
     */
    private function printSuccess(string $name, array $generatedFilePaths, string $testCaseFilePath): void
    {
        $message = sprintf('New files generated for "%s":', $name);
        $this->symfonyStyle->title($message);

        sort($generatedFilePaths);

        foreach ($generatedFilePaths as $generatedFilePath) {
            $fileInfo = new SmartFileInfo($generatedFilePath);
            $relativeFilePath = $fileInfo->getRelativeFilePathFromCwd();
            $this->symfonyStyle->writeln(' * ' . $relativeFilePath);
        }

        $message = sprintf('Make tests green again:%svendor/bin/phpunit %s', PHP_EOL . PHP_EOL, $testCaseFilePath);

        $this->symfonyStyle->success($message);
    }

    private function isGeneratedFilePathTestCase(string $generatedFilePath): bool
    {
        if (Strings::endsWith($generatedFilePath, 'Test.php')) {
            return true;
        }

        if (! Strings::endsWith($generatedFilePath, 'Test.php.inc')) {
            return false;
        }

        return defined('PHPUNIT_COMPOSER_INSTALL');
    }
}
