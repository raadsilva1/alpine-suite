#!/usr/bin/env php
<?php

declare(strict_types=1);

final class ExitCode
{
    public const SUCCESS = 0;
    public const GENERAL_ERROR = 1;
    public const INPUT_ERROR = 2;
    public const PREREQUISITE_ERROR = 3;
    public const PERMISSION_ERROR = 4;
    public const COLLISION_ERROR = 5;
    public const INSTALLATION_ERROR = 6;
}

final class FilePermissions
{
    public const EXECUTABLE = 0755;
    public const DIRECTORY_PRIVATE = 0700;
}

final class Configuration
{
    public const INSTALLATION_DIRECTORY = '/usr/local/bin';
    public const TEMPORARY_DIRECTORY = '/tmp';
    public const WORKING_DIRECTORY_PREFIX = 'alpine-suite-';
    public const PHP_SHEBANG = "#!/usr/bin/env php\n";
    public const PHP_FILE_EXTENSION = 'php';
    public const MINIMUM_PHP_VERSION_ID = 80400;
    public const RANDOM_SUFFIX_BYTES = 8;
    public const SHORT_RANDOM_SUFFIX_BYTES = 4;
    public const MAXIMUM_DIRECTORY_CREATION_ATTEMPTS = 10;
}

final class BootstrapPackages
{
    public const SYSTEM_DEPENDENCIES = [
        'git',
        'curl',
        'ca-certificates',
    ];

    public const PHP_CORE = [
        'php84',
        'php84-common',
        'php84-phar',
        'php84-iconv',
        'php84-mbstring',
        'php84-openssl',
        'php84-tokenizer',
        'php84-ctype',
        'php84-fileinfo',
        'php84-session',
        'php84-json',
    ];

    public const PHP_DATABASE = [
        'php84-pdo',
        'php84-pdo_mysql',
        'php84-pdo_pgsql',
        'php84-pdo_sqlite',
        'php84-mysqli',
        'php84-pgsql',
        'php84-sqlite3',
        'php84-ldap',
    ];

    public const PHP_XML = [
        'php84-xml',
        'php84-dom',
        'php84-simplexml',
        'php84-xmlwriter',
        'php84-xmlreader',
        'php84-xsl',
        'php84-soap',
    ];

    public const PHP_NETWORK = [
        'php84-curl',
        'php84-sockets',
        'php84-ftp',
    ];

    public const PHP_WEB = [
        'php84-fpm',
        'php84-cgi',
    ];

    public const PHP_CACHING = [
        'php84-opcache',
        'php84-pecl-apcu',
        'php84-pecl-redis',
        'php84-pecl-memcached',
    ];

    public const PHP_COMPRESSION = [
        'php84-zip',
        'php84-bz2',
        'php84-zlib',
    ];

    public const PHP_UTILITIES = [
        'php84-gd',
        'php84-intl',
        'php84-bcmath',
        'php84-gmp',
        'php84-sodium',
        'php84-exif',
        'php84-calendar',
        'php84-gettext',
        'php84-pcntl',
        'php84-posix',
        'php84-pecl-imagick',
    ];

    public static function getAllPackages(): array
    {
        return array_merge(
            self::SYSTEM_DEPENDENCIES,
            self::PHP_CORE,
            self::PHP_DATABASE,
            self::PHP_XML,
            self::PHP_NETWORK,
            self::PHP_WEB,
            self::PHP_CACHING,
            self::PHP_COMPRESSION,
            self::PHP_UTILITIES,
        );
    }

    public static function getPackageCategories(): array
    {
        return [
            'System Dependencies' => self::SYSTEM_DEPENDENCIES,
            'PHP Core Extensions' => self::PHP_CORE,
            'Database Extensions' => self::PHP_DATABASE,
            'XML Extensions' => self::PHP_XML,
            'Network Extensions' => self::PHP_NETWORK,
            'Web Server Extensions' => self::PHP_WEB,
            'Caching Extensions' => self::PHP_CACHING,
            'Compression Extensions' => self::PHP_COMPRESSION,
            'Utility Extensions' => self::PHP_UTILITIES,
        ];
    }
}

final class ForbiddenCommandNames
{
    public const LIST = ['test', '.', '..', 'sh', 'bash', 'ash', 'busybox'];
}

final class TerminalStyle
{
    public const RESET = "\033[0m";
    public const BOLD = "\033[1m";
    public const DIM = "\033[2m";

    public const RED = "\033[31m";
    public const GREEN = "\033[32m";
    public const YELLOW = "\033[33m";
    public const BLUE = "\033[34m";
    public const CYAN = "\033[36m";
    public const WHITE = "\033[37m";
}

final class ConfigurationGenerator
{
    private InteractivePrompt $prompt;

    /** @var string[] */
    private array $repositories = [];

    public function __construct()
    {
        $this->prompt = new InteractivePrompt();
    }

    public function run(): int
    {
        $this->prompt->printHeader();
        $this->prompt->printInstructions();
        $this->prompt->printSeparator();
        $this->prompt->printLine();

        $this->collectRepositoryUrls();

        if (empty($this->repositories)) {
            $this->prompt->printWarning('No repositories added. Exiting.');
            return ExitCode::SUCCESS;
        }

        $this->prompt->printLine();
        $this->prompt->printSeparator();
        $this->prompt->printLine();

        $this->prompt->printRepositoryList($this->repositories);

        $this->prompt->printLine();

        $outputPath = $this->prompt->promptForOutputPath();

        if ($outputPath === null) {
            $this->prompt->printError('Cancelled.');
            return ExitCode::INPUT_ERROR;
        }

        if (file_exists($outputPath)) {
            $overwrite = $this->prompt->promptForConfirmation("File '{$outputPath}' exists. Overwrite?");

            if (!$overwrite) {
                $this->prompt->printWarning('Cancelled. File not overwritten.');
                return ExitCode::SUCCESS;
            }
        }

        return $this->writeConfigurationFile($outputPath);
    }

    private function collectRepositoryUrls(): void
    {
        while (true) {
            $input = $this->prompt->promptForRepository(count($this->repositories));

            if ($input === null) {
                break;
            }

            if ($input === '') {
                if (!empty($this->repositories)) {
                    break;
                }
                $this->prompt->printWarning('Please add at least one repository.');
                continue;
            }

            $command = strtolower($input);

            if ($command === 'quit' || $command === 'exit') {
                $this->repositories = [];
                break;
            }

            if ($command === 'undo') {
                $this->handleUndoCommand();
                continue;
            }

            if ($command === 'list') {
                $this->prompt->printLine();
                $this->prompt->printRepositoryList($this->repositories);
                $this->prompt->printLine();
                continue;
            }

            if ($command === 'clear') {
                $this->handleClearCommand();
                continue;
            }

            $this->handleRepositoryInput($input);
        }
    }

