<?php
declare(strict_types=1);

/**
 * build.php — MyFitness Touchscreen Trainer Site Builder
 *
 * Default behavior: *   - Rebuild all clubs discovered from pictures/*
 *   - Write output into builds/<club>/
 *
 * Club selection:
 *   - Use --clubs=ClubA,ClubB to build only selected clubs.
 *
 * Optional zip output in batch mode:
 *   - Use --zip to also write <club>.zip in project root.
 *
 * Requirements: PHP 7.1+, ext-zip
 *
 * Usage:
 *   php build.php [--clubs=Annelinn,Kristiine] [--zip] [--deploy]
 *
 * Options:
 *   --clubs=...     Comma-separated club names for batch mode.
 *   --zip           Also create <club>.zip in project root (batch mode).
 *   --deploy        Mirror each finished build to the configured FTP server.
 */

define('ROOT',    __DIR__);
define('ASSETS',  ROOT . '/assets');
define('PICTURES', ROOT . '/pictures');
define('BUILDS',   ROOT . '/builds');

define('SCREEN_W', 1080);   // portrait display width  (px)
define('SCREEN_H', 1920);  // portrait display height (px)

// ── Includes ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/src/helpers.php';
require_once __DIR__ . '/src/deploy_ftp.php';
require_once __DIR__ . '/src/render_index.php';
require_once __DIR__ . '/src/render_trainer.php';

// ── Main ─────────────────────────────────────────────────────────────────────

function parse_club_name_from_folder(string $folder): ?string
{
    $trimmed = trim($folder);
    if ($trimmed === '') {
        return null;
    }

    // Legacy naming support: Club_HD / Club_4K
    if (preg_match('/^(.+?)_(HD|4K)$/i', $trimmed, $m)) {
        return trim($m[1]);
    }

    // Plain folder naming support: ClubName
    return $trimmed;
}

function parse_requested_clubs(array $opts): ?array
{
    if (!isset($opts['clubs'])) {
        return null;
    }
    $parts = preg_split('/\s*,\s*/', (string) $opts['clubs'], -1, PREG_SPLIT_NO_EMPTY);
    return array_values(array_unique($parts));
}

function collect_picture_clubs(): array
{
    $foldersByClub = [];
    foreach (new DirectoryIterator(PICTURES) as $entry) {
        if (!$entry->isDir() || $entry->isDot()) {
            continue;
        }
        $folder = $entry->getFilename();

        // Ignore temporary duplicate folders like "Saga - Copy" and "Saga - Copy (2)".
        if (preg_match('/\s*-\s*copy(?:\s*\(\d+\))?$/i', $folder)) {
            continue;
        }

        $club   = parse_club_name_from_folder($folder);
        if ($club === null) {
            continue;
        }
        $foldersByClub[strtolower($club)] = [
            'club'   => $club,
            'folder' => $folder,
        ];
    }

    ksort($foldersByClub);
    return $foldersByClub;
}

