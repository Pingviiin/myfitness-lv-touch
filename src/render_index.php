<?php
declare(strict_types=1);

/**
 * Generate the main trainer-grid page (index.html).
 * Float-based and pixel-based for old embedded browser compatibility.
 */
function render_index(array $trainers): string
{
    if (empty($trainers)) {
        throw new InvalidArgumentException('render_index requires at least one trainer.');
    }

    $validated = [];
    foreach ($trainers as $index => $trainer) {
        if (!is_array($trainer)) {
            throw new InvalidArgumentException('Trainer at index ' . $index . ' must be an array.');
        }

        foreach (['id', 'name', 'profile_image'] as $requiredKey) {
            if (!array_key_exists($requiredKey, $trainer)) {
                throw new InvalidArgumentException('Trainer at index ' . $index . ' is missing key: ' . $requiredKey);
            }

            $value = $trainer[$requiredKey];
            if (!is_scalar($value) || trim((string) $value) === '') {
                throw new InvalidArgumentException(
                    'Trainer at index ' . $index . ' has invalid value for key: ' . $requiredKey
                );
            }
        }

        $validated[] = $trainer;
    }

    $sw = defined('SCREEN_W') ? (int) SCREEN_W : 1080;
    $sh = defined('SCREEN_H') ? (int) SCREEN_H : 1920;
    $layout = compute_index_layout(count($validated), $sw, $sh);

    $nextPage = $validated[0]['id'] . '_lv.html';
    if (isset($validated[0]['_next_page']) && is_scalar($validated[0]['_next_page'])) {
        $override = trim((string) $validated[0]['_next_page']);
        if ($override !== '') {
            $nextPage = $override;
        }
    }

    $safeNextPage = htmlspecialchars($nextPage, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $cards = '';
    foreach ($validated as $trainer) {
        $imgSrc = htmlspecialchars('data/profiles/' . basename((string) $trainer['profile_image']), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $entryDetail = 'index.html';
        if (isset($trainer['trainer_page_image_lv']) && $trainer['trainer_page_image_lv'] !== null) {
            $entryDetail = (string) $trainer['id'] . '_lv.html';
        } elseif (isset($trainer['trainer_page_image_en']) && $trainer['trainer_page_image_en'] !== null) {
            $entryDetail = (string) $trainer['id'] . '_en.html';
        }

        $href = htmlspecialchars($entryDetail, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $alt = htmlspecialchars((string) $trainer['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $cards .= <<<CARD

        <a href="{$href}" class="trainer-card">
            <div class="card-frame">
                <img src="{$imgSrc}" alt="{$alt}">
            </div>
        </a>
CARD;
    }

    $gridW = (int) $layout['grid_w'];
    $cardOut = (int) $layout['card_outer_w'];
    $cardPad = (int) $layout['card_pad'];
    $cardW = (int) $layout['card_w'];
    $cardH = (int) $layout['card_h'];
    $gMarginT = (int) $layout['grid_margin_top'];
    $gMarginB = (int) $layout['grid_margin_bottom'];
    $footerH = (int) $layout['footer_h'];
    $logoH = (int) $layout['logo_h'];
    $logoBottom = (int) $layout['logo_bottom'];
    $logoRight = (int) $layout['logo_right'];

    return <<<HTML
<!DOCTYPE html>
<html lang="et">
<head>
<meta charset="UTF-8">
<title>Personaaltreenerid - MyFitness</title>
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
.clear {
    clear: both;
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
    <div class="clear"></div>
</div>
</div>
<div id="footer">
    <img src="assets/logo.png" alt="Logo" class="logo-img">
</div>

</body>
</html>
HTML;
}
