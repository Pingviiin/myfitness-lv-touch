# Touchscreen Trainer Website Specs

## 1. Purpose
A touchscreen-optimized website for displaying gym trainers in a visual grid and rotating detailed trainer profile views automatically as a slideshow.

## 2. Display Targets
- Portrait 1080x1920
- Portrait 2160x3840

UI must scale cleanly across both resolutions with touch-friendly spacing and typography.

## 3. Main Grid Page (index.html)
- Top blue header bar
- Header text (`Personaaltreenerid`) #0078bd
- White page background
- Trainer profile grid in center content area
- Typical layouts:
  - 2 columns x 3 rows
  - Up to 3 columns x 5 rows for clubs with more trainers
- Bottom-right gym logo area
- The trainer profile pictures might vary a couple of pixels in size.
- Make them all the same size on the website and so that the name at the bottom of the pictures doesn't get cropped and the qr code at the top-right corner.

Autoplay behavior on this page:
- Page is shown for 15 seconds per appearance.
- This page is the mandatory transition page between trainer detail pairs.
- Initial startup page is always this main grid page.

## 4. Detailed Trainer Pages (trainer.html variants)
- Displays:
  - Trainer page image from `./data/trainer_pages/`
Navigation behavior:
  - Tap anywhere on detail page returns to trainer grid page


## 5. Browser Restrictions
The target touchscreen displays have limited browser capabilities:
- No support for modern CSS features (CSS Grid, custom properties/variables, many flexbox features)
- No support for ES6+ JavaScript (arrow functions, Promises, async/await, modules) without polyfills
- No WebGL or WebRTC
- No service workers or PWA features
- No H.265/HEVC video decoding in-browser
- Limited codec support — typically H.264 + some basic formats only
- No support for modern web APIs (Fetch API, IntersectionObserver, etc.)

Autoplay implementation compatibility notes:
- Slideshow logic must use legacy-compatible JavaScript (ES5 style).
- Do not rely on unsupported APIs such as Fetch, Promise-based flows, or module loading.
- Keep static pregenerated pages and relative local asset paths for signage player compatibility.

## 6. Acceptance Criteria
- Every displayed page in the loop remains visible for 15 seconds.
- Playback starts on Main grid after app launch or restart.
- Playback follows this exact order pattern for all trainers:
  - Main grid -> TrainerN LV -> TrainerN EN -> Main grid -> TrainerN+1 LV -> TrainerN+1 EN
- Trainer detail pages are always consecutive language pairs for the same trainer (LV then EN).
- After the final trainer EN page, loop continues without stopping.