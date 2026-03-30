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
        $s = $src . DIRECTORY_SEPARATOR . $item;
        $d = $dst . DIRECTORY_SEPARATOR . $item;
        is_dir($s) ? rcopy($s, $d) : copy($s, $d);
    }
}

/**
 * Create a URL-safe ASCII slug from a trainer name.
 * Handles common Estonian / Nordic characters.
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
    $slug = preg_replace('/[^a-z0-9\-]/', '', $slug);
    $slug = preg_replace('/-{2,}/', '-', $slug);
    return trim($slug, '-');
}

/**
 * Extract meaningful name tokens from a filename.
 * Lowercases, normalises separators, then removes:
 *   - pure numbers (e.g. 733, 480, 1070)
 *   - dimension strings (e.g. 733x600)
 *   - known noise words (New, Vaike, Vaike2, Old, …)
 */
function extract_name_words(string $filename): array
{
    static $noiseWords = [
        'new', 'old', 'vaike', 'vaike2', 'small', 'big', 'large',
        'crop', 'page', 'full', 'thumb', 'thumbnail', 'photo', 'pic',
        'portrait', 'profile', 'img', 'image',
    ];
    $base  = strtolower(pathinfo($filename, PATHINFO_FILENAME));
    $base  = str_replace(['-', '_', '.'], ' ', $base);
    $words = preg_split('/\s+/', trim($base), -1, PREG_SPLIT_NO_EMPTY);
    $words = array_filter($words, function (string $w) use ($noiseWords): bool {
        if (preg_match('/^\d+$/', $w))      return false;  // pure number
        if (preg_match('/^\d+x\d+$/i', $w)) return false;  // e.g. 733x600
        if (in_array($w, $noiseWords, true)) return false;  // noise word
        return true;
    });
    return array_values($words);
}

/**
 * Scan data/profiles/ and data/trainer_pages/, match each profile image to
 * the best-fitting detail image by word-overlap score (greedy best-first),
 * and return trainer records with assigned IDs (trainer1, trainer2, …).
 */
function discover_trainers(): array
{
    $supported = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    $scanImages = function (string $dir) use ($supported): array {
        if (!is_dir($dir)) {
            return [];
        }
        $files = [];
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            if (in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $supported, true)) {
                $files[] = $f;
            }
        }
        return $files;
    };

    $profileFiles = $scanImages(DATA . '/profiles');
    $pageFiles    = $scanImages(DATA . '/trainer_pages');

    if (empty($profileFiles)) {
        return [];
    }

    // Sort profiles by cleaned name for stable, predictable ID assignment
    usort($profileFiles, function (string $a, string $b): int {
        return strcmp(
            implode(' ', extract_name_words($a)),
            implode(' ', extract_name_words($b))
        );
    });

    // Pre-compute word sets for all detail-page files
    $pageWordSets = [];
    foreach ($pageFiles as $pf) {
        $pageWordSets[$pf] = extract_name_words($pf);
    }

    $usedPages = [];
    $trainers  = [];

    foreach ($profileFiles as $idx => $profileFile) {
        $profileWords = extract_name_words($profileFile);
        $bestScore    = 0;
        $bestPage     = null;

        foreach ($pageFiles as $pageFile) {
            if (isset($usedPages[$pageFile])) {
                continue;
            }
            $overlap = count(array_intersect($profileWords, $pageWordSets[$pageFile]));
            if ($overlap > $bestScore) {
                $bestScore = $overlap;
                $bestPage  = $pageFile;
            }
        }

        if ($bestPage !== null) {
            $usedPages[$bestPage] = true;
        }

        $trainers[] = [
            'id'                 => 'trainer' . ($idx + 1),
            'name'               => ucwords(implode(' ', $profileWords)),
            'profile_image'      => $profileFile,
            'trainer_page_image' => $bestPage,
            '_match_score'       => $bestScore,
        ];
    }

    return $trainers;
}

/**
 * Render a text string onto a solid-colour background and save as JPEG.
 * Uses GD + FreeType (imagettftext) so the font renders exactly.
 *
 * Returns false when GD/FreeType is unavailable or the font file is missing.
 */