    private function handleUndoCommand(): void
    {
        if (empty($this->repositories)) {
            $this->prompt->printWarning('Nothing to undo.');
            return;
        }

        $removed = array_pop($this->repositories);
        $this->prompt->printInfo("Removed: {$removed}");
    }

    private function handleClearCommand(): void
    {
        if (empty($this->repositories)) {
            $this->prompt->printWarning('List is already empty.');
            return;
        }

        $count = count($this->repositories);
        $this->repositories = [];
        $this->prompt->printInfo("Cleared {$count} repository URL(s).");
    }

    private function handleRepositoryInput(string $input): void
    {
        if (!$this->isValidGitRepositoryUrl($input)) {
            $this->prompt->printError('Invalid Git URL. Use https:// or git:// format.');
            return;
        }

        if (in_array($input, $this->repositories, true)) {
            $this->prompt->printWarning('Repository already in list.');
            return;
        }

        $this->repositories[] = $input;
        $this->prompt->printSuccess("Added: {$input}");
    }

    private function isValidGitRepositoryUrl(string $url): bool
    {
        $httpsPattern = '#^https://[a-zA-Z0-9._-]+/[a-zA-Z0-9._/-]+(?:\.git)?$#';
        $gitProtocolPattern = '#^git://[a-zA-Z0-9._-]+/[a-zA-Z0-9._/-]+(?:\.git)?$#';

        return preg_match($httpsPattern, $url) === 1
            || preg_match($gitProtocolPattern, $url) === 1;
    }

    private function writeConfigurationFile(string $outputPath): int
    {
        $configuration = [
            'repos' => $this->repositories
        ];

        $jsonContent = json_encode($configuration, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($jsonContent === false) {
            $this->prompt->printError('Failed to encode JSON.');
            return ExitCode::GENERAL_ERROR;
        }

        $jsonContent .= "\n";

        $bytesWritten = file_put_contents($outputPath, $jsonContent);

        if ($bytesWritten === false) {
            $this->prompt->printError("Failed to write file: {$outputPath}");
            return ExitCode::GENERAL_ERROR;
        }

        $this->prompt->printLine();
        $this->prompt->printSuccess("Configuration saved to: {$outputPath}");
        $this->prompt->printLine();
        $this->prompt->printInfo("Run: alpine-suite {$outputPath}");
        $this->prompt->printLine();

        return ExitCode::SUCCESS;
    }
}

final class BootstrapStatistics
{
    public int $totalPackages = 0;
    public int $installedPackages = 0;
    public int $skippedPackages = 0;
    public int $failedPackages = 0;

    /** @var string[] */
    public array $failedPackageNames = [];

    /** @var string[] */
    public array $skippedPackageNames = [];
}

final class SystemBootstrapper
{
    private InteractivePrompt $prompt;
    private BootstrapStatistics $statistics;
    private ?string $apkPath = null;

    public function __construct()
    {
        $this->prompt = new InteractivePrompt();
        $this->statistics = new BootstrapStatistics();
    }

    public function run(): int
    {
        $this->prompt->printBootstrapHeader();

        if (!$this->verifyRootPrivileges()) {
            return ExitCode::PERMISSION_ERROR;
        }

        if (!$this->detectAndValidateApk()) {
            return ExitCode::PREREQUISITE_ERROR;
        }

        $this->prompt->printLine();
        $this->displayPackagePlan();

        if (!$this->prompt->promptForConfirmation('Proceed with installation?')) {
            $this->prompt->printWarning('Bootstrap cancelled by user.');
            return ExitCode::SUCCESS;
        }

        $this->prompt->printLine();
        $this->prompt->printSeparator();
        $this->prompt->printLine();

        if (!$this->updateRepositories()) {
            return ExitCode::GENERAL_ERROR;
        }

        $this->prompt->printLine();

        $installationResult = $this->installPackages();

        $this->prompt->printLine();
        $this->printSummary();

        return $installationResult;
    }

    private function verifyRootPrivileges(): bool
    {
        $this->prompt->printPhase('Checking privileges');

        $result = $this->executeCommand(['id', '-u']);

        if (!$result->succeeded()) {
            $this->prompt->printError('Failed to determine user privileges.');
            return false;
        }

        $effectiveUserId = (int)trim($result->standardOutput);

        if ($effectiveUserId !== 0) {
            $this->prompt->printError('Root privileges required. Run with: doas alpine-suite --bootstrap');
            return false;
        }

        $this->prompt->printSuccess('Running as root');
        return true;
    }

    private function detectAndValidateApk(): bool
    {
        $this->prompt->printPhase('Detecting package manager');

        $possiblePaths = [
            '/sbin/apk',
            '/usr/sbin/apk',
            '/bin/apk',
            '/usr/bin/apk',
        ];

        foreach ($possiblePaths as $path) {
            if (is_executable($path)) {
                $this->apkPath = $path;
                break;
            }
        }

        if ($this->apkPath === null) {
            $pathEnv = getenv('PATH') ?: '/sbin:/usr/sbin:/bin:/usr/bin';
            $searchPaths = explode(':', $pathEnv);

            foreach ($searchPaths as $directory) {
                $candidate = rtrim($directory, '/') . '/apk';
                if (is_executable($candidate)) {
                    $this->apkPath = $candidate;
                    break;
                }
            }
        }

        if ($this->apkPath === null) {
            $this->prompt->printError('apk package manager not found. Is this Alpine Linux?');
            return false;
        }

        $this->prompt->printSuccess("Found apk: {$this->apkPath}");

        $versionResult = $this->executeCommand([$this->apkPath, '--version']);

        if (!$versionResult->succeeded()) {
            $this->prompt->printError('Failed to execute apk. Binary may be corrupted.');
            return false;
        }

        $versionOutput = trim($versionResult->standardOutput);
        $this->prompt->printInfo("Version: {$versionOutput}");

        return true;
    }

    private function displayPackagePlan(): void
    {
        $this->prompt->printPhase('Installation Plan');
        $this->prompt->printLine();

        $categories = BootstrapPackages::getPackageCategories();
        $totalPackages = 0;

        foreach ($categories as $categoryName => $packages) {
            $count = count($packages);
            $totalPackages += $count;

            $this->prompt->printCategory($categoryName, $count);

            foreach ($packages as $package) {
                $this->prompt->printPackageItem($package);
            }

            $this->prompt->printLine();
        }

        $this->statistics->totalPackages = $totalPackages;

        $this->prompt->printInfo("Total packages to install: {$totalPackages}");
        $this->prompt->printLine();
    }

