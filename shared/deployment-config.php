<?php
declare(strict_types=1);

function deploymentPrivateConfigPath(): string
{
    $configuredPath = getenv('ADMINLTE_PRIVATE_CONFIG');
    if ($configuredPath !== false && trim($configuredPath) !== '') {
        $resolvedPath = realpath($configuredPath);
        if ($resolvedPath === false || !is_file($resolvedPath)) {
            throw new RuntimeException('The configured private deployment config file is unavailable.');
        }

        return $resolvedPath;
    }

    $configPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bmlhs-private-config.php';
    $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $configDirectory = realpath(dirname($configPath));
    if ($documentRoot !== false && $configDirectory !== false) {
        $root = rtrim(str_replace('\\', '/', $documentRoot), '/');
        $directory = str_replace('\\', '/', $configDirectory);
        if (strcasecmp($directory, $root) === 0 || strncasecmp($directory, $root . '/', strlen($root) + 1) === 0) {
            $configPath = dirname($documentRoot) . DIRECTORY_SEPARATOR . 'bmlhs-private-config.php';
        }
    }

    return $configPath;
}

function deploymentPrivateConfig(): array
{
    static $privateConfig = null;
    if (is_array($privateConfig)) {
        return $privateConfig;
    }

    $configPath = deploymentPrivateConfigPath();
    $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $resolvedConfig = realpath($configPath);
    if ($resolvedConfig !== false && $documentRoot !== false) {
        $root = rtrim(str_replace('\\', '/', $documentRoot), '/');
        $config = str_replace('\\', '/', $resolvedConfig);
        if (strcasecmp($config, $root) === 0 || strncasecmp($config, $root . '/', strlen($root) + 1) === 0) {
            throw new RuntimeException('The private deployment config must be outside the web document root.');
        }
    }

    if (!is_file($configPath)) {
        $privateConfig = [];

        return $privateConfig;
    }

    $loadedConfig = require $configPath;
    if (!is_array($loadedConfig)) {
        throw new RuntimeException('The private deployment config must return an array.');
    }
    $privateConfig = $loadedConfig;

    return $privateConfig;
}

function deploymentPrivateMediaRootPath(): string
{
    $privateConfig = deploymentPrivateConfig();
    $configuredPath = $privateConfig['private_media_path'] ?? dirname(deploymentPrivateConfigPath()) . DIRECTORY_SEPARATOR . 'bmlhs-private-media';
    if (!is_string($configuredPath) || $configuredPath === '') {
        throw new RuntimeException('The private media path must be an absolute path.');
    }
    if (!str_starts_with($configuredPath, DIRECTORY_SEPARATOR) && preg_match('/^[A-Za-z]:[\\\\\/]/', $configuredPath) !== 1) {
        throw new RuntimeException('The private media path must be absolute.');
    }

    $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $rootPath = realpath($configuredPath);
    $rootForCheck = $rootPath !== false ? $rootPath : realpath(dirname($configuredPath));
    if ($rootForCheck !== false && $documentRoot !== false) {
        $document = rtrim(str_replace('\\', '/', $documentRoot), '/');
        $mediaRoot = str_replace('\\', '/', $rootForCheck);
        if (strcasecmp($mediaRoot, $document) === 0 || strncasecmp($mediaRoot, $document . '/', strlen($document) + 1) === 0) {
            throw new RuntimeException('Private media storage must be outside the web document root.');
        }
    }
    if ($rootPath === false && $rootForCheck === false) {
        throw new RuntimeException('The parent directory for private media storage must already exist.');
    }

    return $configuredPath;
}

function deploymentDatabaseSettings(): array
{
    $privateConfig = deploymentPrivateConfig();
    $databaseConfig = $privateConfig['database'] ?? [];
    if (!is_array($databaseConfig)) {
        throw new RuntimeException('The database section in the private deployment config must be an array.');
    }

    $readSetting = static function (string $environmentKey, string $configKey, string $default) use ($databaseConfig): string {
        $environmentValue = getenv($environmentKey);
        if ($environmentValue !== false) {
            return $environmentValue;
        }

        $configValue = $databaseConfig[$configKey] ?? $default;
        if (!is_string($configValue)) {
            throw new RuntimeException('Database configuration values must be strings.');
        }

        return $configValue;
    };

    return [
        'host' => $readSetting('ADMINLTE_DB_HOST', 'host', '127.0.0.1'),
        'name' => $readSetting('ADMINLTE_DB_NAME', 'name', 'bmlhs'),
        'username' => $readSetting('ADMINLTE_DB_USER', 'username', 'root'),
        'password' => $readSetting('ADMINLTE_DB_PASSWORD', 'password', ''),
    ];
}
