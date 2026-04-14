<?php
declare(strict_types=1);

define('ROOT', __DIR__);
define('BUILDS', ROOT . '/builds');

require_once ROOT . '/src/deploy_ftp.php';

function parse_requested_clubs(array $opts): ?array
{
    if (!isset($opts['clubs'])) {
        return null;
    }

    $parts = preg_split('/\s*,\s*/', (string) $opts['clubs'], -1, PREG_SPLIT_NO_EMPTY);
    return array_values(array_unique($parts));
}

function collect_built_clubs(): array
{
    $clubs = [];
    if (!is_dir(BUILDS)) {
        return $clubs;
    }

    foreach (new DirectoryIterator(BUILDS) as $entry) {
        if (!$entry->isDir() || $entry->isDot()) {
            continue;
        }

        $clubs[strtolower($entry->getFilename())] = [
            'club' => $entry->getFilename(),
            'folder' => $entry->getPathname(),
        ];
    }

    ksort($clubs);
    return $clubs;
}

function main(): int
{
    $opts = getopt('', [
        'help',
        'clubs:',
    ]);

    if (isset($opts['help'])) {
        echo "Usage:\n";
        echo "  php deploy.php [--clubs=Saga,Aleja]\n";
        echo "\n";
        echo "FTP settings are loaded from deploy.local.php only.\n";
        return 0;
    }

    $deployConfig = null;
    try {
        $deployConfig = resolve_ftp_deploy_config(ROOT);
    } catch (RuntimeException $e) {
        echo "FTP deploy configuration error: " . $e->getMessage() . "\n";
        return 1;
    }

    $allClubs = collect_built_clubs();
    if (empty($allClubs)) {
        echo "No built club folders found in " . BUILDS . "\n";
        return 1;
    }

    $requested = parse_requested_clubs($opts);
    $selected = [];
    if ($requested === null) {
        $selected = array_values($allClubs);
    } else {
        foreach ($requested as $clubName) {
            $key = strtolower($clubName);
            if (!isset($allClubs[$key])) {
                echo "Unknown built club in --clubs: {$clubName}\n";
                echo "Available clubs: " . implode(', ', array_map(function (array $c): string { return $c['club']; }, array_values($allClubs))) . "\n";
                return 1;
            }
            $selected[] = $allClubs[$key];
        }
    }

    $hr = str_repeat('-', 44);
    echo "\n  MyFitness FTP Deploy\n  {$hr}\n";
    echo "  Selected " . count($selected) . " club(s): " . implode(', ', array_map(function (array $c): string { return $c['club']; }, $selected)) . "\n";
    echo "  Remote   : " . $deployConfig['remote_root_dir'] . "/<club>/touch\n\n";

    $errors = [];
    foreach ($selected as $clubInfo) {
        $clubName = $clubInfo['club'];
        $clubDir = $clubInfo['folder'];

        echo "  [DEPLOY] Uploading {$clubName} ...\n";
        try {
            $remoteTarget = deploy_local_directory_to_ftp($clubDir, $deployConfig);
            echo "  [DEPLOY] Done: {$remoteTarget}\n\n";
        } catch (RuntimeException $e) {
            $errors[] = $clubName;
            echo "  [DEPLOY][ERROR] {$clubName}: " . $e->getMessage() . "\n\n";
        }
    }

    echo "  {$hr}\n";
    if (empty($errors)) {
        echo "  All selected clubs deployed successfully.\n\n";
        return 0;
    }

    echo "  Completed with errors in: " . implode(', ', $errors) . "\n\n";
    return 1;
}

exit(main());