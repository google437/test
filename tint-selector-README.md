# Window Tint Selector — embeddable widget

A self-contained window-tint preview tool. Visitors pick a **Window Tint** shade
(VLT %) and see it applied to the car's glass, then hit a call-to-action.

Everything lives in one file: **`tint-selector.html`**. No build step, no server,
no dependencies. Works on Duda, WordPress, or any site.

---

## 1. Customize per client (no code — just the URL)

All branding is set with URL parameters, so one file works for every client. Add
them after `tint-selector.html?`:

| Parameter | What it does | Example |
|-----------|--------------|---------|
| `shop`   | Heading / business name     | `shop=Tru%20Tints` |
| `phone`  | Click-to-call pill          | `phone=555-123-4567` |
| `accent` | Brand color (URL-encode `#`)| `accent=%23f4511e` |
| `cta`    | Button label                | `cta=Book%20My%20Appointment` |
| `url`    | Where the button links (the visitor's tint choice is appended as a lead) | `url=https://tru-tints.com/contact` |
| `footer` | Small line of fine print    | `footer=Serving%20the%20area%20since%202008` |

**Example:**
```
tint-selector.html?shop=Tru%20Tints&phone=555-123-4567&accent=%23f4511e&url=https://tru-tints.com/contact
```
When someone clicks the button, their choice is passed along, e.g.
`...contact?vehicle=BMW%206%20Series&tint=20%25%20VLT`.

---

## 2. Embed it (same on Duda and WordPress)

**Recommended: iframe.** Upload `tint-selector.html` to any hosting, then paste:

```html
<iframe
  src="https://YOUR-HOST/tint-selector.html?shop=Tru%20Tints&phone=555-123-4567&url=https://tru-tints.com/contact"
  style="width:100%; max-width:1080px; height:900px; border:0; overflow:hidden;"
  loading="lazy"
  title="Window Tint Selector"></iframe>
```

### Duda
1. Drag an **Embed / HTML** widget onto the page.
2. Paste the iframe snippet.
3. Save & publish. (On mobile, bump `height` to ~1100px.)

### WordPress
- **Block editor:** add a **Custom HTML** block → paste the iframe → Update.
- **Elementor:** add an **HTML** widget → paste the iframe.
- **Classic editor:** switch to the **Text** tab → paste the iframe.

> You can also paste the entire contents of `tint-selector.html` directly into a
> Custom HTML block instead of using an iframe. The iframe is cleaner and keeps
> the widget's styles isolated from the theme, so it's the recommended route.

---

## 3. Change the car or the tint shades

Open the `<script>` in `tint-selector.html`:

- **The car** is the `VEHICLE` object: set `image` to any hosted photo URL, and
  `zones` to the window regions.
- **Window regions** are polygons in **fractions of the image** (0..1), so they
  auto-scale to the photo's real size — no need to know its pixel dimensions.
- **The shades** are the `LEVELS` array (defaults: 70 / 50 / 35 / 20 / 05).

### Aligning windows to a new photo
1. Open the tool in a browser with **`?align=1`** on the end of the URL.
2. Pick **Front / Back / Windshield**, then **drag the dots** onto the glass
   (use **Add point** to add corners).
3. Click **Copy zones** and paste the result into the `VEHICLE.zones` object.

---

## 4. iframe height reference

| Screen  | Suggested `height` |
|---------|--------------------|
| Desktop | ~900px  |
| Tablet  | ~1000px |
| Mobile  | ~1100px |

Ask if you'd like the iframe to auto-resize to its content and I'll add a small
postMessage resizer.
