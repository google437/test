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
   - Review and submit: posts the answers to the webhook if one is set, otherwise opens an email to team@envisionmktg.com with the summary. Long summaries are copied to the clipboard and a short email opens for the client to paste into. "Copy summary" and "Download JSON" are always available.
   - The recipient is set by `SUBMIT_TO` at the top of the script, and the admin "Send completed onboardings to" field can override it per link.

## Hosting

Upload the file anywhere static (Duda, Netlify, S3, the existing site). The client link is built from the page's own URL, so wherever the admin opens the file is where the client links will point.

## Getting submissions automatically (Google Apps Script)

`onboarding-email-script.gs` is a small Google Apps Script web app. When a client submits, the wizard posts their answers to it and it emails a formatted summary to team@envisionmktg.com, with Reply-To set to the client. The recipient is fixed inside the script, so the public URL can't be used to email anyone else.

1. Go to script.google.com while signed in to the Google account that should send the emails. Click **New project** and name it "Envision Onboarding".
2. Delete the sample code in `Code.gs`, paste in all of `onboarding-email-script.gs`, and save.
3. Click **Deploy > New deployment**. Pick type **Web app**. Set **Execute as: Me** and **Who has access: Anyone**. Click **Deploy**.
4. Approve the permissions when asked. Google may warn that the app is unverified: click **Advanced**, then **Go to Envision Onboarding**, then **Allow**.
5. Copy the **Web app URL** that ends in `/exec`. Opening it in a browser should show `{"ok":true,...}`.
6. Put that URL in `SUBMIT_WEBHOOK` at the top of the script in `onboarding-wizard.html`, then run `python3 build-duda-onboarding.py` and re-paste the Duda code. For a quick test without editing code, paste it into the "Submission URL override" field in admin setup. It is then built into every link made from that browser.

If the script can't be reached, the wizard falls back to opening the client's email app addressed to team@envisionmktg.com.

To change the script later, edit it, then use **Deploy > Manage deployments > Edit > New version**. That keeps the same URL. Google limits sending to about 100 emails a day on a personal Gmail account and about 1,500 on Google Workspace.

## Customising

Everything is data at the top of the `<script>` block:

- `SERVICES` lists what we sell and which access modules each one needs.
- `MODULES` holds each platform's instructions, fields and confirm text. `{{mcc}}`, `{{gEmail}}` and similar are filled from the agency details.
- `AGENCY_FIELDS` are the agency-side values asked for in admin setup.
- `BUSINESS_FIELDS` is the business basics form.
- `LOGO_URL` at the very top: set it to a hosted logo URL to replace the text wordmark.

## Duda version

`duda-onboarding-wizard.html` is the paste-ready block for a Duda HTML widget, like the calculator embed.

1. Create a new page in Duda (for example `/onboarding`). Hide it from the navigation and turn off search engine indexing in the page's SEO settings.
2. Add a widget: Developer Tools > HTML. Open the HTML editor and paste the entire contents of `duda-onboarding-wizard.html`.
3. Publish, then open the live page with `#admin` on the end (for example `yoursite.com/onboarding#admin`). Bookmark that for the team.
4. Fill in the agency details once, set up a client, and copy or email the generated link.

Visitors who land on the page without a link see a short "use the link we emailed you" message. The `#admin` view is hidden, not password protected. It only holds IDs the clients see anyway.

Scripts in HTML widgets usually only run in Duda's preview or on the published page, not inside the editor canvas.

If your site has a taller sticky header, change `--evo-sticky-top` (default `100px`) on `.evo` so the wizard sidebar sits below it.

**Keeping it in sync:** edit `onboarding-wizard.html`, then run `python3 build-duda-onboarding.py` to regenerate the Duda version.
