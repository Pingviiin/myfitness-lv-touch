<?php
declare(strict_types=1);

/** Recursively delete a directory tree. */
function rrmdir(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($it as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($path);
}

/** Recursively copy a directory. */
function rcopy(string $src, string $dst): void
{
    if (!is_dir($src)) {
        return;
    }

    if (!is_dir($dst)) {
        mkdir($dst, 0755, true);
    }

    foreach (scandir($src) as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $sourcePath = $src . DIRECTORY_SEPARATOR . $item;
        $targetPath = $dst . DIRECTORY_SEPARATOR . $item;

        if (is_dir($sourcePath)) {
            rcopy($sourcePath, $targetPath);
        } else {
            copy($sourcePath, $targetPath);
        }
    }
}

/**
 * Shared index layout computation used by build + render.
 */
function compute_index_layout(int $trainerCount, int $screenW, int $screenH): array
{
    $cols = $trainerCount > 6 ? 3 : 2;
    $gridW = (int) round($screenW * 0.96);
    $cardOuterW = (int) floor($gridW / $cols);
    $cardPad = (int) round($gridW * 0.01);
    $cardW = $cardOuterW - (2 * $cardPad);
    $cardH = (int) round($cardW * 0.90);

    return [
        'screen_w' => $screenW,
        'screen_h' => $screenH,
        'cols' => $cols,
        'grid_w' => $gridW,
        'card_outer_w' => $cardOuterW,
        'card_pad' => $cardPad,
        'card_w' => $cardW,
        'card_h' => $cardH,
        'grid_margin_top' => (int) round($screenW * 0.025),
        'grid_margin_bottom' => (int) round($screenW * 0.12),
        'footer_h' => (int) round($screenW * 0.14),
        'logo_h' => (int) round($screenW * 0.08),
        'logo_bottom' => 0,
        'logo_right' => (int) round($screenW * 0.01),
    ];
}

/**
 * Create a URL-safe ASCII slug from a trainer name.
 */
function slugify(string $name): string
{
    $map = [
        'ä' => 'a', 'Ä' => 'a', 'ö' => 'o', 'Ö' => 'o',
        'ü' => 'u', 'Ü' => 'u', 'õ' => 'o', 'Õ' => 'o',
        'š' => 's', 'Š' => 's', 'ž' => 'z', 'Ž' => 'z',
        'á' => 'a', 'Á' => 'a', 'é' => 'e', 'É' => 'e',
        'í' => 'i', 'Í' => 'i', 'ó' => 'o', 'Ó' => 'o',
        'ú' => 'u', 'Ú' => 'u', 'ñ' => 'n', 'Ñ' => 'n',
    ];

    $slug = strtolower(strtr($name, $map));
    $slug = preg_replace('/\s+/', '-', $slug);
    $slug = preg_replace('/[^a-z0-9\-]/', '', (string) $slug);
    $slug = preg_replace('/-{2,}/', '-', (string) $slug);

    return trim((string) $slug, '-');
}

/**
 * Extract meaningful tokens from a filename.
 */
function extract_name_words(string $filename): array
{
    static $noiseWords = [
        'new', 'old', 'vaike', 'vaike2', 'small', 'big', 'large',
        'crop', 'page', 'full', 'thumb', 'thumbnail', 'photo', 'pic',
        'portrait', 'profile', 'img', 'image',
        'a', 'b', 'c', 'd',
        'lv', 'lat', 'latvian', 'latviesu',
        'en', 'eng', 'english',
    ];

    $base = strtolower(pathinfo($filename, PATHINFO_FILENAME));
    $base = str_replace(['-', '_', '.'], ' ', $base);
    $words = preg_split('/\s+/', trim($base), -1, PREG_SPLIT_NO_EMPTY);
    if ($words === false) {
        return [];
    }

    $words = array_filter($words, function (string $word) use ($noiseWords): bool {
        if (preg_match('/^\d+$/', $word)) {
            return false;
        }
        if (preg_match('/^\d+x\d+$/i', $word)) {
            return false;
        }
        if (in_array($word, $noiseWords, true)) {
            return false;
        }
        return true;
    });

    return array_values($words);
}

/**
 * Detect language marker in filename.
 */
function detect_page_language(string $filename): ?string
{
    $base = strtolower(pathinfo($filename, PATHINFO_FILENAME));
    $base = str_replace(['-', '_', '.'], ' ', $base);
    $words = preg_split('/\s+/', trim($base), -1, PREG_SPLIT_NO_EMPTY);
    if ($words === false) {
        return null;
    }

    foreach ($words as $word) {
        if (in_array($word, ['lv', 'lat', 'latv', 'latvian', 'latviesu', 'lva'], true)) {
            return 'lv';
        }
        if (in_array($word, ['en', 'eng', 'engl', 'english'], true)) {
            return 'en';
        }
    }

    return null;
}

/**
 * Match profile images against trainer page images and assign stable IDs.
 */
function discover_trainers(string $profilesDir, string $pagesDir): array
{
    $supported = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    $scanImages = function (string $dir) use ($supported): array {
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        foreach (scandir($dir) as $fileName) {
            if ($fileName === '.' || $fileName === '..') {
                continue;
            }

            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            if (in_array($ext, $supported, true)) {
                $files[] = $fileName;
            }
        }

        return $files;
    };

    $profileFiles = $scanImages($profilesDir);
    $pageFiles = $scanImages($pagesDir);

    if (empty($profileFiles)) {
        return [];
    }

    usort($profileFiles, function (string $a, string $b): int {
        return strcmp(
            implode(' ', extract_name_words($a)),
            implode(' ', extract_name_words($b))
        );
    });

    $pageWordSets = [];
    $pageLang = [];
    foreach ($pageFiles as $pageFile) {
        $pageWordSets[$pageFile] = extract_name_words($pageFile);
        $pageLang[$pageFile] = detect_page_language($pageFile);
    }

    $usedPagesLv = [];
    $usedPagesEn = [];
    $usedPagesFallback = [];
    $trainers = [];

    foreach ($profileFiles as $idx => $profileFile) {
        $profileWords = extract_name_words($profileFile);
        $bestScoreLv = 0;
        $bestScoreEn = 0;
        $bestPageLv = null;
        $bestPageEn = null;

        foreach ($pageFiles as $pageFile) {
            $overlap = count(array_intersect($profileWords, $pageWordSets[$pageFile]));

            if ($pageLang[$pageFile] === 'lv') {
                if (isset($usedPagesLv[$pageFile])) {
                    continue;
                }
                if ($overlap > $bestScoreLv) {
                    $bestScoreLv = $overlap;
                    $bestPageLv = $pageFile;
                }
                continue;
            }

            if ($pageLang[$pageFile] === 'en') {
                if (isset($usedPagesEn[$pageFile])) {
                    continue;
                }
                if ($overlap > $bestScoreEn) {
                    $bestScoreEn = $overlap;
                    $bestPageEn = $pageFile;
                }
            }
        }

        if ($bestPageLv === null || $bestPageEn === null) {
            $bestFallback1 = null;
            $bestFallback2 = null;
            $bestFallbackScore1 = 0;
            $bestFallbackScore2 = 0;

            foreach ($pageFiles as $pageFile) {
                if ($pageLang[$pageFile] !== null) {
                    continue;
                }
                if (isset($usedPagesFallback[$pageFile])) {
                    continue;
                }

                $overlap = count(array_intersect($profileWords, $pageWordSets[$pageFile]));
                if ($overlap > $bestFallbackScore1) {
                    $bestFallback2 = $bestFallback1;
                    $bestFallbackScore2 = $bestFallbackScore1;
                    $bestFallback1 = $pageFile;
                    $bestFallbackScore1 = $overlap;
                } elseif ($overlap > $bestFallbackScore2) {
                    $bestFallback2 = $pageFile;
                    $bestFallbackScore2 = $overlap;
                }
            }

            if ($bestPageLv === null && $bestFallback1 !== null) {
                $bestPageLv = $bestFallback1;
                $usedPagesFallback[$bestFallback1] = true;
            }
            if ($bestPageEn === null && $bestFallback2 !== null) {
                $bestPageEn = $bestFallback2;
                $usedPagesFallback[$bestFallback2] = true;
            }
            if ($bestPageEn === null && $bestPageLv === null && $bestFallback1 !== null) {
                $bestPageLv = $bestFallback1;
                $usedPagesFallback[$bestFallback1] = true;
            }
            if ($bestPageEn === null && $bestPageLv !== null) {
                $bestScoreEn = $bestScoreLv;
            }
            if ($bestPageLv === null && $bestPageEn !== null) {
                $bestScoreLv = $bestScoreEn;
            }
        }

        if ($bestPageLv !== null) {
            $usedPagesLv[$bestPageLv] = true;
        }
        if ($bestPageEn !== null) {
            $usedPagesEn[$bestPageEn] = true;
        }

        $trainers[] = [
            'id' => 'trainer' . ($idx + 1),
            'name' => ucwords(implode(' ', $profileWords)),
            'profile_image' => $profileFile,
            'trainer_page_image_lv' => $bestPageLv,
            'trainer_page_image_en' => $bestPageEn,
            '_match_score_lv' => $bestScoreLv,
            '_match_score_en' => $bestScoreEn,
        ];
    }

    return $trainers;
}

/**
 * Resize and letterbox an image into an exact target JPEG.
 */
function process_image_letterbox(
    string $srcPath,
    string $dstPath,
    int $targetW,
    int $targetH,
    array $bgRgb = [255, 255, 255],
    int $quality = 92,
    string $align = 'center'
): bool {
    if (!function_exists('imagecreatefromjpeg')) {
        return false;
    }

    $ext = strtolower(pathinfo($srcPath, PATHINFO_EXTENSION));
    switch ($ext) {
        case 'jpg':
        case 'jpeg':
            $src = @imagecreatefromjpeg($srcPath);
            break;
        case 'png':
            $src = @imagecreatefrompng($srcPath);
            break;
        case 'gif':
            $src = @imagecreatefromgif($srcPath);
            break;
        case 'webp':
            $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($srcPath) : false;
            break;
        default:
            return false;
    }

    if (!$src) {
        return false;
    }

    $srcW = imagesx($src);
    $srcH = imagesy($src);
    $scale = min($targetW / $srcW, $targetH / $srcH);
    $fitW = (int) round($srcW * $scale);
    $fitH = (int) round($srcH * $scale);
    $offX = (int) round(($targetW - $fitW) / 2);
    $offY = $align === 'top' ? 0 : (int) round(($targetH - $fitH) / 2);

    $dst = imagecreatetruecolor($targetW, $targetH);
    $bg = imagecolorallocate($dst, $bgRgb[0], $bgRgb[1], $bgRgb[2]);
    imagefill($dst, 0, 0, $bg);
    imagecopyresampled($dst, $src, $offX, $offY, 0, 0, $fitW, $fitH, $srcW, $srcH);

    $result = imagejpeg($dst, $dstPath, $quality);
    imagedestroy($src);
    imagedestroy($dst);

    return $result !== false;
}

/** Pack all files under a directory into a zip archive. */
function zip_dir(string $dir, string $zipPath): void
{
    $zip = new ZipArchive();
    $result = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($result !== true) {
        throw new RuntimeException('Cannot open ZIP for writing: ' . $zipPath . ' (code ' . $result . ')');
    }

    $realDir = rtrim((string) realpath($dir), DIRECTORY_SEPARATOR);
    $base = strlen($realDir) + 1;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($it as $file) {
        if ($file->isDir()) {
            continue;
        }

        $abs = (string) $file->getRealPath();
        $relative = str_replace('\\', '/', substr($abs, $base));
        $zip->addFile($abs, $relative);
    }

    $zip->close();
}
