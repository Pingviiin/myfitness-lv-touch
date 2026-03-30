<?php
declare(strict_types=1);

/**
 * build.php — MyFitness Touchscreen Trainer Site Builder
 *
 * Default behavior:
 *   - Rebuild all clubs discovered from pictures/*_(HD|4K)
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
 *   php build.php [--clubs=Annelinn,Kristiine] [--zip]
 *
 * Options:
 *   --clubs=...     Comma-separated club names for batch mode.
 *   --zip           Also create <club>.zip in project root (batch mode).
 */

define('ROOT',    __DIR__);
define('DATA',    ROOT . '/data');
define('ASSETS',  ROOT . '/assets');
define('PICTURES', ROOT . '/pictures');
define('BUILDS',   ROOT . '/builds');
define('DATA_PROF', DATA . '/profiles');
define('DATA_PAGES', DATA . '/trainer_pages');

define('SCREEN_W', 1080);   // portrait display width  (px)
define('SCREEN_H', 1920);  // portrait display height (px)

// ── Includes ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/src/helpers.php';
require_once __DIR__ . '/src/render_index.php';
require_once __DIR__ . '/src/render_trainer.php';

// ── Main ─────────────────────────────────────────────────────────────────────

function copy_dir_flat(string $src, string $dst): void
{
    if (!is_dir($src)) {
        return;
    }
    if (!is_dir($dst)) {
        mkdir($dst, 0755, true);
    }
    foreach (new DirectoryIterator($src) as $item) {
        if ($item->isDot() || $item->isDir()) {
            continue;
        }
        copy($item->getPathname(), $dst . DIRECTORY_SEPARATOR . $item->getFilename());
    }
}

function clear_dir_flat(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (new DirectoryIterator($dir) as $item) {
        if ($item->isDot() || $item->isDir()) {
            continue;
        }
        unlink($item->getPathname());
    }
}

function parse_club_name_from_folder(string $folder): ?string
{
    if (!preg_match('/^(.+?)_(HD|4K)$/i', $folder, $m)) {
        return null;
    }
    return $m[1];
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

function build_from_current_data(string $clubName, string $distDir, ?string $zipOut): int
{
    $hr = str_repeat('-', 44);
    echo "\n  Building Club: {$clubName}\n  {$hr}\n\n";

    if ($zipOut !== null && !class_exists('ZipArchive')) {
        echo "  ERROR: PHP ext-zip is not available. Install it and retry.\n\n";
        return 1;
    }

    $gymNameFile = DATA . '/gym_name.txt';
    $gymName     = file_exists($gymNameFile)
        ? trim((string) file_get_contents($gymNameFile))
        : 'MyFitness';

    $trainers = discover_trainers();

    if (empty($trainers)) {
        echo "  ERROR: No image files found in data/profiles/\n";
        echo "  Hint:  Copy your trainer profile images (JPG/PNG) into data/profiles/\n";
        echo "         and matching detail images into data/trainer_pages/\n\n";
        return 1;
    }

    echo "  Gym     : {$gymName}\n";
    printf("  Trainers: %d\n\n", count($trainers));

    // ── Show match table ──────────────────────────────────────────────────
    $idW      = 10;
    $fileW    = 36;
    $warnings = 0;
    printf("  %-{$idW}s  %-{$fileW}s  %s\n", 'ID', 'Profile image', 'Detail page image');
    echo '  ' . str_repeat('-', $idW) . '  ' . str_repeat('-', $fileW) . '  ' . str_repeat('-', $fileW) . "\n";
    foreach ($trainers as $t) {
        $pageLabel = $t['trainer_page_image'] !== null
            ? $t['trainer_page_image']
            : '[NO MATCH — detail page missing]';
        printf("  %-{$idW}s  %-{$fileW}s  %s\n", $t['id'], $t['profile_image'], $pageLabel);
        if ($t['trainer_page_image'] === null) {
            $warnings++;
        }
    }
    echo "\n";
    if ($warnings > 0) {
        echo "  NOTE: {$warnings} trainer(s) have no matching detail page.\n";
        echo "        Their card will still appear in the grid but link to a blank page.\n\n";
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
    rcopy(DATA . '/profiles',      $distDir . '/data/profiles');
    rcopy(DATA . '/trainer_pages', $distDir . '/data/trainer_pages');

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

            // Trainer page image — letterbox to full SCREEN_W × SCREEN_H
            if ($t['trainer_page_image'] !== null) {
                $srcFile = $t['trainer_page_image'];
                $srcPath = $distDir . '/data/trainer_pages/' . basename($srcFile);
                $dstFile = preg_replace('/\.(png|gif|webp)$/i', '.jpg', $srcFile);
                $dstPath = $distDir . '/data/trainer_pages/' . basename($dstFile);
                if (file_exists($srcPath)) {
                    if (process_image_letterbox($srcPath, $dstPath, SCREEN_W, SCREEN_H)) {
                        if ($dstPath !== $srcPath) {
                            @unlink($srcPath);
                        }
                        $trainers[$i]['trainer_page_image'] = $dstFile;
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

    // ── Header image ──────────────────────────────────────────────────────
    $fontPath  = ASSETS . '/MyriadPro-Regular.otf';
    $hFontSz  = (int) round(SCREEN_W * 0.06);
    $hPadV    = (int) round(SCREEN_W * 0.04);
    $hdrImgW  = (int) round(SCREEN_W * 1);
    if (generate_header_image('Personaaltreenerid', $fontPath, $distDir . '/assets/header.jpg', $hdrImgW, $hFontSz, $hPadV)) {
        echo "        + header.jpg\n";
    } else {
        echo "  WARNING: Could not generate header.jpg (GD+FreeType or font unavailable).\n";
        echo "           The header <h1> text fallback will be used instead.\n";
    }

    // ── 5. Generate HTML files ────────────────────────────────────────────
    echo "  [4/5] Generating HTML ...\n";

    file_put_contents($distDir . '/index.html', render_index($trainers, $gymName));
    echo "        + index.html\n";

    foreach ($trainers as $t) {
        $filename = $t['id'] . '.html';
        file_put_contents($distDir . "/{$filename}", render_trainer_detail($t));
        echo "        + {$filename}\n";
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

    $errors = [];

    foreach ($selected as $clubInfo) {
        $clubName = $clubInfo['club'];
        $folder   = $clubInfo['folder'];
        $clubDir  = PICTURES . DIRECTORY_SEPARATOR . $folder;
        $clubOut  = BUILDS . DIRECTORY_SEPARATOR . $clubName;
        $zipOut   = $zipBuilds ? (ROOT . DIRECTORY_SEPARATOR . $clubName . '.zip') : null;

        echo "  Preparing data for {$clubName} from {$folder}\n";
        clear_dir_flat(DATA_PROF);
        clear_dir_flat(DATA_PAGES);
        copy_dir_flat($clubDir . '/profiles',      DATA_PROF);
        copy_dir_flat($clubDir . '/trainer_pages', DATA_PAGES);

        $code = build_from_current_data($clubName, $clubOut, $zipOut);
        if ($code !== 0) {
            $errors[] = $clubName;
            echo "  [ERROR] build failed for {$clubName} (exit {$code})\n\n";
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
    $opts = getopt('', ['help', 'clubs:', 'zip']);

    if (isset($opts['help'])) {
        echo "Usage:\n";
        echo "  php build.php [--clubs=Annelinn,Kristiine] [--zip]\n";
        return 0;
    }

    return run_batch($opts);
}

exit(main());

