<?php
declare(strict_types=1);

/**
 * Generate the main trainer-grid page (index.html).
 *
 * S3-compatible layout:
 *  - No @font-face / .otf (system fonts only)
 *  - No vw/vh units — all dimensions computed in px from SCREEN_W x SCREEN_H
 *  - No object-fit — relies on images pre-processed to exact card dimensions
 *  - No position:fixed (unreliable on S3) — footer uses position:absolute
 *  - Float-based grid (works on IE6+ / old WebKit)
 */
function render_index(array $trainers, string $gymName, string $nextPage): string
{
    $count = count($trainers);
    $cols  = $count > 6 ? 3 : 2;

    // ── Pixel layout — reads SCREEN_W/SCREEN_H constants set in build.php ─
    $sw       = defined('SCREEN_W') ? (int) SCREEN_W : 1080;
    $sh       = defined('SCREEN_H') ? (int) SCREEN_H : 1920;
    $gridW    = (int) round($sw * 0.96);
    $cardOut  = (int) floor($gridW / $cols);   // outer card box width (floor avoids overflow)
    $cardPad  = (int) round($gridW * 0.01);    // margin around each card
    $cardW    = $cardOut - 2 * $cardPad;        // inner image area width
    $cardH    = (int) round($cardW * 0.90);    // inner image area height (90% aspect)
    $gMarginT = (int) round($sw * 0.025);      // grid top margin
    $gMarginB = (int) round($sw * 0.12);       // grid bottom margin
    $footerH  = (int) round($sw * 0.14);       // footer height
    $logoH    = (int) round($sw * 0.08);       // logo image height
    $logoBottom = (int) round($sh * 0.00);     // logo distance from bottom of screen
    $logoRight = (int) round($sw * 0.01);       // logo distance from right edge of screen

    $safeNextPage = htmlspecialchars($nextPage, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // ── Trainer cards ─────────────────────────────────────────────────────
    $cards = '';
    foreach ($trainers as $t) {
        $imgSrc = htmlspecialchars('data/profiles/' . basename($t['profile_image']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $entryDetail = $t['trainer_page_image_lv'] !== null
            ? $t['id'] . '_lv.html'
            : ($t['trainer_page_image_en'] !== null ? $t['id'] . '_en.html' : 'index.html');
        $href   = htmlspecialchars($entryDetail, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $alt    = htmlspecialchars((string) $t['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $cards .= <<<CARD

        <a href="{$href}" class="trainer-card">
            <div class="card-frame">
                <img src="{$imgSrc}" alt="{$alt}">
            </div>
        </a>
CARD;
    }

    // ── Assemble page ─────────────────────────────────────────────────────
    return <<<HTML
<!DOCTYPE html>
<html lang="et">
<head>
<meta charset="UTF-8">
<title>Personaaltreenerid &mdash; MyFitness</title>
<style>
* { margin: 0; padding: 0; }
body {
    background: #ffffff;
    font-family: Arial, Helvetica, sans-serif;
    width: {$sw}px;
    height: {$sh}px;
    overflow: hidden;
    position: relative;
}

#header-img {
    display: block;
    margin-left: auto;
    margin-right: auto;
}
#grid {
    width: {$gridW}px;
    margin: {$gMarginT}px auto {$gMarginB}px auto;
    overflow: hidden;
}
.trainer-card {
    float: left;
    width: {$cardOut}px;
    display: block;
    text-decoration: none;
    outline: 0;
}
.card-frame {
    width: {$cardW}px;
    height: {$cardH}px;
    margin: {$cardPad}px;
    overflow: hidden;
    background: #ffffff;
}
.card-frame img {
    width: {$cardW}px;
    height: {$cardH}px;
    display: block;
}
#footer {
    position: absolute;
    bottom: {$logoBottom}px;
    right: 0;
    height: {$footerH}px;
}
.logo-img {
    padding-right: {$logoRight}px;
    height: {$logoH}px;
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

<div id="page">
<img id="header-img" src="assets/banner.jpg" alt="Personaaltreenerid">

<div id="grid">
    {$cards}
    <div style="clear:both;"></div>
</div>
</div>
<div id="footer">
    <img src="assets/logo.png" alt="Logo" class="logo-img">
</div>

</body>
</html>
HTML;
}