    private function updateRepositories(): bool
    {
        $this->prompt->printPhase('Updating package repositories');

        $this->prompt->printProgressBar(0, 1, 'Fetching repository index...');

        $result = $this->executeCommand([$this->apkPath, 'update']);

        if (!$result->succeeded()) {
            $this->prompt->clearLine();
            $this->prompt->printError('Failed to update repositories: ' . $result->getErrorMessage());
            return false;
        }

        $this->prompt->clearLine();
        $this->prompt->printSuccess('Package repositories updated');

        return true;
    }

    private function installPackages(): int
    {
        $this->prompt->printPhase('Installing packages');
        $this->prompt->printLine();

        $allPackages = BootstrapPackages::getAllPackages();
        $totalPackages = count($allPackages);
        $currentPackage = 0;

        foreach ($allPackages as $package) {
            $currentPackage++;

            $this->prompt->printProgressBar($currentPackage, $totalPackages, $package);

            $installResult = $this->installSinglePackage($package);

            $this->prompt->clearLine();

            if ($installResult === 'installed') {
                $this->statistics->installedPackages++;
                $this->prompt->printPackageInstalled($package);
            } elseif ($installResult === 'skipped') {
                $this->statistics->skippedPackages++;
                $this->statistics->skippedPackageNames[] = $package;
                $this->prompt->printPackageSkipped($package);
            } else {
                $this->statistics->failedPackages++;
                $this->statistics->failedPackageNames[] = $package;
                $this->prompt->printPackageFailed($package);
            }
        }

        if ($this->statistics->failedPackages > 0) {
            return ExitCode::GENERAL_ERROR;
        }

        return ExitCode::SUCCESS;
    }

    private function installSinglePackage(string $package): string
    {
        $checkResult = $this->executeCommand([$this->apkPath, 'info', '-e', $package]);

        if ($checkResult->succeeded()) {
            return 'skipped';
        }

        $installResult = $this->executeCommand([
            $this->apkPath, 'add', '--no-progress', $package
        ]);

        if ($installResult->succeeded()) {
            return 'installed';
        }

        return 'failed';
    }

    private function executeCommand(array $commandArguments): CommandExecutionResult
    {
        $processDescriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $processHandle = proc_open($commandArguments, $processDescriptors, $pipes);

        if (!is_resource($processHandle)) {
            return new CommandExecutionResult(
                exitCode: -1,
                standardOutput: '',
                standardError: 'Failed to execute command',
            );
        }

        fclose($pipes[0]);

        $standardOutput = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $standardError = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($processHandle);

        return new CommandExecutionResult(
            exitCode: $exitCode,
            standardOutput: $standardOutput ?: '',
            standardError: $standardError ?: '',
        );
    }

    private function printSummary(): void
    {
        $this->prompt->printSeparator();
        $this->prompt->printLine();
        $this->prompt->printPhase('Bootstrap Summary');
        $this->prompt->printLine();

        $this->prompt->printSummaryLine('Total packages', $this->statistics->totalPackages);
        $this->prompt->printSummaryLine('Installed', $this->statistics->installedPackages, TerminalStyle::GREEN);
        $this->prompt->printSummaryLine('Already present', $this->statistics->skippedPackages, TerminalStyle::CYAN);
        $this->prompt->printSummaryLine('Failed', $this->statistics->failedPackages, TerminalStyle::RED);

        if (!empty($this->statistics->failedPackageNames)) {
            $this->prompt->printLine();
            $this->prompt->printWarning('Failed packages:');
            foreach ($this->statistics->failedPackageNames as $package) {
                $this->prompt->printPackageItem($package);
            }
        }

        $this->prompt->printLine();

        if ($this->statistics->failedPackages === 0) {
            $this->prompt->printSuccess('Bootstrap completed successfully!');
            $this->prompt->printLine();
            $this->prompt->printInfo('PHP 8.4 and common extensions are now installed.');
            $this->prompt->printInfo('Verify with: php -v && php -m');
        } else {
            $this->prompt->printError('Bootstrap completed with errors.');
            $this->prompt->printInfo('Some packages failed to install. Check package names and repositories.');
        }

        $this->prompt->printLine();
    }
}

final class InteractivePrompt
{
    private bool $supportsColor;

    public function __construct()
    {
        $this->supportsColor = $this->detectColorSupport();
    }

    private function detectColorSupport(): bool
    {
        if (getenv('NO_COLOR') !== false) {
            return false;
        }

        if (!stream_isatty(STDOUT)) {
            return false;
        }

        $term = getenv('TERM');

        return $term !== false && $term !== 'dumb';
    }

    private function style(string $text, string ...$styles): string
    {
        if (!$this->supportsColor) {
            return $text;
        }

        $styleSequence = implode('', $styles);

        return $styleSequence . $text . TerminalStyle::RESET;
    }

    public function printHeader(): void
    {
        $this->printLine();
        $this->printLine($this->style('╔══════════════════════════════════════════════════════════════╗', TerminalStyle::CYAN));
        $this->printLine($this->style('║', TerminalStyle::CYAN) . $this->style('          alpine-suite Configuration Generator              ', TerminalStyle::BOLD) . $this->style('║', TerminalStyle::CYAN));
        $this->printLine($this->style('╚══════════════════════════════════════════════════════════════╝', TerminalStyle::CYAN));
        $this->printLine();
    }

    public function printInstructions(): void
    {
        $this->printLine($this->style('Instructions:', TerminalStyle::BOLD, TerminalStyle::YELLOW));
        $this->printLine($this->style('  • ', TerminalStyle::DIM) . 'Enter Git repository URLs one at a time');
        $this->printLine($this->style('  • ', TerminalStyle::DIM) . 'Press ' . $this->style('Enter', TerminalStyle::BOLD) . ' with empty input when finished');
        $this->printLine($this->style('  • ', TerminalStyle::DIM) . 'Type ' . $this->style('undo', TerminalStyle::BOLD) . ' to remove the last entry');
        $this->printLine($this->style('  • ', TerminalStyle::DIM) . 'Type ' . $this->style('list', TerminalStyle::BOLD) . ' to see current entries');
        $this->printLine($this->style('  • ', TerminalStyle::DIM) . 'Type ' . $this->style('clear', TerminalStyle::BOLD) . ' to start over');
        $this->printLine($this->style('  • ', TerminalStyle::DIM) . 'Type ' . $this->style('quit', TerminalStyle::BOLD) . ' to cancel');
    }

    public function printRepositoryList(array $repositories): void
    {
        if (empty($repositories)) {
            $this->printLine($this->style('  (no repositories added yet)', TerminalStyle::DIM));
            return;
        }

        $this->printLine($this->style('Current repositories:', TerminalStyle::BOLD));

        foreach ($repositories as $index => $repository) {
            $number = $index + 1;
            $this->printLine(
                $this->style("  {$number}. ", TerminalStyle::CYAN) .
                $this->style($repository, TerminalStyle::WHITE)
            );
        }
    }

