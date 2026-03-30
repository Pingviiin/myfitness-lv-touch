# Samsung Signage Player Browser Restrictions

## S6 Player (QM32R-B / SSSP6 / Tizen 4.0)

### Engine
- Runs **Tizen 4.0**, capped at **Chromium 56** (equivalent to Chrome early 2017)

### CSS Restrictions
- **`object-fit`** — partially supported or broken; `object-fit: contain` may not work
- **CSS Grid** — not supported
- **CSS Variables (`--custom-props`)** — not supported
- **`position: sticky`** — not supported
- **`vw`/`vh` units inside flexbox children** — buggy and unreliable
- **`transform`** — supported but has known compositing bugs in some Tizen 4 builds
- **`@font-face` with `.otf`** — unreliable; use `.woff` or `.woff2` instead
- **Flexbox** — supported but buggy (e.g. `align-items: center` may ignore height in some contexts)

### JavaScript Restrictions
- No `async/await` without a polyfill
- No ES6 modules (`import/export`)
- No `fetch` API — use `XMLHttpRequest`
- No `Promise.finally()`
- No `IntersectionObserver`
- No `ResizeObserver`

### Media & Asset Restrictions
- No WebP image support
- No HEVC/H.265 via `<video>` in browser context
- No `<picture>` element `srcset` support

### Other
- No reversed screen orientations supported
- Local file access is sandboxed — paths must be relative and assets bundled
- `window.localStorage` works but can behave inconsistently across reboots
- No dev tools / remote debugging without Tizen Studio setup

---

## S3 Player (SSSP3)

### Engine
- Predates Tizen — runs **Linux with a WebKit-based engine** (not Chromium)
- Roughly equivalent to Safari/WebKit from ~2013

### CSS Restrictions
- **Flexbox** — completely unsupported or broken
- **CSS Grid** — not supported
- **CSS Variables** — not supported
- **`object-fit`** — not supported at all
- **`vw`/`vh` units** — unreliable or completely broken
- **`calc()`** — not supported
- **`transform: translate()`** — partially broken
- **`position: sticky`** — not supported
- **`position: fixed`** — unreliable
- **`border-radius`** — requires `-webkit-` prefix
- **`box-shadow`** — requires `-webkit-` prefix
- **`@font-face`** — very limited; `.otf` will almost certainly fail, use system fonts only

### JavaScript Restrictions
- No ES6 whatsoever (no `let`, `const`, arrow functions, template literals)
- No `fetch` — use `XMLHttpRequest` only
- No `Promise`
- No `addEventListener` on some elements — use `onclick` style handlers instead
- Very limited `localStorage`

### Media & Asset Restrictions
- No WebP images
- PNG transparency can be buggy

### Other
- No longer updated by Samsung — incompatible with modern web standards
- Viewport meta tag behaviour is inconsistent
- Many digital signage platforms have dropped S3 support entirely

---

## Practical Recommendations

| Feature | S6 (Chromium 56) | S3 (WebKit ~2013) |
|---|---|---|
| Flexbox | ⚠️ Buggy | ❌ No |
| CSS Grid | ❌ No | ❌ No |
| `vw`/`vh` | ⚠️ Buggy | ❌ No |
| `object-fit` | ⚠️ Buggy | ❌ No |
| `transform` | ⚠️ Buggy | ⚠️ Buggy |
| `@font-face .otf` | ⚠️ Unreliable | ❌ No |
| ES6 JS | ❌ No | ❌ No |
| `fetch` | ❌ No | ❌ No |
| WebP images | ❌ No | ❌ No |

**For S6:** Use hardcoded `px` values, `position: absolute` with manual arithmetic, avoid flexbox for vertical centering.

**For S3:** Write IE8-era CSS — everything in `px`, no modern layout, `-webkit-` prefixes on everything, `position: absolute` with hardcoded coordinates. Consider using a separate template or an external player (e.g. Raspberry Pi) instead.
