# Client Onboarding Wizard

`onboarding-wizard.html` is a single self-contained page with two modes. There is no backend and nothing is stored on a server.

## How it works

1. **Admin setup** (open the file with no `#` in the URL)
   - Enter the client name and contact.
   - Tick the services we are doing for them (Google Ads, Meta, SEO, and so on).
   - The access steps the client will see are picked automatically from those services. Click a chip to remove one, or add extras under "Add other access steps".
   - Fill in our account details once (Google access email, MCC ID, Meta Business Portfolio ID, etc.). They are saved in the browser.
   - Copy the generated link, or click "Email it" to open a prefilled email.

2. **Client wizard** (the generated link, `...onboarding-wizard.html#c=...`)
   - Welcome screen listing what we will set up.
   - "Your business" basics (required fields marked with *).
   - One step per platform with numbered, click-by-click instructions that show our IDs and emails as copy buttons, plus the fields we need back (Customer ID, Property ID, ad account ID, etc.).
   - Each step has a "done" checkbox and a blockers box, so the client can keep going even if they are stuck on one.
   - Progress is saved in their browser automatically, so they can come back later.
   - Review and submit: posts the answers to the webhook if one is set, otherwise opens an email to the agency with the summary. "Copy summary" and "Download JSON" are always available.

## Hosting

Upload the file anywhere static (Duda, Netlify, S3, the existing site). The client link is built from the page's own URL, so wherever the admin opens the file is where the client links will point.

## Getting submissions automatically

Paste a Zapier or Make "catch hook" URL into the Webhook field in admin setup. The wizard POSTs a JSON body with `client`, `services`, `business`, and an `access` array (one entry per platform step with the fields the client filled in, a `done` flag and any `blocker` text). From there it can go to Asana, Slack, Gmail or a sheet.

## Customising

Everything is data at the top of the `<script>` block:

- `SERVICES` lists what we sell and which access modules each one needs.
- `MODULES` holds each platform's instructions, fields and confirm text. `{{mcc}}`, `{{gEmail}}` and similar are filled from the agency details.
- `AGENCY_FIELDS` are the agency-side values asked for in admin setup.
- `BUSINESS_FIELDS` is the business basics form.
- `LOGO_URL` at the very top: set it to a hosted logo URL to replace the text wordmark.