    public function promptForRepository(int $currentCount): ?string
    {
        $displayNumber = $currentCount + 1;

        $prompt = $this->style('[', TerminalStyle::DIM) .
                  $this->style((string)$displayNumber, TerminalStyle::CYAN, TerminalStyle::BOLD) .
                  $this->style(']', TerminalStyle::DIM) .
                  $this->style(' Repository URL: ', TerminalStyle::WHITE);

        fwrite(STDOUT, $prompt);

        $input = fgets(STDIN);

        if ($input === false) {
            return null;
        }

        return trim($input);
    }

    public function promptForOutputPath(): ?string
    {
        $defaultPath = 'repos.json';

        $prompt = $this->style('Output file', TerminalStyle::WHITE) .
                  $this->style(" [{$defaultPath}]: ", TerminalStyle::DIM);

        fwrite(STDOUT, $prompt);

        $input = fgets(STDIN);

        if ($input === false) {
            return null;
        }

        $input = trim($input);

        return $input !== '' ? $input : $defaultPath;
    }

    public function promptForConfirmation(string $question): bool
    {
        $prompt = $this->style($question, TerminalStyle::YELLOW) .
                  $this->style(' [Y/n]: ', TerminalStyle::DIM);

        fwrite(STDOUT, $prompt);

        $input = fgets(STDIN);

        if ($input === false) {
            return false;
        }

        $normalizedInput = strtolower(trim($input));

        return $normalizedInput === '' || $normalizedInput === 'y' || $normalizedInput === 'yes';
    }

    public function printSuccess(string $message): void
    {
        $symbol = $this->supportsColor ? '✓' : '[OK]';
        $this->printLine($this->style("{$symbol} ", TerminalStyle::GREEN, TerminalStyle::BOLD) . $message);
    }

    public function printError(string $message): void
    {
        $symbol = $this->supportsColor ? '✗' : '[ERROR]';
        $this->printLine($this->style("{$symbol} ", TerminalStyle::RED, TerminalStyle::BOLD) . $message);
    }

    public function printWarning(string $message): void
    {
        $symbol = $this->supportsColor ? '⚠' : '[WARN]';
        $this->printLine($this->style("{$symbol} ", TerminalStyle::YELLOW, TerminalStyle::BOLD) . $message);
    }

    public function printInfo(string $message): void
    {
        $symbol = $this->supportsColor ? 'ℹ' : '[INFO]';
        $this->printLine($this->style("{$symbol} ", TerminalStyle::BLUE, TerminalStyle::BOLD) . $message);
    }

    public function printLine(string $message = ''): void
    {
        fwrite(STDOUT, $message . "\n");
    }

    public function printSeparator(): void
    {
        $line = str_repeat('─', 64);
        $this->printLine($this->style($line, TerminalStyle::DIM));
    }

    public function printBootstrapHeader(): void
    {
        $this->printLine();
        $this->printLine($this->style('╔══════════════════════════════════════════════════════════════╗', TerminalStyle::CYAN));
        $this->printLine($this->style('║', TerminalStyle::CYAN) . $this->style('            alpine-suite System Bootstrap                    ', TerminalStyle::BOLD) . $this->style('║', TerminalStyle::CYAN));
        $this->printLine($this->style('║', TerminalStyle::CYAN) . $this->style('         PHP 8.4 Environment Installer for Alpine           ', TerminalStyle::DIM) . $this->style('║', TerminalStyle::CYAN));
        $this->printLine($this->style('╚══════════════════════════════════════════════════════════════╝', TerminalStyle::CYAN));
        $this->printLine();
    }

    public function printPhase(string $phaseName): void
    {
        $arrow = $this->supportsColor ? '▶' : '=>';
        $this->printLine($this->style("{$arrow} {$phaseName}", TerminalStyle::BOLD, TerminalStyle::CYAN));
    }

    public function printCategory(string $categoryName, int $packageCount): void
    {
        $bullet = $this->supportsColor ? '●' : '*';
        $this->printLine(
            $this->style("  {$bullet} ", TerminalStyle::YELLOW) .
            $this->style($categoryName, TerminalStyle::BOLD) .
            $this->style(" ({$packageCount} packages)", TerminalStyle::DIM)
        );
    }

    public function printPackageItem(string $packageName): void
    {
        $this->printLine($this->style("      └─ {$packageName}", TerminalStyle::DIM));
    }

    public function printProgressBar(int $current, int $total, string $currentItem): void
    {
        $percentage = $total > 0 ? (int)(($current / $total) * 100) : 0;
        $barWidth = 30;
        $filledWidth = (int)(($current / $total) * $barWidth);
        $emptyWidth = $barWidth - $filledWidth;

        $filledChar = $this->supportsColor ? '█' : '#';
        $emptyChar = $this->supportsColor ? '░' : '-';

        $filledBar = str_repeat($filledChar, $filledWidth);
        $emptyBar = str_repeat($emptyChar, $emptyWidth);

        $progressText = sprintf('[%s%s] %3d%% (%d/%d) %s',
            $this->style($filledBar, TerminalStyle::GREEN),
            $this->style($emptyBar, TerminalStyle::DIM),
            $percentage,
            $current,
            $total,
            $this->style($currentItem, TerminalStyle::CYAN)
        );

        fwrite(STDOUT, "\r" . $progressText);
    }

    public function clearLine(): void
    {
        if ($this->supportsColor) {
            fwrite(STDOUT, "\r\033[K");
        } else {
            fwrite(STDOUT, "\r" . str_repeat(' ', 80) . "\r");
        }
    }

    public function printPackageInstalled(string $packageName): void
    {
        $symbol = $this->supportsColor ? '✓' : '[NEW]';
        $this->printLine(
            $this->style("  {$symbol} ", TerminalStyle::GREEN, TerminalStyle::BOLD) .
            $packageName .
            $this->style(' installed', TerminalStyle::GREEN)
        );
    }

    public function printPackageSkipped(string $packageName): void
    {
        $symbol = $this->supportsColor ? '○' : '[OK]';
        $this->printLine(
            $this->style("  {$symbol} ", TerminalStyle::CYAN) .
            $packageName .
            $this->style(' already installed', TerminalStyle::DIM)
        );
    }

    public function printPackageFailed(string $packageName): void
    {
        $symbol = $this->supportsColor ? '✗' : '[FAIL]';
        $this->printLine(
            $this->style("  {$symbol} ", TerminalStyle::RED, TerminalStyle::BOLD) .
            $packageName .
            $this->style(' failed', TerminalStyle::RED)
        );
    }

