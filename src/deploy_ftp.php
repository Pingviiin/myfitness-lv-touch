<?php
declare(strict_types=1);

function load_ftp_deploy_config(string $rootDir): array
{
    $configPath = rtrim($rootDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'deploy.local.php';
    if (!file_exists($configPath)) {
        throw new RuntimeException(
            'Missing deploy.local.php. Set host, username, password, and remote_root_dir in deploy.local.php.'
        );
    }

    $config = require $configPath;
    if (!is_array($config)) {
        throw new RuntimeException('FTP deploy config must return an array: ' . $configPath);
    }

    return $config;
}

function normalize_ftp_path(string $path): string
{
    $path = trim(str_replace('\\', '/', $path));
    if ($path === '' || $path === '/') {
        return '/';
    }

    $path = preg_replace('#/+#', '/', $path);
    if ($path === null || $path === '') {
        return '/';
    }

    if ($path[0] !== '/') {
        $path = '/' . $path;
    }

    return rtrim($path, '/');
}

function join_ftp_paths(string $basePath, string $childPath): string
{
    $basePath = normalize_ftp_path($basePath);
    $childPath = trim(str_replace('\\', '/', $childPath));
    $childPath = trim($childPath, '/');

    if ($childPath === '') {
        return $basePath;
    }

    if ($basePath === '/') {
        return '/' . $childPath;
    }

    return $basePath . '/' . $childPath;
}

function to_bool($value, bool $default = false): bool
{
    if ($value === null) {
        return $default;
    }
    if (is_bool($value)) {
        return $value;
    }

    $filtered = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return $filtered === null ? $default : $filtered;
}

function resolve_ftp_deploy_config(string $rootDir): array
{
    $fileConfig = load_ftp_deploy_config($rootDir);

    $resolved = [
        'host' => trim((string) ($fileConfig['host'] ?? '')),
        'username' => trim((string) ($fileConfig['username'] ?? '')),
        'password' => (string) ($fileConfig['password'] ?? ''),
        'remote_root_dir' => trim((string) ($fileConfig['remote_root_dir'] ?? '')),
        'port' => (int) ($fileConfig['port'] ?? 21),
        'timeout' => (int) ($fileConfig['timeout'] ?? 90),
        'secure' => to_bool($fileConfig['secure'] ?? false),
        'passive' => to_bool($fileConfig['passive'] ?? true, true),
    ];

    if (
        $resolved['host'] === '' ||
        $resolved['username'] === '' ||
        $resolved['password'] === '' ||
        $resolved['remote_root_dir'] === ''
    ) {
        throw new RuntimeException(
            'FTP configuration is incomplete. Set host, username, password, and remote_root_dir in deploy.local.php.'
        );
    }

    $resolved['remote_root_dir'] = normalize_ftp_path($resolved['remote_root_dir']);
    if ($resolved['port'] <= 0) {
        $resolved['port'] = 21;
    }
    if ($resolved['timeout'] <= 0) {
        $resolved['timeout'] = 90;
    }

    return $resolved;
}

function ftp_remote_path_is_dir($connection, string $path): bool
{
    $path = normalize_ftp_path($path);
    $currentDir = ftp_pwd($connection);
    if ($currentDir === false) {
        throw new RuntimeException('Unable to read the current FTP working directory.');
    }

    if (@ftp_chdir($connection, $path)) {
        @ftp_chdir($connection, $currentDir);
        return true;
    }

    return false;
}

function ftp_mkdir_recursive($connection, string $remoteDir): void
{
    $remoteDir = normalize_ftp_path($remoteDir);
    if ($remoteDir === '/') {
        return;
    }

    $segments = explode('/', trim($remoteDir, '/'));
    $path = '';
    foreach ($segments as $segment) {
        $path .= '/' . $segment;
        if (ftp_remote_path_is_dir($connection, $path)) {
            continue;
        }

        if (!@ftp_mkdir($connection, $path) && !ftp_remote_path_is_dir($connection, $path)) {
            throw new RuntimeException('Unable to create remote directory: ' . $path);
        }
    }
}

function ftp_delete_recursive($connection, string $remotePath): void
{
    $remotePath = normalize_ftp_path($remotePath);

    if ($remotePath === '/' || $remotePath === '') {
        throw new RuntimeException('Refusing to delete the FTP root directory.');
    }

    if (ftp_remote_path_is_dir($connection, $remotePath)) {
        $entries = @ftp_nlist($connection, $remotePath);
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                $name = basename((string) $entry);
                if ($name === '' || $name === '.' || $name === '..') {
                    continue;
                }

                ftp_delete_recursive($connection, $remotePath . '/' . $name);
            }
        }

        if (!@ftp_rmdir($connection, $remotePath)) {
            throw new RuntimeException('Unable to remove remote directory: ' . $remotePath);
        }

        return;
    }

    if (!@ftp_delete($connection, $remotePath)) {
        return;
    }
}

function ftp_upload_directory($connection, string $localDir, string $remoteDir): void
{
    if (!is_dir($localDir)) {
        throw new RuntimeException('Local build directory does not exist: ' . $localDir);
    }

    $items = scandir($localDir);
    if ($items === false) {
        throw new RuntimeException('Unable to scan local directory: ' . $localDir);
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $localPath = $localDir . DIRECTORY_SEPARATOR . $item;
        $remotePath = join_ftp_paths($remoteDir, $item);

        if (is_dir($localPath)) {
            ftp_mkdir_recursive($connection, $remotePath);
            ftp_upload_directory($connection, $localPath, $remotePath);
            continue;
        }

        if (!@ftp_put($connection, $remotePath, $localPath, FTP_BINARY)) {
            throw new RuntimeException('Failed to upload file: ' . $localPath);
        }
    }
}

function ftp_connect_from_config(array $config)
{
    $host = (string) $config['host'];
    $port = (int) $config['port'];
    $timeout = (int) $config['timeout'];
    $secure = (bool) $config['secure'];

    if ($secure) {
        if (!function_exists('ftp_ssl_connect')) {
            throw new RuntimeException('FTPS was requested, but ftp_ssl_connect() is not available in this PHP build.');
        }
        $connection = @ftp_ssl_connect($host, $port, $timeout);
    } else {
        $connection = @ftp_connect($host, $port, $timeout);
    }

    if (!$connection) {
        throw new RuntimeException('Unable to connect to FTP server: ' . $host . ':' . $port);
    }

    if (!@ftp_login($connection, (string) $config['username'], (string) $config['password'])) {
        @ftp_close($connection);
        throw new RuntimeException('FTP login failed for user: ' . (string) $config['username']);
    }

    @ftp_pasv($connection, (bool) $config['passive']);
    @ftp_set_option($connection, FTP_TIMEOUT_SEC, $timeout);

    return $connection;
}

function deploy_local_directory_to_ftp(string $localDir, array $config): string
{
    if (!is_dir($localDir)) {
        throw new RuntimeException('Local build directory does not exist: ' . $localDir);
    }

    $connection = ftp_connect_from_config($config);
    $remoteRoot = normalize_ftp_path((string) $config['remote_root_dir']);
    $localBase = basename(str_replace('\\', '/', rtrim($localDir, DIRECTORY_SEPARATOR)));
    $remoteTarget = join_ftp_paths($remoteRoot, $localBase . '/touch');

    try {
        ftp_delete_recursive($connection, $remoteTarget);
        ftp_mkdir_recursive($connection, $remoteTarget);
        ftp_upload_directory($connection, $localDir, $remoteTarget);
    } finally {
        @ftp_close($connection);
    }

    return $remoteTarget;
}