function generate_header_image(
    string $text,
    string $fontPath,
    string $dstPath,
    int    $canvasW,
    int    $fontSize,
    int    $padV,
    array  $bgRgb   = [0, 120, 189],
    array  $textRgb = [255, 255, 255],
    int    $quality  = 95): bool
{
    if (!function_exists('imagettftext') || !file_exists($fontPath)) {
        return false;
    }
    $bbox = imagettfbbox($fontSize, 0, $fontPath, $text);
    if ($bbox === false) {
        return false;
    }
    // bbox indices: 0/1 lower-left, 2/3 lower-right, 4/5 upper-right, 6/7 upper-left
    // Y is relative to the text baseline: negative = above, positive = below (descent)
    $textW   = abs($bbox[4] - $bbox[6]);
    $ascent  = -$bbox[7];   // pixels above baseline
    $descent =  $bbox[1];   // pixels below baseline
    $textH   = $ascent + $descent;
    $canvasH = $textH + 2 * $padV;

    $img = imagecreatetruecolor($canvasW, $canvasH);
    $bg  = imagecolorallocate($img, $bgRgb[0], $bgRgb[1], $bgRgb[2]);
    $fg  = imagecolorallocate($img, $textRgb[0], $textRgb[1], $textRgb[2]);
    imagefill($img, 0, 0, $bg);

    $x = (int) round(($canvasW - $textW) / 2);
    $y = $padV + $ascent;   // baseline position
    imagettftext($img, $fontSize, 0, $x, $y, $fg, $fontPath, $text);

    $result = imagejpeg($img, $dstPath, $quality);
    imagedestroy($img);
    return $result !== false;
}

/**
 * Resize and letterbox $srcPath into an exact $targetW × $targetH JPEG at $dstPath.
 * The source image is scaled to fit (preserving aspect ratio) and centred on a
 * solid $bgRgb background — equivalent to CSS object-fit: contain, baked into
 * the image file so no CSS tricks are needed.
 *
 * Supported source formats: JPEG, PNG, GIF, WebP (if GD was compiled with WebP).
 * Returns false when GD is unavailable or the source cannot be decoded.
 */
function process_image_letterbox(
    string $srcPath,
    string $dstPath,
    int    $targetW,
    int    $targetH,
    array  $bgRgb = [255, 255, 255],
    int    $quality = 92,
    string $align = 'center'): bool
{
    if (!function_exists('imagecreatefromjpeg')) {
        return false;
    }

    $ext = strtolower(pathinfo($srcPath, PATHINFO_EXTENSION));
    switch ($ext) {
        case 'jpg':
        case 'jpeg': $src = @imagecreatefromjpeg($srcPath); break;
        case 'png':  $src = @imagecreatefrompng($srcPath);  break;
        case 'gif':  $src = @imagecreatefromgif($srcPath);  break;
        case 'webp':
            $src = function_exists('imagecreatefromwebp')
                ? @imagecreatefromwebp($srcPath)
                : false;
            break;
        default: return false;
    }

    if (empty($src)) {
        return false;
    }

    $srcW  = imagesx($src);
    $srcH  = imagesy($src);
    $scale = min($targetW / $srcW, $targetH / $srcH);
    $fitW  = (int) round($srcW * $scale);
    $fitH  = (int) round($srcH * $scale);
    $offX  = (int) round(($targetW - $fitW) / 2);
    $offY  = $align === 'top' ? 0 : (int) round(($targetH - $fitH) / 2);

    $dst = imagecreatetruecolor($targetW, $targetH);
    $bg  = imagecolorallocate($dst, $bgRgb[0], $bgRgb[1], $bgRgb[2]);
    imagefill($dst, 0, 0, $bg);
    imagecopyresampled($dst, $src, $offX, $offY, 0, 0, $fitW, $fitH, $srcW, $srcH);

    $result = imagejpeg($dst, $dstPath, $quality);
    imagedestroy($src);
    imagedestroy($dst);

    return $result !== false;
}

/** Pack all files under $dir into a zip archive at $zipPath. */
function zip_dir(string $dir, string $zipPath): void
{
    $zip    = new ZipArchive();
    $result = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($result !== true) {
        throw new RuntimeException("Cannot open ZIP for writing: $zipPath (code $result)");
    }
    $realDir = rtrim((string) realpath($dir), DIRECTORY_SEPARATOR);
    $base    = strlen($realDir) + 1;
    $it      = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if ($file->isDir()) {
            continue;
        }
        $abs      = (string) $file->getRealPath();
        $relative = str_replace('\\', '/', substr($abs, $base));
        $zip->addFile($abs, $relative);
    }
    $zip->close();
}