    public function printSummaryLine(string $label, int $value, string $color = ''): void
    {
        $paddedLabel = str_pad($label . ':', 20);

        if ($color !== '') {
            $valueText = $this->style((string)$value, $color, TerminalStyle::BOLD);
        } else {
            $valueText = $this->style((string)$value, TerminalStyle::BOLD);
        }

        $this->printLine("  {$paddedLabel} {$valueText}");
    }
}

final class InputException extends Exception {}
final class PrerequisiteException extends Exception {}
final class PermissionException extends Exception {}
final class CollisionException extends Exception {}
final class InstallationException extends Exception {}

final class CommandExecutionResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $standardOutput,
        public readonly string $standardError,
    ) {}

    public function succeeded(): bool
    {
        return $this->exitCode === 0;
    }

    public function getErrorMessage(): string
    {
        $message = trim($this->standardError ?: $this->standardOutput);
        return $message !== '' ? $message : 'Unknown error';
    }
}

final class InstallationStatistics
{
    public int $totalRepositories = 0;
    public int $processedRepositories = 0;
    public int $discoveredFiles = 0;
    public int $installedFiles = 0;
}

final class AlpineSuite
{
    private string $workingDirectory = '';
    private bool $workingDirectoryRequiresCleanup = false;

    /** @var array<string, string> */
    private array $destinationNameToSourcePath = [];

    /** @var array<string, string> */
    private array $stagedPathToFinalPath = [];

    /** @var string[] */
    private array $successfullyInstalledFiles = [];

    private InstallationStatistics $statistics;

    public function __construct()
    {
        $this->statistics = new InstallationStatistics();
    }

    public function run(array $arguments): int
    {
        $this->logMessage('alpine-suite starting');

        try {
            $configurationFilePath = $this->parseCommandLineArguments($arguments);
            $this->verifyPrerequisites();
            $repositoryUrls = $this->loadAndValidateConfiguration($configurationFilePath);

            $this->statistics->totalRepositories = count($repositoryUrls);
            $this->logMessage("Loaded {$this->statistics->totalRepositories} repository URL(s) from: {$configurationFilePath}");

            $this->createWorkingDirectory();
            $this->cloneRepositories($repositoryUrls);
            $this->discoverPhpFiles();
            $this->detectCollisions();
            $this->stageFilesForInstallation();
            $this->performAtomicInstallation();
            $this->cleanupWorkingDirectory();

            $this->printSummary();
            return ExitCode::SUCCESS;

        } catch (InputException $exception) {
            $this->logError("Input error: {$exception->getMessage()}");
            return ExitCode::INPUT_ERROR;

        } catch (PrerequisiteException $exception) {
            $this->logError("Prerequisite error: {$exception->getMessage()}");
            return ExitCode::PREREQUISITE_ERROR;

        } catch (PermissionException $exception) {
            $this->logError("Permission error: {$exception->getMessage()}");
            $this->attemptCleanup();
            return ExitCode::PERMISSION_ERROR;

        } catch (CollisionException $exception) {
            $this->logError("Collision error: {$exception->getMessage()}");
            $this->attemptCleanup();
            return ExitCode::COLLISION_ERROR;

        } catch (InstallationException $exception) {
            $this->logError("Installation error: {$exception->getMessage()}");
            $this->rollbackInstalledFiles();
            $this->attemptCleanup();
            return ExitCode::INSTALLATION_ERROR;

        } catch (Throwable $exception) {
            $this->logError("Unexpected error: {$exception->getMessage()}");
            $this->rollbackInstalledFiles();
            $this->attemptCleanup();
            return ExitCode::GENERAL_ERROR;
        }
    }

    private function parseCommandLineArguments(array $arguments): string
    {
        $programName = $arguments[0] ?? 'alpine-suite';

        if (count($arguments) < 2) {
            $this->printUsageInstructions($programName);
            throw new InputException('JSON configuration file path is required');
        }

        $firstArgument = $arguments[1];

        if (in_array($firstArgument, ['-h', '--help'], true)) {
            $this->printUsageInstructions($programName);
            exit(ExitCode::SUCCESS);
        }

        if (in_array($firstArgument, ['-g', '--generate'], true)) {
            $generator = new ConfigurationGenerator();
            exit($generator->run());
        }

        if (in_array($firstArgument, ['-b', '--bootstrap'], true)) {
            $bootstrapper = new SystemBootstrapper();
            exit($bootstrapper->run());
        }

        $filePath = $firstArgument;

        if (!file_exists($filePath)) {
            throw new InputException("JSON file does not exist: {$filePath}");
        }

        if (!is_readable($filePath)) {
            throw new InputException("JSON file is not readable: {$filePath}");
        }

        if (!is_file($filePath)) {
            throw new InputException("Path is not a regular file: {$filePath}");
        }

        return realpath($filePath) ?: $filePath;
    }

    private function printUsageInstructions(string $programName): void
    {
        $installationDirectory = Configuration::INSTALLATION_DIRECTORY;

        $usage = <<<USAGE
alpine-suite - PHP Script Installer for Alpine Linux

Usage:
  {$programName} <config.json>      Install PHP scripts from repositories
  {$programName} --bootstrap        Install PHP 8.4 and common extensions
  {$programName} --generate         Interactive configuration file generator
  {$programName} --help             Show this help message

Options:
  -b, --bootstrap  Install PHP 8.4 with 30+ common extensions (requires root)
  -g, --generate   Launch interactive mode to create a configuration file
  -h, --help       Display this help message and exit

Bootstrap Mode:
  Installs PHP 8.4 and 45+ commonly used extensions:
  - System: git, curl, ca-certificates
  - Core: phar, mbstring, openssl, json, tokenizer, ctype, fileinfo, session
  - Database: pdo, pdo_mysql, pdo_pgsql, pdo_sqlite, mysqli, pgsql, sqlite3, ldap
  - XML: xml, dom, simplexml, xmlwriter, xmlreader, xsl, soap
  - Network: curl, sockets, ftp
  - Web: fpm, cgi
  - Caching: opcache, apcu, redis, memcached
  - Compression: zip, bz2, zlib
  - Utilities: gd, intl, bcmath, gmp, sodium, pcntl, posix, imagick

Configuration Format:
  {
    "repos": [
      "https://github.com/example/repo-one.git",
      "https://github.com/example/repo-two.git"
    ]
  }

Behavior:
  - Clones each public Git repository to a temporary directory
  - Discovers all .php files recursively
  - Installs them to {$installationDirectory} without the .php extension
  - Ensures all installed files are executable
  - Fails on duplicate destination names (collision safety)
  - Atomic installation with rollback on failure

Requirements:
  - PHP 8.4+ (or use --bootstrap to install it)
  - Git
  - Write access to {$installationDirectory}

USAGE;
        echo $usage;
    }

