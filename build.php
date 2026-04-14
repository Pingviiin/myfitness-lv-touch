<?php
declare(strict_types=1);

define('ROOT', __DIR__);
define('ASSETS', ROOT . '\\assets');
define('PICTURES', ROOT . '\\pictures');
define('BUILDS', ROOT . '\\builds');

define('SCREEN_W', 1080);
define('SCREEN_H', 1920);

require_once ROOT . '/src/helpers.php';
require_once ROOT . '/src/deploy_ftp.php';
require_once ROOT . '/src/render_index.php';
require_once ROOT . '/src/render_trainer.php';

function parse_requested_clubs(array $opts): ?array
{
    if (!isset($opts['clubs'])) {
        return null;
    }

    $parts = preg_split('/\s*,\s*/', (string) $opts['clubs'], -1, PREG_SPLIT_NO_EMPTY);
    if ($parts === false) {
        return null;
    }

    return array_values(array_unique($parts));
}

function collect_picture_clubs(): array
{
    $clubs = [];
    if (!is_dir(PICTURES)) {
        return $clubs;
    }

    foreach (new DirectoryIterator(PICTURES) as $entry) {
        if (!$entry->isDir() || $entry->isDot()) {
            continue;
        }

        $clubName = trim($entry->getFilename());
        if ($clubName === '') {
            continue;
        }

        $clubDir = $entry->getPathname();
        if (!is_dir($clubDir . '/profiles') || !is_dir($clubDir . '/trainer_pages')) {
            continue;
        }

        $clubs[strtolower($clubName)] = [
            'club' => $clubName,
            'dir' => $clubDir,
        ];
    }

    ksort($clubs);
    return $clubs;
}