function build_from_club_folder(string $clubName, string $clubDir, string $distDir, ?string $zipOut): int
{
    $hr = str_repeat('-', 44);
    echo "\n  Building Club: {$clubName}\n  {$hr}\n\n";

    if ($zipOut !== null && !class_exists('ZipArchive')) {
        echo "  ERROR: PHP ext-zip is not available. Install it and retry.\n\n";
        return 1;
    }

    $profilesDir = $clubDir . '/profiles';
    $trainerPagesDir = $clubDir . '/trainer_pages';
    $gymNameFile = $clubDir . '/gym_name.txt';
    $gymName     = file_exists($gymNameFile)
        ? trim((string) file_get_contents($gymNameFile))
        : $clubName;

    $trainers = discover_trainers($profilesDir, $trainerPagesDir);

    if (empty($trainers)) {
        echo "  ERROR: No image files found in {$profilesDir}\n";
        echo "  Hint:  Put trainer profile images (JPG/PNG) in {$profilesDir}\n";
        echo "         and matching detail images in {$trainerPagesDir}\n\n";
        return 1;
    }

    echo "  Gym     : {$gymName}\n";
    printf("  Trainers: %d\n\n", count($trainers));

    // ── Show match table ──────────────────────────────────────────────────
    $idW      = 10;
    $fileW    = 32;
    $warnings = 0;
    printf("  %-{$idW}s  %-{$fileW}s  %-{$fileW}s  %s\n", 'ID', 'Profile image', 'Detail LV image', 'Detail EN image');
    echo '  ' . str_repeat('-', $idW) . '  ' . str_repeat('-', $fileW) . '  ' . str_repeat('-', $fileW) . '  ' . str_repeat('-', $fileW) . "\n";
    foreach ($trainers as $t) {
        $pageLabelLv = $t['trainer_page_image_lv'] !== null
            ? $t['trainer_page_image_lv']
            : '[NO MATCH — LV detail missing]';
        $pageLabelEn = $t['trainer_page_image_en'] !== null
            ? $t['trainer_page_image_en']
            : '[NO MATCH — EN detail missing]';
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

    // ── 2. Prepare a clean output directory ───────────────────────────────
    echo "  [1/5] Preparing output directory ...\n";
    rrmdir($distDir);
    foreach (['', '/assets', '/data', '/data/profiles', '/data/trainer_pages'] as $sub) {
        mkdir($distDir . $sub, 0755, true);
    }

    // ── 3. Copy static assets and trainer images ─────────────────────────
    echo "  [2/5] Copying assets and images ...\n";
    rcopy(ASSETS,                  $distDir . '/assets');
    rcopy($profilesDir,            $distDir . '/data/profiles');
    rcopy($trainerPagesDir,        $distDir . '/data/trainer_pages');

    // ── 4. Pre-process images (letterbox to exact px, convert to JPEG) ──
    echo "  [3/5] Processing images ...\n";
    if (!function_exists('imagecreatefromjpeg')) {
        echo "  WARNING: PHP ext-gd not available — images NOT letterboxed.\n";
        echo "           Install ext-gd and rebuild for full image compatibility.\n\n";
    } else {
        $cols    = count($trainers) > 6 ? 3 : 2;
        $gridW   = (int) round(SCREEN_W * 0.96);
        $cardOut = (int) floor($gridW / $cols);  // floor avoids 1-px overflow
        $cardPad = (int) round($gridW * 0.01);
        $cardW   = $cardOut - 2 * $cardPad;
        $cardH   = (int) round($cardW * 0.90);

        $processed = 0;
        $failed    = 0;
        foreach ($trainers as $i => $t) {
            // Profile card image — letterbox to exact card dimensions
            $srcFile = $t['profile_image'];
            $srcPath = $distDir . '/data/profiles/' . basename($srcFile);
            $dstFile = preg_replace('/\.(png|gif|webp)$/i', '.jpg', $srcFile);
            $dstPath = $distDir . '/data/profiles/' . basename($dstFile);
            if (file_exists($srcPath)) {
                if (process_image_letterbox($srcPath, $dstPath, $cardW, $cardH, [255, 255, 255], 92, 'top')) {
                    if ($dstPath !== $srcPath) {
                        @unlink($srcPath);
                    }
                    $trainers[$i]['profile_image'] = $dstFile;
                    $processed++;
                } else {
                    $failed++;
                }
            }

            // Trainer page images (LV/EN) — letterbox to full SCREEN_W × SCREEN_H
            foreach (['trainer_page_image_lv', 'trainer_page_image_en'] as $pageKey) {
                if ($t[$pageKey] === null) {
                    continue;
                }
                $srcFile = (string) $t[$pageKey];
                $srcPath = $distDir . '/data/trainer_pages/' . basename($srcFile);
                $dstFile = preg_replace('/\.(png|gif|webp)$/i', '.jpg', $srcFile);
                $dstPath = $distDir . '/data/trainer_pages/' . basename($dstFile);
                if (file_exists($srcPath)) {
                    if (process_image_letterbox($srcPath, $dstPath, SCREEN_W, SCREEN_H)) {
                        if ($dstPath !== $srcPath) {
                            @unlink($srcPath);
                        }
                        $trainers[$i][$pageKey] = $dstFile;
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

    // ── 5. Generate HTML files ────────────────────────────────────────────
    echo "  [4/5] Generating HTML ...\n";

    $trainerCount = count($trainers);

    for ($i = 0; $i < $trainerCount; $i++) {
        $indexFile = $i === 0 ? 'index.html' : ('index_' . ($i + 1) . '.html');
        $nextDetail = $trainers[$i]['id'] . '_lv.html';
        file_put_contents($distDir . '/' . $indexFile, render_index($trainers, $gymName, $nextDetail));
        echo "        + {$indexFile}\n";
    }

    foreach ($trainers as $i => $t) {
        $lvFile = $t['id'] . '_lv.html';
        $enFile = $t['id'] . '_en.html';
        $nextGrid = ($i + 1) < $trainerCount ? ('index_' . ($i + 2) . '.html') : 'index.html';

        file_put_contents($distDir . "/{$lvFile}", render_trainer_detail($t, 'lv', $enFile));
        echo "        + {$lvFile}\n";

        file_put_contents($distDir . "/{$enFile}", render_trainer_detail($t, 'en', $nextGrid));
        echo "        + {$enFile}\n";
    }

    // ── 6. Bundle into zip (optional) ────────────────────────────────────
    if ($zipOut !== null) {
        echo "  [5/5] Creating zip ...\n";
        if (file_exists($zipOut)) {
            unlink($zipOut);
        }
        zip_dir($distDir, $zipOut);

        $sizeKb = round(filesize($zipOut) / 1024, 1);
        echo "\n  Done!  Build output in {$distDir} and zip in {$zipOut} ({$sizeKb} KB).\n\n";
    } else {
        echo "  [5/5] Skipping zip ...\n";
        echo "\n  Done!  Build output in {$distDir}\n\n";
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
            $deployConfig = resolve_ftp_deploy_config($opts, ROOT);
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
                echo "Available clubs: " . implode(', ', array_map(function (array $c): string { return $c['club']; }, array_values($clubsMap))) . "\n";
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
    echo "  Selected " . count($selected) . " club(s): " . implode(', ', array_map(function (array $c): string { return $c['club']; }, $selected)) . "\n";
    echo "  Output   : " . BUILDS . "/<club>\n";
    echo "  Zip      : " . ($zipBuilds ? 'enabled' : 'disabled') . "\n\n";
    if ($deployBuilds) {
        echo "  Deploy   : enabled\n";
        echo "  Remote   : " . $deployConfig['remote_root_dir'] . "/<club>/touch\n\n";
    }

    $errors = [];

    foreach ($selected as $clubInfo) {
        $clubName = $clubInfo['club'];
        $folder   = $clubInfo['folder'];
        $clubDir  = PICTURES . DIRECTORY_SEPARATOR . $folder;
        $clubOut  = BUILDS . DIRECTORY_SEPARATOR . $clubName;
        $zipOut   = $zipBuilds ? (ROOT . DIRECTORY_SEPARATOR . $clubName . '.zip') : null;

        echo "  Preparing build for {$clubName} from {$folder}\n";

        $code = build_from_club_folder($clubName, $clubDir, $clubOut, $zipOut);
        if ($code !== 0) {
            $errors[] = $clubName;
            echo "  [ERROR] build failed for {$clubName} (exit {$code})\n\n";
            continue;
        }

        if ($deployBuilds) {
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
        'deploy-config:',
        'ftp-host:',
        'ftp-user:',
        'ftp-pass:',
        'ftp-root:',
        'ftp-port:',
        'ftp-timeout:',
        'ftp-secure',
        'ftp-passive',
        'ftp-no-passive',
        'ftp-insecure',
    ]);

    if (isset($opts['help'])) {
        echo "Usage:\n";
        echo "  php build.php [--clubs=Annelinn,Kristiine] [--zip] [--deploy]\n";
        echo "\n";
        echo "FTP deploy options:\n";
        echo "  --deploy-config=path   Optional config file (default: deploy.local.php)\n";
        echo "  --ftp-host=host        FTP server host\n";
        echo "  --ftp-user=user        FTP username\n";
        echo "  --ftp-pass=pass        FTP password\n";
        echo "  --ftp-root=path        Remote root folder for club builds\n";
        echo "  --ftp-port=21          FTP port\n";
        echo "  --ftp-secure           Use FTPS when available\n";
        return 0;
    }

    return run_batch($opts);
}

exit(main());