    private function verifyPrerequisites(): void
    {
        $this->logMessage('Checking prerequisites...');

        $this->verifyPhpVersion();
        $this->verifyGitAvailability();
        $this->verifyTemporaryDirectoryIsWritable();
        $this->verifyInstallationDirectoryIsWritable();

        $this->logMessage('Prerequisites satisfied');
    }

    private function verifyPhpVersion(): void
    {
        if (PHP_VERSION_ID < Configuration::MINIMUM_PHP_VERSION_ID) {
            throw new PrerequisiteException(
                'PHP 8.4 or higher is required. Current version: ' . PHP_VERSION
            );
        }
        $this->logMessage('  PHP version: ' . PHP_VERSION . ' [OK]');
    }

    private function verifyGitAvailability(): void
    {
        $gitExecutablePath = $this->findExecutableInPath('git');

        if ($gitExecutablePath === null) {
            throw new PrerequisiteException(
                'Git is required but not found in PATH. Install with: apk add git'
            );
        }

        $this->logMessage("  Git found: {$gitExecutablePath} [OK]");
    }

    private function verifyTemporaryDirectoryIsWritable(): void
    {
        $temporaryDirectory = Configuration::TEMPORARY_DIRECTORY;

        if (!is_dir($temporaryDirectory) || !is_writable($temporaryDirectory)) {
            throw new PrerequisiteException("{$temporaryDirectory} must exist and be writable");
        }

        $this->logMessage("  {$temporaryDirectory} writable: [OK]");
    }

    private function verifyInstallationDirectoryIsWritable(): void
    {
        $installationDirectory = Configuration::INSTALLATION_DIRECTORY;

        if (!is_dir($installationDirectory)) {
            throw new PrerequisiteException("{$installationDirectory} does not exist");
        }

        if (!is_writable($installationDirectory)) {
            throw new PermissionException(
                "Cannot write to {$installationDirectory}. Run with appropriate privileges (e.g., doas or sudo)."
            );
        }

        $this->logMessage("  {$installationDirectory} writable: [OK]");
    }

    private function findExecutableInPath(string $executableName): ?string
    {
        $defaultPath = '/usr/local/bin:/usr/bin:/bin';
        $searchPaths = explode(':', getenv('PATH') ?: $defaultPath);

        foreach ($searchPaths as $directory) {
            $fullPath = rtrim($directory, '/') . '/' . $executableName;

            if (is_executable($fullPath)) {
                return $fullPath;
            }
        }

        return null;
    }

    private function loadAndValidateConfiguration(string $filePath): array
    {
        $this->logMessage("Loading configuration from: {$filePath}");

        $fileContents = file_get_contents($filePath);

        if ($fileContents === false) {
            throw new InputException("Failed to read JSON file: {$filePath}");
        }

        $fileContents = trim($fileContents);

        if ($fileContents === '') {
            throw new InputException('JSON file is empty');
        }

        $decodedData = json_decode($fileContents, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InputException('Invalid JSON syntax: ' . json_last_error_msg());
        }

        if (!is_array($decodedData)) {
            throw new InputException('JSON root must be an object');
        }

        if (!array_key_exists('repos', $decodedData)) {
            throw new InputException('JSON must contain a "repos" key');
        }

        $repositoryUrls = $decodedData['repos'];

        if (!is_array($repositoryUrls)) {
            throw new InputException('"repos" must be an array');
        }

        if (!array_is_list($repositoryUrls)) {
            throw new InputException('"repos" must be a list, not an associative array');
        }

        if (count($repositoryUrls) === 0) {
            throw new InputException('"repos" array is empty');
        }

        $validatedUrls = $this->validateRepositoryUrls($repositoryUrls);

        $this->logMessage('  Configuration validated: ' . count($validatedUrls) . ' repository URL(s)');

        return $validatedUrls;
    }

    private function validateRepositoryUrls(array $repositoryUrls): array
    {
        $validatedUrls = [];

        foreach ($repositoryUrls as $index => $url) {
            if (!is_string($url)) {
                throw new InputException(
                    "repos[{$index}] must be a string, got " . gettype($url)
                );
            }

            $url = trim($url);

            if ($url === '') {
                throw new InputException("repos[{$index}] is empty");
            }

            if (!$this->isValidGitRepositoryUrl($url)) {
                throw new InputException("repos[{$index}] is not a valid Git URL: {$url}");
            }

            $validatedUrls[] = $url;
        }

        return $validatedUrls;
    }

    private function isValidGitRepositoryUrl(string $url): bool
    {
        $httpsPattern = '#^https://[a-zA-Z0-9._-]+/[a-zA-Z0-9._/-]+(?:\.git)?$#';
        $gitProtocolPattern = '#^git://[a-zA-Z0-9._-]+/[a-zA-Z0-9._/-]+(?:\.git)?$#';

        return preg_match($httpsPattern, $url) === 1
            || preg_match($gitProtocolPattern, $url) === 1;
    }

    private function createWorkingDirectory(): void
    {
        $attemptCount = 0;

        while ($attemptCount < Configuration::MAXIMUM_DIRECTORY_CREATION_ATTEMPTS) {
            $randomSuffix = bin2hex(random_bytes(Configuration::RANDOM_SUFFIX_BYTES));
            $candidatePath = Configuration::TEMPORARY_DIRECTORY
                . '/'
                . Configuration::WORKING_DIRECTORY_PREFIX
                . $randomSuffix;

            if (!file_exists($candidatePath)) {
                if (!mkdir($candidatePath, FilePermissions::DIRECTORY_PRIVATE, true)) {
                    throw new InstallationException("Failed to create working directory: {$candidatePath}");
                }

                $this->workingDirectory = $candidatePath;
                $this->workingDirectoryRequiresCleanup = true;
                $this->logMessage("Created working directory: {$this->workingDirectory}");
                return;
            }

            $attemptCount++;
        }

        throw new InstallationException('Failed to create unique working directory after multiple attempts');
    }

    private function cloneRepositories(array $repositoryUrls): void
    {
        $this->logMessage('Cloning repositories...');

        foreach ($repositoryUrls as $index => $repositoryUrl) {
            $repositoryName = $this->extractRepositoryNameFromUrl($repositoryUrl);
            $randomSuffix = bin2hex(random_bytes(Configuration::SHORT_RANDOM_SUFFIX_BYTES));
            $targetDirectory = $this->workingDirectory . '/' . $repositoryName . '-' . $randomSuffix;

            $this->logMessage("  [{$index}] Cloning: {$repositoryUrl}");

            $executionResult = $this->executeCommand([
                'git', 'clone',
                '--depth', '1',
                '--single-branch',
                '--quiet',
                $repositoryUrl,
                $targetDirectory
            ]);

            if (!$executionResult->succeeded()) {
                throw new InstallationException(
                    "Failed to clone repository: {$repositoryUrl}\nGit error: {$executionResult->getErrorMessage()}"
                );
            }

            $this->statistics->processedRepositories++;
            $this->logMessage("       Cloned to: {$targetDirectory}");
        }

        $processed = $this->statistics->processedRepositories;
        $total = $this->statistics->totalRepositories;
        $this->logMessage("Cloned {$processed}/{$total} repositories");
    }