function build_club(string $clubName, string $clubDir, string $distDir, ?string $zipOut): int
{
    $hr = str_repeat('-', 44);
    echo "\n  Building Club: {$clubName}\n  {$hr}\n\n";

    if ($zipOut !== null && !class_exists('ZipArchive')) {
        echo "  ERROR: PHP ext-zip is not available. Install it and retry.\n\n";
        return 1;
    }

    $profilesDir = $clubDir . '/profiles';
    $trainerPagesDir = $clubDir . '/trainer_pages';
    $gymLabel = $clubName;

    $trainers = discover_trainers($profilesDir, $trainerPagesDir);
    if (empty($trainers)) {
        echo "  ERROR: No image files found in {$profilesDir}\n";
        echo "  Hint: Put trainer profile images in {$profilesDir}\n";
        echo "        and matching detail images in {$trainerPagesDir}\n\n";
        return 1;
    }

    echo "  Gym     : {$gymLabel}\n";
    printf("  Trainers: %d\n\n", count($trainers));

    $idW = 10;
    $fileW = 32;
    $warnings = 0;
    printf("  %-{$idW}s  %-{$fileW}s  %-{$fileW}s  %s\n", 'ID', 'Profile image', 'Detail LV image', 'Detail EN image');
    echo '  ' . str_repeat('-', $idW) . '  ' . str_repeat('-', $fileW) . '  ' . str_repeat('-', $fileW) . '  ' . str_repeat('-', $fileW) . "\n";
    foreach ($trainers as $t) {
        $pageLabelLv = $t['trainer_page_image_lv'] !== null
            ? $t['trainer_page_image_lv']
            : '[NO MATCH - LV detail missing]';
        $pageLabelEn = $t['trainer_page_image_en'] !== null
            ? $t['trainer_page_image_en']
            : '[NO MATCH - EN detail missing]';
        printf("  %-{$idW}s  %-{$fileW}s  %-{$fileW}s  %s\n", $t['id'], $t['profile_image'], $pageLabelLv, $pageLabelEn);
        if ($t['trainer_page_image_lv'] === null || $t['trainer_page_image_en'] === null) {
            $warnings++;
        }
    }
    echo "\n";

    if ($warnings > 0) {
        echo "  NOTE: {$warnings} trainer(s) are missing either LV or EN detail page.\n";
        echo "        Missing language pages will fall back to the available variant.\n\n";
    }

    echo "  [1/5] Preparing output directory ...\n";
    rrmdir($distDir);
    foreach (['', '/assets', '/data', '/data/profiles', '/data/trainer_pages'] as $sub) {
        mkdir($distDir . $sub, 0755, true);
    }

    echo "  [2/5] Copying assets and images ...\n";
    $sharedBanner = ASSETS . '/banner.jpg';
    if (!file_exists($sharedBanner)) {
        echo "  ERROR: Missing banner image: {$sharedBanner}\n\n";
        return 1;
    }

    rcopy(ASSETS, $distDir . '/assets');
    rcopy($profilesDir, $distDir . '/data/profiles');
    rcopy($trainerPagesDir, $distDir . '/data/trainer_pages');

    echo "  [3/5] Processing images ...\n";
    if (!function_exists('imagecreatefromjpeg')) {
        echo "  WARNING: PHP ext-gd not available - images NOT letterboxed.\n";
        echo "           Install ext-gd and rebuild for full image compatibility.\n\n";
    } else {
        $layout = compute_index_layout(count($trainers), SCREEN_W, SCREEN_H);
        $cardW = (int) $layout['card_w'];
        $cardH = (int) $layout['card_h'];

        $processed = 0;
        $failed = 0;
        foreach ($trainers as $i => $t) {
            $srcFile = $t['profile_image'];
            $srcPath = $distDir . '/data/profiles/' . basename($srcFile);
            $dstFile = preg_replace('/\.(png|gif|webp)$/i', '.jpg', $srcFile);
            $dstPath = $distDir . '/data/profiles/' . basename((string) $dstFile);
            if (file_exists($srcPath)) {
                if (process_image_letterbox($srcPath, $dstPath, $cardW, $cardH, [255, 255, 255], 92, 'top')) {
                    if ($dstPath !== $srcPath) {
                        @unlink($srcPath);
                    }
                    $trainers[$i]['profile_image'] = (string) $dstFile;
                    $processed++;
                } else {
                    $failed++;
                }
            }

            foreach (['trainer_page_image_lv', 'trainer_page_image_en'] as $pageKey) {
                if ($t[$pageKey] === null) {
                    continue;
                }

                $pageSource = (string) $t[$pageKey];
                $pageSrcPath = $distDir . '/data/trainer_pages/' . basename($pageSource);
                $pageDstFile = preg_replace('/\.(png|gif|webp)$/i', '.jpg', $pageSource);
                $pageDstPath = $distDir . '/data/trainer_pages/' . basename((string) $pageDstFile);
                if (file_exists($pageSrcPath)) {
                    if (process_image_letterbox($pageSrcPath, $pageDstPath, SCREEN_W, SCREEN_H)) {
                        if ($pageDstPath !== $pageSrcPath) {
                            @unlink($pageSrcPath);
                        }
                        $trainers[$i][$pageKey] = (string) $pageDstFile;
                        $processed++;
                    } else {
                        $failed++;
                    }
                }
            }
        }

        if ($failed > 0) {
            printf("        %d processed, %d failed\n", $processed, $failed);
        } else {
            printf("        %d image(s) processed\n", $processed);
        }
    }

    echo "  [4/5] Generating HTML ...\n";
    $trainerCount = count($trainers);

    for ($i = 0; $i < $trainerCount; $i++) {
        $indexFile = $i === 0 ? 'index.html' : ('index_' . ($i + 1) . '.html');
        $nextDetail = $trainers[$i]['id'] . '_lv.html';
        $indexTrainers = $trainers;
        $indexTrainers[0]['_next_page'] = $nextDetail;
        file_put_contents($distDir . '/' . $indexFile, render_index($indexTrainers));
        echo "        + {$indexFile}\n";
    }

    foreach ($trainers as $i => $t) {
        $lvFile = $t['id'] . '_lv.html';
        $enFile = $t['id'] . '_en.html';
        $nextGrid = ($i + 1) < $trainerCount ? ('index_' . ($i + 2) . '.html') : 'index.html';

        file_put_contents($distDir . '/' . $lvFile, render_trainer_detail($t, 'lv', $enFile));
        echo "        + {$lvFile}\n";

        file_put_contents($distDir . '/' . $enFile, render_trainer_detail($t, 'en', $nextGrid));
        echo "        + {$enFile}\n";
    }

    if ($zipOut !== null) {
        echo "  [5/5] Creating zip ...\n";
        if (file_exists($zipOut)) {
            unlink($zipOut);
        }
        zip_dir($distDir, $zipOut);

        $sizeKb = round((float) filesize($zipOut) / 1024, 1);
        echo "\n  Done! Build output in {$distDir} and zip in {$zipOut} ({$sizeKb} KB).\n\n";
    } else {
        echo "  [5/5] Skipping zip ...\n";
        echo "\n  Done! Build output in {$distDir}\n\n";
    }

    return 0;
}

