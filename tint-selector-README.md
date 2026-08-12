# Window Tint Selector — embeddable widget

A self-contained window-tint preview tool (like tintsim.com / cuttingedgetint.com).
Visitors pick a vehicle and a tint darkness (VLT %) for the **Front**, **Back**, and
**Windshield** windows and see the glass darken live, then hit a call-to-action.

Everything lives in one file: **`tint-selector.html`**. No build step, no server,
no external dependencies. It works on Duda, WordPress, or any site.

---

## 1. Customize it per client (no code — just the URL)

All branding is set with URL parameters, so **one file works for every client**.
Add them after `tint-selector.html?`:

| Parameter | What it does | Example |
|-----------|--------------|---------|
| `shop`   | Heading / business name        | `shop=Cutting%20Edge%20Tint` |
| `phone`  | Shows a click-to-call pill      | `phone=555-123-4567` |
| `accent` | Brand color (URL-encode `#`)    | `accent=%23f4511e` |
| `cta`    | Button label                    | `cta=Book%20My%20Appointment` |
| `url`    | Where the button links (the visitor's tint choices are appended automatically as a lead) | `url=https://client.com/contact` |
| `footer` | Small line of fine print        | `footer=Serving%20Dallas%20since%202008` |

**Example:**
```
tint-selector.html?shop=Cutting%20Edge%20Tint&phone=555-123-4567&accent=%23f4511e&cta=Get%20a%20Free%20Quote&url=https://cuttingedgetint.com/contact
```
When someone clicks the button, their selection is passed to the contact page, e.g.
`...contact?vehicle=Sedan&front=20%&rear=05%&windshield=Factory` — so the shop knows
what the customer wants.

---

## 2. Embed it (works the same on Duda and WordPress)

**Recommended: iframe** (host the file, drop in one snippet). Upload
`tint-selector.html` to any hosting (the client's server, an S3 bucket, Netlify, etc.),
then paste this where you want it:

```html
<iframe
  src="https://YOUR-HOST/tint-selector.html?shop=Cutting%20Edge%20Tint&phone=555-123-4567&url=https://client.com/contact"
  style="width:100%; max-width:1080px; height:1150px; border:0; overflow:hidden;"
  loading="lazy"
  title="Window Tint Selector"></iframe>
```

### Duda
1. Drag an **Embed / HTML** widget onto the page.
2. Paste the iframe snippet above.
3. Save & publish. (On mobile, increase `height` to ~1500px so nothing is cut off.)

### WordPress
- **Block editor:** add a **Custom HTML** block → paste the iframe → Update.
- **Elementor:** add an **HTML** widget → paste the iframe.
- **Classic editor:** switch to the **Text** (not Visual) tab → paste the iframe.

> Tip: because the tool is one self-contained file, you can also paste the *entire
> contents* of `tint-selector.html` directly into a Custom HTML block instead of using
> an iframe. The iframe is cleaner and keeps the widget's styles fully isolated from
> the theme, so it's the recommended route.

---

## 3. Swap in real vehicle photos (optional, best-looking)

Out of the box it ships with clean built-in car illustrations so it works instantly.
For photo-realistic previews like the reference sites, add a photo + its window
regions in the `VEHICLES` list near the top of the `<script>`:

```js
{
  name: "Tesla Model S",
  viewBox: "0 0 1600 900",                 // = the image's width height
  image: "https://cdn.client.com/tesla.png", // or a data: URI
  zones: {
    front:      [ [[x,y],[x,y],[x,y],[x,y]] ],   // polygon around the front glass
    rear:       [ [[x,y],[x,y],[x,y],[x,y]] ],
    windshield: [ [[x,y],[x,y],[x,y],[x,y]] ]
  }
}
```

Each zone is one or more polygons in the image's pixel coordinates. Use a transparent
PNG of the car (or a side/3-4 photo on a clean background). **Send me any car photo and
I'll trace the window polygons for you** — then it looks exactly like the screenshot
you shared.

---

## 4. Height reference for the iframe

| Screen | Suggested `height` |
|--------|--------------------|
| Desktop | ~1150px |
| Tablet  | ~1300px |
| Mobile  | ~1550px |

If you want the iframe to auto-resize to its content, ask and I'll add a tiny
postMessage resizer.