    private function extractRepositoryNameFromUrl(string $url): string
    {
        $urlPath = parse_url($url, PHP_URL_PATH) ?? '';
        $basename = basename($urlPath);

        if (str_ends_with($basename, '.git')) {
            $basename = substr($basename, 0, -4);
        }

        $sanitizedName = preg_replace('/[^a-zA-Z0-9_-]/', '-', $basename);

        return $sanitizedName !== '' ? $sanitizedName : 'repository';
    }

    private function executeCommand(array $commandArguments): CommandExecutionResult
    {
        $processDescriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $processHandle = proc_open($commandArguments, $processDescriptors, $pipes);

        if (!is_resource($processHandle)) {
            return new CommandExecutionResult(
                exitCode: -1,
                standardOutput: '',
                standardError: 'Failed to execute command',
            );
        }

        fclose($pipes[0]);

        $standardOutput = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $standardError = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($processHandle);

        return new CommandExecutionResult(
            exitCode: $exitCode,
            standardOutput: $standardOutput ?: '',
            standardError: $standardError ?: '',
        );
    }

    private function discoverPhpFiles(): void
    {
        $this->logMessage('Discovering PHP files...');

        $directoryIterator = new RecursiveDirectoryIterator(
            $this->workingDirectory,
            RecursiveDirectoryIterator::SKIP_DOTS
        );

        $fileIterator = new RecursiveIteratorIterator(
            $directoryIterator,
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($fileIterator as $fileInfo) {
            if (!$fileInfo instanceof SplFileInfo) {
                continue;
            }

            if (!$fileInfo->isFile()) {
                continue;
            }

            if (!$fileInfo->isReadable()) {
                $this->logMessage("  Warning: Skipping unreadable file: {$fileInfo->getPathname()}");
                continue;
            }

            if (strtolower($fileInfo->getExtension()) !== Configuration::PHP_FILE_EXTENSION) {
                continue;
            }

            $sourcePath = $fileInfo->getPathname();
            $destinationName = $fileInfo->getBasename('.' . Configuration::PHP_FILE_EXTENSION);

            if (!$this->isValidCommandName($destinationName)) {
                $this->logMessage("  Warning: Skipping file with invalid command name: {$sourcePath}");
                continue;
            }

            $this->destinationNameToSourcePath[$destinationName] = $sourcePath;
            $this->statistics->discoveredFiles++;
        }

        $this->logMessage("Discovered {$this->statistics->discoveredFiles} PHP file(s)");

        if ($this->statistics->discoveredFiles === 0) {
            $this->logMessage('  No PHP files found in any repository');
            return;
        }

        foreach ($this->destinationNameToSourcePath as $destinationName => $sourcePath) {
            $relativePath = str_replace($this->workingDirectory . '/', '', $sourcePath);
            $this->logMessage("  {$relativePath} => {$destinationName}");
        }
    }

    private function isValidCommandName(string $name): bool
    {
        if ($name === '') {
            return false;
        }

        if (str_starts_with($name, '-')) {
            return false;
        }

        $validCharacterPattern = '/^[a-zA-Z0-9_][a-zA-Z0-9_.-]*$/';

        if (preg_match($validCharacterPattern, $name) !== 1) {
            return false;
        }

        if (in_array(strtolower($name), ForbiddenCommandNames::LIST, true)) {
            return false;
        }

        return true;
    }

    private function detectCollisions(): void
    {
        $this->logMessage('Checking for destination collisions...');

        $this->detectCollisionsWithinDiscoveredFiles();
        $this->detectCollisionsWithExistingFiles();

        $this->logMessage('  No collisions detected');
    }

    private function detectCollisionsWithinDiscoveredFiles(): void
    {
        $seenDestinations = [];
        $collisions = [];

        foreach ($this->destinationNameToSourcePath as $destinationName => $sourcePath) {
            if (isset($seenDestinations[$destinationName])) {
                $collisions[$destinationName] = [
                    $seenDestinations[$destinationName],
                    $sourcePath
                ];
            } else {
                $seenDestinations[$destinationName] = $sourcePath;
            }
        }

        if (empty($collisions)) {
            return;
        }

        $collisionDescriptions = [];

        foreach ($collisions as $destinationName => $sourcePaths) {
            $collisionDescriptions[] = "'{$destinationName}' from: " . implode(' and ', $sourcePaths);
        }

        throw new CollisionException(
            "Duplicate destination names detected:\n  " . implode("\n  ", $collisionDescriptions)
        );
    }

    private function detectCollisionsWithExistingFiles(): void
    {
        $existingFileCollisions = [];

        foreach ($this->destinationNameToSourcePath as $destinationName => $sourcePath) {
            $destinationPath = Configuration::INSTALLATION_DIRECTORY . '/' . $destinationName;

            if (file_exists($destinationPath)) {
                $existingFileCollisions[] = $destinationPath;
            }
        }

        if (empty($existingFileCollisions)) {
            return;
        }

        throw new CollisionException(
            "Destination files already exist (will not overwrite):\n  "
            . implode("\n  ", $existingFileCollisions)
            . "\n\nRemove existing files manually if you intend to replace them."
        );
    }

    private function stageFilesForInstallation(): void
    {
        if (empty($this->destinationNameToSourcePath)) {
            $this->logMessage('No files to stage');
            return;
        }

        $this->logMessage('Staging files for atomic installation...');

        $stagingDirectory = $this->workingDirectory . '/staged';

        if (!mkdir($stagingDirectory, FilePermissions::DIRECTORY_PRIVATE, true)) {
            throw new InstallationException('Failed to create staging directory');
        }

        foreach ($this->destinationNameToSourcePath as $destinationName => $sourcePath) {
            $stagedFilePath = $stagingDirectory . '/' . $destinationName;
            $finalDestinationPath = Configuration::INSTALLATION_DIRECTORY . '/' . $destinationName;

            $fileContents = file_get_contents($sourcePath);

            if ($fileContents === false) {
                throw new InstallationException("Failed to read source file: {$sourcePath}");
            }

            $fileContents = $this->ensurePhpShebangPresent($fileContents);

            if (file_put_contents($stagedFilePath, $fileContents) === false) {
                throw new InstallationException("Failed to write staged file: {$stagedFilePath}");
            }

            if (!chmod($stagedFilePath, FilePermissions::EXECUTABLE)) {
                throw new InstallationException("Failed to set permissions on staged file: {$stagedFilePath}");
            }

            $this->stagedPathToFinalPath[$stagedFilePath] = $finalDestinationPath;
            $this->logMessage("  Staged: {$destinationName}");
        }

        $stagedCount = count($this->stagedPathToFinalPath);
        $this->logMessage("  Staged {$stagedCount} file(s)");
    }

    private function ensurePhpShebangPresent(string $fileContents): string
    {
        if (str_starts_with($fileContents, '#!')) {
            return $fileContents;
        }

        $trimmedContents = ltrim($fileContents);

        if (str_starts_with($trimmedContents, '<?php') || str_starts_with($trimmedContents, '<?')) {
            return Configuration::PHP_SHEBANG . $trimmedContents;
        }

        return Configuration::PHP_SHEBANG . "<?php\n" . $fileContents;
    }

    private function performAtomicInstallation(): void
    {
        if (empty($this->stagedPathToFinalPath)) {
            $this->logMessage('No files to install');
            return;
        }

        $this->logMessage('Installing files to ' . Configuration::INSTALLATION_DIRECTORY . '...');

        foreach ($this->stagedPathToFinalPath as $stagedPath => $finalDestinationPath) {
            $destinationName = basename($finalDestinationPath);

            if (!$this->atomicMoveFile($stagedPath, $finalDestinationPath)) {
                throw new InstallationException("Failed to install: {$destinationName}");
            }

            if (!chmod($finalDestinationPath, FilePermissions::EXECUTABLE)) {
                @unlink($finalDestinationPath);
                throw new InstallationException("Failed to set permissions on: {$finalDestinationPath}");
            }

            $this->successfullyInstalledFiles[] = $finalDestinationPath;
            $this->statistics->installedFiles++;
            $this->logMessage("  Installed: {$destinationName}");
        }

        $this->logMessage("Installed {$this->statistics->installedFiles} file(s)");
    }

    private function atomicMoveFile(string $sourcePath, string $destinationPath): bool
    {
        if (@rename($sourcePath, $destinationPath)) {
            return true;
        }

        $temporaryDestinationPath = $destinationPath . '.tmp.' . getmypid();

        if (!copy($sourcePath, $temporaryDestinationPath)) {
            @unlink($temporaryDestinationPath);
            return false;
        }

        if (!chmod($temporaryDestinationPath, FilePermissions::EXECUTABLE)) {
            @unlink($temporaryDestinationPath);
            return false;
        }

        if (!rename($temporaryDestinationPath, $destinationPath)) {
            @unlink($temporaryDestinationPath);
            return false;
        }

        @unlink($sourcePath);

        return true;
    }

    private function rollbackInstalledFiles(): void
    {
        if (empty($this->successfullyInstalledFiles)) {
            return;
        }

        $this->logMessage('Rolling back installation...');

        foreach ($this->successfullyInstalledFiles as $installedFilePath) {
            if (!file_exists($installedFilePath)) {
                continue;
            }

            if (@unlink($installedFilePath)) {
                $this->logMessage("  Removed: {$installedFilePath}");
            } else {
                $this->logError("  Failed to remove: {$installedFilePath}");
            }
        }

        $this->logMessage('Rollback complete');
    }

    private function cleanupWorkingDirectory(): void
    {
        if (!$this->workingDirectoryRequiresCleanup || $this->workingDirectory === '') {
            return;
        }

        $this->logMessage("Cleaning up working directory: {$this->workingDirectory}");

        if (!$this->isValidWorkingDirectoryPath($this->workingDirectory)) {
            $this->logError("Refusing to clean up invalid working directory: {$this->workingDirectory}");
            return;
        }

        $this->recursivelyDeleteDirectory($this->workingDirectory);
        $this->workingDirectoryRequiresCleanup = false;
        $this->logMessage('  Cleanup complete');
    }

    private function attemptCleanup(): void
    {
        try {
            $this->cleanupWorkingDirectory();
        } catch (Throwable $exception) {
            $this->logError("Cleanup failed: {$exception->getMessage()}");
        }
    }

    private function isValidWorkingDirectoryPath(string $path): bool
    {
        $expectedPrefix = Configuration::TEMPORARY_DIRECTORY . '/';

        if (!str_starts_with($path, $expectedPrefix)) {
            return false;
        }

        $directoryBasename = basename($path);

        if (!str_starts_with($directoryBasename, Configuration::WORKING_DIRECTORY_PREFIX)) {
            return false;
        }

        if (str_contains($path, '..')) {
            return false;
        }

        if (!is_dir($path)) {
            return false;
        }

        return true;
    }

    private function recursivelyDeleteDirectory(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        if (is_link($path)) {
            unlink($path);
            return;
        }

        if (is_file($path)) {
            unlink($path);
            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $directoryContents = scandir($path);

        if ($directoryContents === false) {
            return;
        }

        foreach ($directoryContents as $itemName) {
            if ($itemName === '.' || $itemName === '..') {
                continue;
            }

            $itemPath = $path . '/' . $itemName;
            $resolvedPath = realpath($itemPath);

            if ($resolvedPath !== false && !str_starts_with($resolvedPath, $this->workingDirectory)) {
                $this->logError("Refusing to delete path outside working directory: {$itemPath}");
                continue;
            }

            $this->recursivelyDeleteDirectory($itemPath);
        }

        rmdir($path);
    }

    private function printSummary(): void
    {
        $this->logMessage('');
        $this->logMessage('=== Installation Summary ===');
        $this->logMessage("Repositories processed: {$this->statistics->processedRepositories}/{$this->statistics->totalRepositories}");
        $this->logMessage("PHP files discovered:   {$this->statistics->discoveredFiles}");
        $this->logMessage("Files installed:        {$this->statistics->installedFiles}");
        $this->logMessage("Installation directory: " . Configuration::INSTALLATION_DIRECTORY);

        if (!empty($this->successfullyInstalledFiles)) {
            $this->logMessage('');
            $this->logMessage('Installed commands:');

            foreach ($this->successfullyInstalledFiles as $installedFilePath) {
                $commandName = basename($installedFilePath);
                $this->logMessage("  {$commandName}");
            }
        }

        $this->logMessage('');
        $this->logMessage('alpine-suite completed successfully');
    }

    private function logMessage(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        fwrite(STDOUT, "[{$timestamp}] {$message}\n");
    }

    private function logError(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        fwrite(STDERR, "[{$timestamp}] ERROR: {$message}\n");
    }
}

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$application = new AlpineSuite();
exit($application->run($argv));