function run_batch(array $opts): int
{
    $clubsMap = collect_picture_clubs();
    if (empty($clubsMap)) {
        echo "No valid club folders found in " . PICTURES . "\n";
        return 1;
    }

    $requested = parse_requested_clubs($opts);
    $zipBuilds = isset($opts['zip']);
    $deployBuilds = isset($opts['deploy']);
    $deployConfig = null;

    if ($deployBuilds) {
        try {
            $deployConfig = resolve_ftp_deploy_config(ROOT);
        } catch (RuntimeException $e) {
            echo "FTP deploy configuration error: " . $e->getMessage() . "\n";
            return 1;
        }
    }

    $selected = [];
    if ($requested === null) {
        $selected = array_values($clubsMap);
    } else {
        foreach ($requested as $clubName) {
            $key = strtolower($clubName);
            if (!isset($clubsMap[$key])) {
                echo "Unknown club in --clubs: {$clubName}\n";
                echo "Available clubs: " . implode(', ', array_map(function (array $c): string {
                    return $c['club'];
                }, array_values($clubsMap))) . "\n";
                return 1;
            }
            $selected[] = $clubsMap[$key];
        }
    }

    if (empty($selected)) {
        echo "No clubs selected.\n";
        return 1;
    }

    $hr = str_repeat('-', 44);
    echo "\n  MyFitness Batch Builder\n  {$hr}\n";
    echo "  Selected " . count($selected) . " club(s): " . implode(', ', array_map(function (array $c): string {
        return $c['club'];
    }, $selected)) . "\n";
    echo "  Output   : " . BUILDS . "/<club>\n";
    echo "  Zip      : " . ($zipBuilds ? 'enabled' : 'disabled') . "\n";
    echo "  Deploy   : " . ($deployBuilds ? 'enabled' : 'disabled') . "\n\n";

    if ($deployBuilds && is_array($deployConfig)) {
        echo "  Remote   : " . $deployConfig['remote_root_dir'] . "/<club>/touch\n\n";
    }

    $errors = [];
    foreach ($selected as $clubInfo) {
        $clubName = $clubInfo['club'];
        $clubDir = $clubInfo['dir'];
        $clubOut = BUILDS . DIRECTORY_SEPARATOR . $clubName;
        $zipOut = $zipBuilds ? (ROOT . DIRECTORY_SEPARATOR . $clubName . '.zip') : null;

        echo "  Preparing build for {$clubName}\n";
        $code = build_club($clubName, $clubDir, $clubOut, $zipOut);
        if ($code !== 0) {
            $errors[] = $clubName;
            echo "  [ERROR] Build failed for {$clubName} (exit {$code})\n\n";
            continue;
        }

        if ($deployBuilds && is_array($deployConfig)) {
            echo "  [DEPLOY] Uploading {$clubName} to FTP ...\n";
            try {
                $remoteTarget = deploy_local_directory_to_ftp($clubOut, $deployConfig);
                echo "  [DEPLOY] Done: {$remoteTarget}\n\n";
            } catch (RuntimeException $e) {
                $errors[] = $clubName . ' (deploy)';
                echo "  [DEPLOY][ERROR] {$clubName}: " . $e->getMessage() . "\n\n";
            }
        }
    }

    echo "  {$hr}\n";
    if (empty($errors)) {
        echo "  All clubs built successfully.\n\n";
        return 0;
    }

    echo "  Completed with errors in: " . implode(', ', $errors) . "\n\n";
    return 1;
}

function main(): int
{
    $opts = getopt('', [
        'help',
        'clubs:',
        'zip',
        'deploy',
    ]);

    if (isset($opts['help'])) {
        echo "Usage:\n";
        echo "  php build.php [--clubs=Aleja,Saga] [--zip] [--deploy]\n";
        echo "\n";
        echo "Options:\n";
        echo "  --clubs=...  Comma-separated club names to build\n";
        echo "  --zip        Create <club>.zip in project root\n";
        echo "  --deploy     Deploy each completed build via FTP\n";
        echo "\n";
        echo "FTP settings are loaded from deploy.local.php only.\n";
        return 0;
    }

    return run_batch($opts);
}

exit(main());

