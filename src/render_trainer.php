<?php
declare(strict_types=1);

/**
 * Generate an individual trainer detail page.
 * Page auto-advances after 15 seconds.
 *
 * S3-compatible: no object-fit, pixel-exact layout, basic ES5 script.
 * Relies on trainer page images pre-processed to SCREEN_W × SCREEN_H by build.php.
 */
function render_trainer_detail(array $trainer, string $language, string $nextPage): string
{
    $sw = defined('SCREEN_W') ? (int) SCREEN_W : 1080;
    $sh = defined('SCREEN_H') ? (int) SCREEN_H : 1920;

    $name   = htmlspecialchars((string) $trainer['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $safeNextPage = htmlspecialchars($nextPage, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $imgTag = '';
    $pageImage = null;
    if ($language === 'lv') {
        $pageImage = $trainer['trainer_page_image_lv'] !== null
            ? $trainer['trainer_page_image_lv']
            : $trainer['trainer_page_image_en'];
    } else {
        $pageImage = $trainer['trainer_page_image_en'] !== null
            ? $trainer['trainer_page_image_en']
            : $trainer['trainer_page_image_lv'];
    }

    if ($pageImage !== null) {
        $imgSrc = htmlspecialchars(
            'data/trainer_pages/' . basename((string) $pageImage),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
        $imgTag = "<img id=\"detail-img\" src=\"{$imgSrc}\" alt=\"{$name}\">";
    }

    return <<<HTML
<!DOCTYPE html>
<html lang="et">
<head>
<meta charset="UTF-8">
<title>{$name}</title>
<style>
* { margin: 0; padding: 0; }
html, body {
    width: {$sw}px;
    height: {$sh}px;
    overflow: hidden;
    background: #000000;
}
#back-link {
    display: block;
    width: {$sw}px;
    height: {$sh}px;
    cursor: pointer;
}
#detail-img {
    width: {$sw}px;
    height: {$sh}px;
    display: block;
}
</style>
<script>
function goNext() {
    window.location.href = '{$safeNextPage}';
}
setTimeout(goNext, 15000);
</script>
</head>
<body>
<a id="back-link" href="{$safeNextPage}">
{$imgTag}
</a>
</body>
</html>
HTML;
}
