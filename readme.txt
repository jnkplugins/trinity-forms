=== Trinity Forms – Contact Form Builder with Conditional Logic ===
Contributors: suresh1986
Tags: contact form, form builder, conditional logic, csv export, survey
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 2.24.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A free WordPress form builder with conditional logic, native entry storage, and CSV export — the basics other plugins gate behind $200+/yr plans.

== Description ==

**Tired of hitting a paywall the moment your form needs to do something useful?**

https://www.youtube.com/watch?v=gpTBkVRzSqs

Most WordPress form plugins hand you a free builder, then gate everything that
matters. The big-name form plugins put conditional logic, entry storage, and
Stripe behind plans that start around $200 and renew even higher. You end up
paying hundreds a year just to run a contact form.

Trinity Forms ships the whole builder for free — a drag-and-drop form builder with
conditional logic, native entry storage, CSV export, email notifications, and
spam protection, with no submission caps and no tracking. Payments and
integrations are Pro, from $29/year, only when you actually need them.

It's built for the WordPress shops that ship things: agencies, freelancers,
membership sites, donation pages, course creators, and local businesses that want
a real form tool without the renewal shock.

= What you can do today (free version) =

* Build forms two ways — a dedicated drag-and-drop **Visual Builder** where you drop fields into place, reorder, undo/redo and start from 12 ready-made templates, or the Gutenberg block editor you already know. New forms open in the builder; either editor can be used on any form.
* 32 field types: text, email, textarea, select, radio, checkbox, multiple choice, multi select, image choice, number, range slider, name, phone, address, country, date, time, website, password, list, hidden, HTML, page break, section, rating, consent, file upload (files are stored privately and only reach logged-in admins), and the pricing fields Product, Quantity, Option, Shipping and Total.
* Conditional logic — show, hide, and skip fields based on prior answers, with all-of / any-of matching and eight operators. Uncapped.
* Native entry storage in your own WordPress database, with a searchable admin Entries list and per-entry detail view.
* CSV export of all your submissions.
* Results report — turn any form into a survey or poll with per-option counts, percentages, and averages; plus quiz scoring with {quiz_score} / {quiz_max} merge tags.
* Email notifications with merge tags.
* Spam protection — honeypot, time-trap, and your pick of Cloudflare Turnstile, Google reCAPTCHA (v2 and invisible v3), or hCaptcha, plus optional Akismet.
* Per-form and global data retention controls, plus GDPR export/erase built into WordPress' own Personal Data tools.
* Form analytics — a views → submissions funnel with completion rate, per form.
* AI assistants — let Claude, Cursor or another MCP app read how your forms are built, their numbers and their entries (WordPress 6.9 or newer). Contact details are masked by default and files and passwords are never shown.
* Unlimited forms and unlimited submissions.

= Get Pro =

Pro is available at https://jnkplugins.com/trinity-forms/pricing.php (from $29/year, flat, and the renewal price is the year-one price). Pro adds:

* Payments — Stripe, PayPal, and Mollie. Hosted Checkout keeps your site PCI out-of-scope. If a licence ends, forms keep taking payments and everything visitors use keeps working; 30 days after expiry only adding new Pro features in the dashboard locks.
* CRM & marketing integrations — automatically send submissions to popular email-marketing, CRM, chat, spreadsheet, and automation tools, plus a generic HMAC-signed webhook.
* AI form generator — describe a form and it builds the fields, using your own OpenAI or Anthropic key (no per-request markup).
* AI assistants that build forms — your assistant creates forms, changes fields and conditional logic, publishes them and sorts entries, showing each change first; every change can be undone.
* Guided mode — an optional one-question-at-a-time flow with a progress bar.
* Advanced fields — signature, repeater, and calculation.
* Save & resume for partial submissions, and PDF export of submissions.
* Entry → post/CPT creation and a front-end submissions table.

Or bundle any two JnK plugins for ~20% off, or all three for ~25% off: https://license.jnkplugins.com/bundles

= Why Trinity Forms instead of the big form plugins? =

Trinity Forms puts the features other plugins sell as "Pro" in the free tier, and prices its actual Pro flat:

* Conditional logic, native entry storage, and CSV export — free.
* No submission caps, ever — free.
* Payments (Stripe, PayPal, Mollie): Pro, from $29/year flat, not bundled into a $200–$400/year plan.
* Renewal price is the same as year one. No "introductory pricing" trap.

See the full feature comparison at https://jnkplugins.com/trinity-forms/features.php

== Installation ==

1. Download the zip from https://jnkplugins.com/trinity-forms/ and upload it under Plugins > Add New > Upload Plugin.
2. Activate it through the Plugins screen.
3. Go to Forms > Add New to build your first form.
4. Embed it with the `[trinity-forms id="123"]` shortcode or the Trinity Forms block.

== Frequently Asked Questions ==

= Are there any submission limits? =

No. The free plugin stores unlimited submissions in your WordPress database.

= Does it phone home or require an account? =

No account. The zip from jnkplugins.com includes a small update client: it asks
our license server on jnkplugins.com whether a newer Trinity Forms is out,
sending your site address, the license key the zip came with and the installed
version, so WordPress can offer the update. Your form entries are never sent
anywhere. The only other external call is the optional anti-spam CAPTCHA
(Cloudflare Turnstile, Google reCAPTCHA, or hCaptcha), which runs solely when you
enable it and enter your own provider keys. See "External services" below.

= What happens to my data if I uninstall or my Pro license expires? =

Your data is preserved by default. Deactivating or deleting the plugin keeps
your forms and entries — nothing is wiped unless you explicitly turn on
"Delete all Trinity Forms data when the plugin is deleted" under Trinity Forms Settings,
in which case data is removed only when you delete the plugin.

If a Pro license expires, Pro keeps working for 30 days. After that, everything
your visitors see keeps working (forms with Pro fields, payments, the
integrations already set up), and only the Pro tools in your dashboard lock
until you renew. Nothing is ever deleted.

= Which editor do forms open in? =

New forms open in the drag-and-drop Visual Builder. Forms you built before
upgrading keep opening in the block editor, so an upgrade never moves your
existing work — change them individually, or switch the site default under
Forms → Settings → "Open forms in". Both editors read and write the same form,
so you can switch either way at any time, per form.

= What spam protection is included, and is it free? =

All of it is free: a honeypot, a time-trap, and your choice of Cloudflare
Turnstile, Google reCAPTCHA v2, invisible reCAPTCHA v3, or hCaptcha — plus
Akismet if you have it configured. See "External services" below for what each
CAPTCHA provider receives.

= Do you have other plugins that work with this? =

Yes — Trinity Forms is one of three JnK Plugins:

* [JnK Linkweave](https://jnkplugins.com/jnk-linkweave/?utm_source=wporg&utm_medium=readme&utm_campaign=listing) — server-side internal linking suggestions.
* [Trinity Forms](https://jnkplugins.com/trinity-forms/?utm_source=wporg&utm_medium=readme&utm_campaign=listing) — flat-priced form builder with Stripe.
* [EntryVault](https://jnkplugins.com/entryvault/?utm_source=wporg&utm_medium=readme&utm_campaign=listing) — Kanban CRM for form submissions.

Buy any two at ~20% off or all three at ~25% off. See [the bundle pricing](https://license.jnkplugins.com/bundles?utm_source=wporg&utm_medium=readme&utm_campaign=listing).

== Screenshots ==

1. The Visual Builder: add fields from the left, set them up on the right, and see the form as visitors will.
2. Entries: everything people send, kept in your own database, easy to filter, search and export.
3. One entry: every answer, private team notes, where it came from, and what happened to it.
4. Twelve ready-made templates: contact, quote, booking, RSVP, job application, order form and more.
5. Pro: send entries on to your email tool, CRM, team chat, spreadsheet or automation service.

== External services ==

The zip from jnkplugins.com checks our license server for updates: once or twice
a day it sends your site address, its license key and the installed version to
our license server on jnkplugins.com so WordPress can offer the update, and the
update itself downloads from there. No form data is sent. Terms:
https://jnkplugins.com/terms.php · Privacy: https://jnkplugins.com/privacy.php

Apart from that, Trinity Forms does not contact any external service by default.

If — and only if — you enable a CAPTCHA challenge for a form and enter your own
keys for the chosen provider under Trinity Forms Settings, the plugin contacts that
provider to verify that submissions are not from bots. You choose one provider:

* Cloudflare Turnstile — widget script `https://challenges.cloudflare.com/turnstile/v0/api.js`; verification POST to `https://challenges.cloudflare.com/turnstile/v0/siteverify`. Terms: https://www.cloudflare.com/website-terms/ · Privacy: https://www.cloudflare.com/privacypolicy/
* Google reCAPTCHA v2 — widget script `https://www.google.com/recaptcha/api.js`; verification POST to `https://www.google.com/recaptcha/api/siteverify`. Terms: https://policies.google.com/terms · Privacy: https://policies.google.com/privacy
* hCaptcha — widget script `https://js.hcaptcha.com/1/api.js`; verification POST to `https://api.hcaptcha.com/siteverify`. Terms: https://www.hcaptcha.com/terms · Privacy: https://www.hcaptcha.com/privacy

In each case the widget script loads only on pages that display a CAPTCHA-protected
form, and on submission the entered token, your secret key and the visitor's IP
address are sent to the selected provider to validate the challenge. This happens
only when you enable a CAPTCHA and configure its keys.

== Development ==

Trinity Forms is fully open source (GPLv2-or-later) and ships entirely uncompiled
alongside its build output, so no external download is needed to read or rebuild it.

* All PHP is human-readable and shipped as-is (there is no PHP build step).
* The Gutenberg block SOURCE is included in this plugin under `blocks/`. Every compiled
  file `build/<block>/index.js` is generated directly from its readable source
  `blocks/<block>/index.js` — e.g. `build/field-checkbox/index.js` comes from
  `blocks/field-checkbox/index.js`, and `build/form-block/index.js` from
  `blocks/form-block/` (`index.js`, `edit.js`, `save.js`). Shared helpers live in
  `blocks/field-shared.js`. Nothing in `build/` is authored by hand.
* The build uses @wordpress/scripts (https://www.npmjs.com/package/@wordpress/scripts),
  with the source and output directories set explicitly in package.json:

`npm install`
`npm run build`   (runs: wp-scripts build --webpack-src-dir=blocks --output-path=build)

Running that regenerates `build/` from `blocks/`. No other build step is required.

== Changelog ==

= 2.24.0 =
* Fixed: a Page break's "Next button text" and "Previous button text" are used on the form. They were saved but every step showed "Next" / "Previous".
* Fixed: on a multi-step form the Next button looks like Submit and Previous gets a matching outlined style (both were plain browser buttons), with even spacing, wrapping on a phone, the right order in right-to-left languages and readable on dark themes.
* Fixed: the Visual Builder's Settings now has the Restrictions: when the form opens and closes, entry limits (total, per user, per IP address) with their messages, and Require login. They are the same settings as the block editor's Restrictions tab, so a change in either editor shows in the other. Before, they were only in the block editor's settings box.

= 2.23.0 =
* Changed: entry exports (CSV, Excel, View downloads, WP-CLI) name the entry columns in the site language ("Entry ID", "Date submitted", "Status", "IP address") instead of id / submitted_at / status / ip_address, the Status column reads like the entry screen ("Completed", "Spam") instead of the stored key, and the date header names the site time zone, e.g. "Date submitted (Europe/Madrid)". Dates are in the time zone set under Settings > General (stored as UTC). A spreadsheet or script that looked for the old column names needs the new ones.
* Fixed: Visual Builder field settings show readable, translated choices instead of stored values (Product type, Show as, Fills in, Amount mode: "Dropdown of the answers", not "select"), and settings such as Minimum length, Maximum rows or Next button text have translated names instead of English ones made from the setting's key.
* Fixed: success and error messages, required stars and help text stay readable on dark themes (they fell to about 2:1 contrast, e.g. Twenty Twenty-One in dark mode).
* Fixed: "Create a page" on a site where pages open in the Classic Editor puts the form's shortcode on the page, so the page no longer looks empty in the editor.
* New: Form revisions. Every save of a form (block editor, Visual Builder, form settings, REST, AI assistants) keeps a copy; the last 20 are listed with who, when and what changed (fields added, removed or changed, title, settings). Restore one with a confirmation; the restore is kept as a revision too. "Revisions" on the forms list and a box on the form edit screen.
* New: QR code that opens a form. "QR code" on the forms list: pick a page the form is on (blocks, shortcode, Elementor, Beaver Builder), another page or any address, optionally tag scans with utm_source=qr, then download SVG or a 1024px PNG. Made on your own site, no outside service.
* New: Beaver Builder module "Trinity Forms" (works with the free Beaver Builder Lite): pick a form, see it in the editor.
* New: REST API: read, add, edit and delete an entry's notes (/submissions/{id}/notes; notes the plugin wrote stay read-only), and read a form's integration feeds with secrets hidden (GET /forms/{id}/feeds). Same permissions as the admin screens.
* Improved: REST API requests signed in with an application password no longer need a nonce.
* New: Visual Builder: a toolbar slot and a small bridge for extensions.
* Improved: accessibility and right-to-left pass on visitor forms. Choice groups (radios, checkboxes, ratings, name, address, survey fields) are a fieldset named by their question; help text is read out with every input; a failed submit marks each wrong answer for screen readers, ties the message to it and moves focus to the error summary or the first problem; a multi-step form no longer jumps to itself on page load. Darker default borders, placeholder, error, success and star colours for easier reading, visible keyboard focus on every button, and Arabic and Hebrew forms put the dropdown arrow, labels and side borders on the right side while emails, phone numbers and totals stay left to right. The Pro signature field can be signed by typing a name.
* New: campaign source capture. Turn on "Record where visitors came from" on a form's General tab and each entry keeps the utm_source, utm_medium, utm_campaign, utm_term and utm_content of the visit, the site that sent the visitor and the first page they saw. Shown on the entry, added to the CSV export and available in emails as {utm_source}, {utm_medium}, {utm_campaign}, {landing_page} and {first_referrer}. Kept in the visitor's browser only for the visit, with no cookie, and works on cached pages.
* New: choose and order the columns of an entry export, and export to Excel (.xlsx) as well as CSV. Entries > Export opens a small panel; filtered to one form, it lists that form's columns to tick and move up or down, and remembers your choice (also used by "Export selected", which gains "Export selected to Excel"). Excel cells are plain text, so leading zeros stay and nothing runs as a formula. CSV files keep the byte order mark Excel needs.
* New: an option to not store visitor IP addresses. Settings > Data turns it off for every form, or a form's General tab for that form; the default is unchanged. A Stored IP addresses card on Settings removes the addresses already kept (all forms or one). Spam lists, Akismet and rate limits still check the address while a form is sent; the per IP address entry limit needs stored addresses. The personal data export now lists the IP address (when kept), date and browser of each entry, and the CSV/Excel export can include an IP address column when you tick it.

= 2.22.0 =
* New (Pro): choose where AI requests go. Keep your own OpenAI or Anthropic key, or use WordPress's AI connection (Settings > Connectors, WordPress 7.0 or newer) so every plugin shares one key and more providers work. Sites with a key keep using it; the settings say what each choice means.
* Improved (Pro): Claude Haiku, Sonnet and Opus 5.5 are offered first for form generation and work with it (they refuse settings older models used).
* New: the AI assistants screen has a "Guide for your assistant" download, a short file that tells Claude or another AI app how to use the Trinity Forms abilities safely.

= 2.21.0 =
* New: an AI assistants screen (Trinity Forms > AI assistants). Connect Claude Desktop, Claude Code, Cursor or another MCP app and ask it about your forms: how they are built, how they perform, and what people sent. It shows ready-to-paste settings, installs the free MCP Adapter plugin in one click, and lists every request an assistant made.
* New: reading forms and entries is free. Contact details in entries are masked by default (also inside messages), files, passwords, card numbers, signatures and payment fields are never shown, forms you tick are kept from assistants, and each entry an assistant opens gets a note. It stays off until you switch it on.
* New (Pro): assistants can build forms. Create a form (saved as a draft), add, change, move or remove fields including conditional logic, publish it, import one, and mark entries read, starred or spam; moving entries to the trash has its own switch. Each change is shown first and saved only when the assistant asks again, and can be undone unless the form or entry was changed since. Forms are changed through the visual builder's own rules, so they open the same in the builder and the block editor.

= 2.20.0 =
* Maintenance release: the changes in this version are in Pro PDFs.

= 2.19.0 =
* New: ticking "Delete all Trinity Forms data" asks you to confirm and shows what deleting the plugin will remove.
* Changed: deleting the plugin frees this site's licence seat, so you can use it on another site straight away. Your data stays unless you ticked the delete box; if you install the plugin again, enter your key again.
* Fixed: with the delete box ticked, deleting the plugin also removes the saved licence key.
* Fixed: the licence box answers in your language (a wrong key, Install Pro, deactivating), an unlimited licence shows Unlimited instead of a site limit of 0, and a free key is no longer told it switches on Pro.
* Fixed: View details on the Plugins screen is there right after you activate the plugin, and its tabs keep WordPress's order.
* Fixed: the question shown when you deactivate the plugin closes with Esc.
* Fixed: WPForms notifications set to "Don't send" when a rule matches are imported that way. They used to be imported the other way round and sent to the wrong people.
* Fixed: a notification's rule shows in the block editor as it works ("Send only when" or "Don't send when"), and you can change it, flip it or remove it there. Before, a "don't send" rule from an import read as "Sent only when" and could not be changed.
* Fixed: file uploads in entries imported from Forminator, Fluent Forms, SureForms and the other form plugins show as links to the file. They used to show as blank, and entries imported earlier now show their links too.
* Fixed: saving a form in the block editor no longer removes merge tags written in angle brackets from a notification, like "Name <{your_email}>", and the mail shows the brackets around the address.
* Fixed: the 3-send limit on one copy of a cached page holds when a bot sends many copies at the same moment. Before, sends that arrived together could all get through.
* Fixed: Fluent Forms notifications that greet by first or last name ({inputs.names.first_name}) are imported with the name filled in, using the new {field:<id>:first} and {field:<id>:last} merge tags.
* Fixed: links in consent text (to a privacy policy or terms page) are kept when importing from Fluent Forms and Forminator.
* Fixed: imported entries store the choice the visitor picked the same way new entries do, so rules, merge tags and filters match old and new entries alike.
* Fixed: a required consent box shows its asterisk in the Visual Builder, and a consent text with a link shows as words, not HTML, in both editors.
* Fixed: with "Delete all Trinity Forms data" ticked, deleting the plugin also removes your forms, the widget settings, the licence state and every saved setting, as the box says. Before, only the entries went. Files visitors uploaded stay in their private folder on the server.
* Changed: the "Trinity Forms is ready" notice goes away once you open any Trinity Forms screen, not only the forms list.
* Changed: imported fields get readable ids (and merge tags) when the label is Arabic, Korean or another non-Latin script ({email} instead of {field_1}), and Formidable's generated keys like "xqo5y" give way to the label. A field is never given the id of a built-in merge tag such as {date}.
* Fixed: dates in entries imported from Ninja Forms are stored the way new entries store a date (2026-01-02), not as the text Ninja Forms showed.
* Changed: Contact Form 7's demo_mode and skip_mail lines are no longer listed as "not imported" after an import.
* Fixed: the field list in a rule no longer offers page breaks, sections and HTML blocks, which hold no answer.
* Fixed: an "is one of" rule matches a choice whose label has a comma in it, like "Group, 5+".
* Fixed: a form opened in the block editor stays there when you save and reload the page, instead of moving to the Visual Builder.
* New: Import forms takes several forms in one go. Tick the ones you want, and the result of each is listed after the import.

= 2.18.2 =
* Fixed: View details on a free install shows the full description, changelog and FAQ, with the video, instead of raw text.

= 2.18.1 =
* Changed: View details on the Plugins screen shows the plugin's banner, icons and screenshots, loaded from our server.
* Changed: one copy of a cached page can send the form 3 times. A bot replaying the page's HTML is then asked to send again, like a stale page. Visitors with JavaScript get their own token and are not limited.

= 2.18.0 =
* Fixed: the licence box shows its status, update channel and dates in your site language.
* Fixed: the Add Trinity form button works inside SiteOrigin's Editor widget, and the Trinity Forms widget no longer shows as missing in SiteOrigin Page Builder after a save.
* New: import forms from Contact Form 7, WPForms, Fluent Forms, Ninja Forms, Formidable, Forminator, Everest Forms, SureForms and MetForm, with their entries where the plugin keeps them. The forms list says what it found on your site, and anything that could not be brought over is listed after the import.
* New: a Trinity Forms widget in Elementor, a classic widget for sidebars and SiteOrigin Page Builder, and an "Add Trinity form" button in the classic editor.
* New: the submit button text and what happens after submit (message, page or address) can be set in the Visual Builder too.
* New: when a rule is about a choice field, its value is picked from that field's choices. Rules typed as the label now match.
* New: Arabic translation (review pending).
* New: after activation the Plugins screen says "Trinity Forms is ready. Start here", once.
* Changed: the "too fast" spam check uses a signed time token, so a bot posting a copy of a cached page is caught while cached pages keep working for visitors. A page older than 5 minutes asks the visitor to send again instead of filing the entry as spam.
* Changed: a blank From name sends as your site title instead of "WordPress".
* Fixed: a notification's sending rules were dropped when the form settings were saved, so a conditional email went out for every entry.
* Fixed: phone and number answers typed in Persian, Bengali, Thai or Arabic digits were saved empty or refused.
* Fixed: on a Spanish (or other translated) site, templates, new fields and the submit button started in English, and saving in the block editor turned "Enviar" into "Submit".
* Fixed: a required consent box sent unticked was accepted by the server.
* Fixed: entries search finds Persian ی/ک written as ي/ك, Turkish ı/İ in any case, words with or without the Persian ZWNJ, text pasted from a Mac, and phone numbers typed in local digits.
* Fixed: a Turkish "İstanbul" choice got a value with a hidden dot, so its rule never matched.
* Fixed: Persian notification emails read right to left; addresses, emails and phone numbers keep their order on right-to-left screens and in mail.
* Fixed: forms keep the block editor when the Classic Editor plugin is active.
* Fixed: saving a published form empties the page cache of common cache plugins, so visitors see the change.
* Fixed: the entry Activity log is in your site language.

= 2.17.0 =
* New: Hindi translation.
* Fixed: block names in the block editor now follow your WordPress language.
* Fixed: the licence box on the Settings screen is now translated.

= 2.16.2 =
* Maintenance release for the Pro version. Nothing changes in the free version.

= 2.16.1 =
* Maintenance release for the Pro version. Nothing changes in the free version.

= 2.16.0 =
* Fixed: CSV exports showed Korean, Japanese, Arabic and emoji as garbled text in Excel on Windows.
* Fixed: in Arabic, Hebrew and other right-to-left languages, every Trinity Forms admin screen scrolled sideways.
* Fixed: the Visual Builder was laid out left to right on right-to-left sites, and long field names in the palette were cut off.
* Fixed: "wp trinity entry export 12" and "wp trinity entry delete 12" ignored the 12 and acted on every form. They now stop and ask for --form=12.

= 2.15.0 =
* Changed: times are now stored in UTC and shown in the time zone set in Settings > General, so a time zone change or a daylight saving switch no longer shifts entry times. Existing entries are converted once, the first time an admin page opens after the update. Do not go back to an older version afterwards, as it would show the times shifted.
* Fixed: entries imported from Gravity Forms showed the wrong time.
* Fixed: on a multi-step form, a link to a spot on the same page jumped back to step 1, and after sending, Back walked through the empty steps.
* Fixed: with JavaScript off, a page from a cache, a file that was too big or too many submissions showed a page of code. The visitor now goes back to the form with the message, and each error names its field.
* For developers: entry times in the REST API keep site time and gain a submitted_at_gmt field. Entries::get() now returns UTC.

= 2.14.0 =
* Fixed: entry times were shifted by the site's time zone (a new entry on an India-time site said "in 5 hours"). Retention and trash clean-up now count days in site time too.
* Fixed: a page more than a day old (a cached page, or a tab left open overnight) refused every submit. It now gets a fresh security token and sends; if that fails it says the page is out of date and keeps the answers.
* Fixed: a phone autofill could fill the hidden spam trap and send a real visitor's entry to spam. The trap is harder to autofill, an entry where a person typed is kept, and the log shows what was in the trap.
* Fixed: a file bigger than the server allows said "This field is required." for fields the visitor filled. It now says the file is too big and gives the real limit. An empty file says so.
* Fixed: on right-to-left sites every page with a form was 10,000 px wide.
* Fixed: with JavaScript off, a form landed on a page of code after sending, a multi-step form could not be sent, and conditional fields never showed. All three work now.
* Fixed: email subjects and sender names showed "&amp;" and "&#039;". A notification's From name is used even when From email is blank.
* New: Settings, the test email and Tools > Site Health warn when mail goes out through PHP mail() (and about a strict DMARC policy on the From domain), and say "Form emails are failing" when they are.
* Fixed: on PHP-FPM and LiteSpeed hosts, notifications are sent after the visitor gets the reply, so a slow mail server no longer keeps them waiting.
* Fixed: during a spam wave, real visitors were told "Too many submissions from your connection". Spam now has its own limit.
* Fixed: a form setting saved at the top level of the form data (by code or an import) was ignored. It is used now, with a notice for developers.
* Fixed: the page script's messages can be translated. The phone Back button moves between steps of a multi-step form, and Back after a redirect no longer sends the entry again.
* Fixed: when a form is deleted, the page it was on shows a note to editors instead of nothing.

= 2.13.0 =
* New: Tools > Site Health checks that Trinity Forms's database tables exist. If the database refused one, it says which, with the database's own error, and Trinity Forms's screens show the same notice.

= 2.12.3 =
* Fixed: "Update now" could fail with "Download link expired" when clicked a while after WordPress last checked for updates. The plugin now fetches a fresh download link right before the update starts.

= 2.12.2 =
* Fixed: "Check again" on Dashboard > Updates now finds a new version straight away. Before, a release could stay hidden for up to 12 hours unless you used "Check for updates now" in the License card.

= 2.12.1 =
* Changed: the readme says plainly what the free zip's update check sends and where, with current prices, and how to install from the jnkplugins.com zip. No code changes.

= 2.12.0 =
* New: bought Pro? Paste your Pro license key in Settings, under License, and click "Install Pro now". Pro is installed over this version for you, the same way WordPress applies an update, and everything you set up stays. Downloading and uploading the Pro zip still works.
* Changed: the upgrade page points to that box first.

= 2.11.0 =
* New: the Dark style is free now, and a new "Theme colours" style takes the form colours from your block theme.
* Changed: the License card looks and works the same in every JnK plugin. In the free version it shows your free license, which keeps the plugin updated, and links to your account if you bought Pro.
* Changed: the upgrade page has a "Download the Pro zip" button and the three steps, and says what WordPress shows when you upload Pro over this version.
* Changed: "licence" is now spelled "license" everywhere.
* Pro: license handling fixes, see the Pro changelog.

= 2.10.3 =
* Fixed: on a dark theme, text in form fields could be invisible. The fields are white, but they took the theme's light text colour, so typed text, the dropdown and its options were white on white. Fields now always use dark text on their white background. The open dropdown list matches too.
* New: a `--trinity-forms-field-color` CSS variable sets the text colour inside fields, next to `--trinity-forms-field-bg`.
* Changed: the Name field no longer shows "First" and "Last" both inside the boxes and under them. The words show once, as labels. With sub-labels set to hidden, they still show inside the boxes.

= 2.10.2 =
* Pro: fixed an update notice that could show again after updating. No change in the free version.

= 2.10.1 =
* Fixed: a form brought in with Import, the Gravity Forms importer or the REST API opened as an empty form in the Visual Builder and the block editor, even though it worked on your site. Those forms now open with all their fields, and forms imported with an earlier version are fixed the first time you open them.
* Fixed: saving one of those empty-looking forms could replace the real form with an empty one. If a form ever can't be shown in the builder, it now says so and saving is turned off.
* Fixed: the form-wide "Help text position" setting had no effect on fields added in the builder or the block editor. Fields now follow it, unless you set a position on the field itself ("Use the form's setting" is the new default).

= 2.10.0 =
* New: Email check. A Send a test email button on Settings and on every notification shows whether form emails can leave your site, before a visitor finds out. WordPress can only confirm an email was handed to your mail server, and the wording says exactly that.
* New: when a form email cannot be sent, the entry now says why (for example "SMTP Error: Could not authenticate"), and your dashboard warns you about form emails that failed in the last 7 days.
* New: a "Your first ten minutes" checklist on the forms list: create a form, put it on a page, send a test entry, check the email arrived. It ticks itself from what has really happened, and you can hide it.
* New: Create a page with this form. It makes a draft page holding your form and opens it, from the forms list, the form settings or the Visual Builder.
* New: form styles. Pick Default, Rounded or Sharp and an accent colour on the Layout tab, with a live preview.
* Improved: an entry marked as spam now says why (the hidden trap, sent too fast, the bot check or Akismet).

= 2.9.0 =
* New: the File upload field is free. Visitors can send a CV, a photo or a document with any form. Files are kept in a private folder outside the public web, and only people who can manage forms can download them. You choose the allowed file types, a size limit, and whether more than one file is allowed.
* New: Spanish and Korean translations ship with the plugin. If your site language is Español or 한국어, the admin screens, the form builder and the editor blocks now show in your language. Nothing to set up.

= 2.8.1 =
* Fixed: the star icon on the Star button sat below its label on a single entry.

= 2.8.0 =
* New: a cleaner admin. Every screen has a clear header, the forms list shows live forms, entries and unread at a glance, and the entries list is compact and easy to scan.
* Improved: the form builder shows every field the same way, full width and the same height, on one clean page. Fields set to half or a third of the width now sit side by side in the builder, as they will on your site.
* Improved: forms look better out of the box. Every box has the same height and border, first and last name sit side by side, the address is laid out in rows, dropdowns are no longer grey, and the slider uses the full width.
* Improved: the Rating field shows stars instead of numbered buttons.
* Fixed: "Mark unread" on an entry did nothing.
* Fixed: the forms list showed "0% completion" for forms whose entries came in before views were counted.

= 2.7.0 =
* Fixed: a field whose type isn't recognised (for example from an add-on you switched off) is now saved as plain text instead of exactly as it was posted.

= 2.6.0 =
* New: the Visual Builder now shows the form's shortcode with a Copy button. Creating a form from a template used to drop you into the builder with no way to find out how to put it on a page.
* New: deactivating the plugin asks one optional question about what went wrong. Answering is optional, skipping deactivates straight away, and nothing from your site content is sent.

= 2.5.0 =
* Fixed: opening Add Form or Visual Builder left a new "Untitled form" draft behind every time. An unsaved form is now cleaned up by WordPress, and the first save keeps it.
* Fixed: with the block editor as the site default, the Visual Builder menu item said "Sorry, you are not allowed to access this page".
* New: the Visual Builder has a Draft / Published control. A draft form no longer shows on the site or accepts submissions; you can still preview it while logged in.
* Fixed: Image Choice, Multiple Choice and Option showed choices in the builder but the live field had nothing to pick. Settings on those fields no longer mention files, and Product only shows choices when it has them.
* Fixed: a form built only in the Visual Builder sent no email. It now gets the default admin notification like every other new form.
* Fixed: the Notifications button in the builder opened the block editor with the settings box folded away. It now opens the box on the Notifications tab.
* Fixed: confirmation conditions offered 7 operators; they now offer the same 15 as field logic. Saving a notification with no trigger no longer logs a PHP warning.
* Fixed: the merge-tag list offered payment tags that could never be filled in this build, and tags for page breaks, sections and HTML blocks.
* Fixed: a visitor's line breaks were lost in notification emails, and the page a form was submitted from was never recorded.
* Fixed: two forms on one page shared element ids, so labels in the second form pointed at the first. The Consent box now shows the required marker.
* Changed: the submit limit is a fixed 10 per minute per visitor (50 per form). It used to restart on every submission, which could lock out a whole office, and the message now says how long to wait.
* Fixed: All Forms counted spam in its Submissions column next to a funnel that did not. The column is now "Entries" and matches the Entries screen.
* Fixed: the entry activity log now records notes, edits, star, read, spam, trash and restore. Print headers show the date, not "(2 minutes ago)". Statuses read "Pending review" rather than "Pending_review".
* Fixed: exporting every form to CSV repeated column names like "Message"; repeated names now carry the form title.
* New: Import from Gravity Forms is always in the menu, explains what it needs, and a dry run says it changed nothing. Also linked from Import / Export.
* Fixed: Trinity field blocks were offered in the inserter on ordinary pages and posts. Only the form block is offered there now.
* Fixed: the Upgrade page said Pro adds multi-step forms. Multi-step is free.
* Fixed: every submission left a failed job in Tools > Scheduled Actions.
* Fixed: Name, checkbox, radio and rating fields pointed their label at nothing, which browsers flag and screen readers skip. The label now names the whole group. Payment statuses read "Pending" or "No payment" instead of raw values.
* Fixed: after saving a form in the block editor, the site quietly loaded the Visual Builder in the background.

= 2.4.2 =
* Fixed: with another JnK plugin active, the whole site could stop loading ("There has been a critical error on this website"), and WordPress blamed Trinity Forms. Several plugins bundle the same licensing client and PHP's code cache could load it twice. They are safe to run together now.
* Fixed: turning on Cloudflare Turnstile sent every real submission to Spam without telling anyone, because the widget was given the encrypted site key. The widget now works, a missing bot check tells the visitor to complete it, and Settings shows the real site key. The CAPTCHA script also no longer loads on forms that don't use it.
* Fixed: the Results page stopped with a critical error as soon as a Number, Rating or Range field had answers.
* Fixed: dropdown, radio and checkbox options could not be typed or pasted in the Visual Builder or the block editor ("Gold | gold" became "G | goldgold").
* Fixed: "Visual Builder" in the menu said "Sorry, you are not allowed to do that", and "Add Form" opened the block editor even when the builder is your default. Both open the builder now.
* Fixed: CSV and privacy exports showed stored values ("goldgold", "a", "1") instead of what the visitor picked; consent reads "Yes".
* Fixed: a submission caught as spam showed a raw {name}, and quiz tags kept their braces ("Quiz score {1}/{1}"). Line breaks you type in a notification are kept in the email.
* Fixed: the edit note listed fields that did not change, a fresh note said "(in 1 second)", entry dates ignored your site's date format, and the edit screen printed labels twice. The entry screen now has an activity log.
* New: export a form as JSON and import it on another site from Trinity Forms → Import / Export (previously WP-CLI only).
* Fixed: Settings shows "Settings saved.", the CAPTCHA option is named for the provider you chose, Pro-only notification triggers no longer appear in the free build, the menu is in a sensible order, the builder labels fields properly, and the plugin reports its real version.
* Changed: the Plugins-row Support and Docs links go to jnkplugins.com instead of the closed wordpress.org forum. The Upgrade page lists Mollie and no longer calls page breaks a Pro feature.

= 2.4.0 =
* Maintenance: block-built forms now resolve every hyphenated field block to its handler (only page breaks were mapped before). No other free-tier changes in this release.

= 2.3.0 =
* New: the entries list now has All / Unread / Starred / Spam / Trash tabs with live counts, so "is anything waiting for me?" is answerable at a glance.
* New: search your submissions. Type anything that was submitted and the list narrows to matching entries; filter by date range too.
* New: bulk actions — mark read or unread, star, resend notifications, mark as spam, move to trash, restore, or delete, on as many entries as you select at once. Large batches run in the background so the page never hangs.
* New: star an entry to keep it, and unread entries stand out until you open one.
* New: trash instead of delete. Trashed entries can be restored, and are permanently removed after 30 days (configurable, or never).
* New: private notes on an entry, with author and timestamp. Notes are for your team — they are never emailed or shown to the person who submitted.
* New: an activity log on each entry, plus the details you previously had no way to see — the page the form was submitted from, the referrer, the browser, and the logged-in user.
* New: a clean print view for a single entry, and Newer/Older buttons so you can work through a queue without going back to the list every time.
* New: "Resend notifications" on an entry, for when a notification bounced or a new address needs a copy.
* New: edit an entry. Fix a typo in an address or correct an answer, validated exactly the way the form validates a submission. Every edit is recorded as a note naming the fields that changed and who changed them.
* New: choose which answers appear as columns in the entries list, per form, so you can scan submissions without opening each one.
* New: print or export just the entries you select, not only the whole list.
* New: pre-fill fields from a link. Put `?plan=pro` on a URL and the form already knows, or fill in a logged-in visitor's own name and email without asking. Off per field by default, because a form whose every field can be set from a link is a form whose data you cannot trust.
* New: several confirmations per form, each with its own condition. Send a big-budget enquiry to a different page and everyone else to a thank-you message, from one form. Show a message, open a page on your site, or redirect anywhere, with a query string built from the answers.
* New: notifications can be sent to an email field on the form, so an autoresponder is a dropdown rather than a guess at the right merge tag. Plus Cc, Bcc, a From name, an on/off switch per notification, and a choice of when each one fires (on submission, when a payment completes, or when an entry is edited).
* New: import from Gravity Forms. Bring your forms across with their fields, conditional logic, notifications and entries — including the original submission dates. Gravity Forms does not even need to be active, nothing on its side is changed, and a dry run tells you exactly what would happen first. Any field with no equivalent here is named rather than silently dropped.
* New: export a form as JSON and import it somewhere else — build on staging, move to production, or keep a template in version control. API keys are stripped from the file, and entries never travel with a form.
* New: WP-CLI commands — `wp trinity form list|export|import|delete`, `wp trinity entry list|export|delete`, and `wp trinity import-gf`.
* New: a documented way to extend the plugin. Add-on authors get a base class that handles settings, queueing and error reporting, plus a full reference of all 77 hooks.
* New: seven more conditional-logic operators — starts with, ends with, is before, is after, is one of, is none of, and matches pattern. Dates now compare as dates rather than being refused as "not a number".
* New: nested conditions, so a rule can say "plan is Pro AND (seats over 10 OR priority is yes)" instead of needing several fields to fake it — with an "Add group" button in the rule editor to build them.
* New: many more merge tags — {form_title}, {entry_url}, {today:d/m/Y}, {user:first_name}, {post:post_title}, {payment_amount}, {field:id:label} and more, with a picker beside every field that takes them so you can drop one in at the cursor instead of remembering the name.
* New: schedule a form. Set when it opens and closes, with your own message for before and after.
* New: entry limits — cap a form in total, per logged-in user, or per IP address, each with its own message. Spam and trashed entries do not use up the allowance.
* New: require login to see and submit a form.
* New: ten more field types — Website, Password, Multi Select, Image Choice, and List (a simple "add another row" table). Plus a full set of pricing fields: Product, Quantity, Option, Shipping and Total, so you can build a real order form with a running total. Every amount is worked out on the server from your own settings, so nobody can edit the price in their browser. Taking the payment is still Pro.
* New: a Layout tab. Put labels above the field, to the left or right, or hide them; move help text and errors; choose how required fields are marked (an asterisk, the word, or mark the optional ones instead — which reads better on a form where nearly everything is required); position the sub-labels on name and address fields; and add your own CSS class to the form.
* New: an optional summary of every problem at the top of the form when a submit fails, each one a link that jumps to the field. Worth turning on for a long form, where the inline errors can all be below the fold and a failed submit looks like nothing happened.
* New: the submit button can be an image, and can be hidden until a condition is met — a consent box that has to be ticked before the form can be sent, for example.
* New: name fields now have real "First" and "Last" labels rather than placeholders alone. A placeholder is not an accessible name, and it disappears the moment somebody starts typing.
* New: a notification can attach the files uploaded with the submission, and can be duplicated — the usual reason being "same email, one different recipient".
* Improved: a submission caught by the spam filter is now kept under Entries → Spam instead of being refused outright, so a false positive can be recovered. Nothing is emailed and no integration runs for it. The visitor sees the normal confirmation by default; you can set different wording if you would rather be explicit.
* New: per-field settings — default value, admin label (a short name for entries and exports when the question is a whole sentence), field width for side-by-side layouts, custom CSS class, help text above or below, visibility (everyone / logged-in / admins), a custom error message, an autocomplete hint, and "no duplicates" to refuse a value another entry already used.
* Improved: CSV export now honours the filters and search on screen, instead of always exporting everything.
* Improved: exporting a large number of entries is dramatically faster — the export used to run two database queries per entry.

= 2.2.4 =
* Fixed: the Results report charted the stored value of each answer rather than the option people actually chose, so a poll read "premium_finance_software" where the form said "Premium finance software". Notification emails were fixed in 2.2.3; the report now matches them.
* Improved: the notification fields no longer suggest typing "{field_id}", which is not a tag — they point at a real one. Your form's own tags are listed above the notification, click to copy.

= 2.2.3 =
* New: the admin menu is now "Trinity Forms → All Forms", matching how the docs and support replies describe it instead of a generic "Forms".
* New: the entries list shows who each submission came from, with the address as a mailto link, and a single entry has a "Reply by email" button. Reading a submission and answering it is the same job.
* New: notifications have their own Reply-To setting, filled in automatically for a new form. Getting Reply to reach the sender previously meant sending the notification as them, which fails SPF checks and lands it in spam.
* Fixed: new fields get a readable id from their label — "Email" becomes {email} — instead of text_a1b2c3, so the merge tag is something you can guess. Existing fields keep their ids.
* Fixed: entry values no longer read like a database dump — a name shows as "Ravi Kumar" rather than {"first":"Ravi","last":"Kumar"}, and choice answers show the option's label.
* Improved: the forms list points at the two ways to make a form while you have none, rather than showing WordPress' bare "No posts found."
* Improved: the Payment column is hidden unless payments are actually in use; on a free install it could only ever read "none".
* Fixed: a form created from a template emailed nobody. The default admin notification was only filled in by the block editor, and Templates → "Use template" drops you straight into the visual builder — the most common way to make a form was the one way that skipped it. Every newly created form now gets it, however it was created.
* Fixed: notification emails printed the stored value of a dropdown, radio or checkbox answer instead of the option the visitor actually picked — "premium_finance_software" where the form said "Premium finance software".

= 2.2.2 =
* Fixed: the Notifications panel now lists the merge tags this form actually has — each field's real tag, next to its label, click to copy. It previously said to use "{field_id}", which reads like a tag you can type; field ids are generated (e.g. text_a1b2c3) and were only visible one block at a time in the editor sidebar, so a notification full of tags that never got replaced was the usual result.

= 2.2.1 =
* Fixed: notifications and other per-form settings saved in the block editor were silently discarded. Any form set to open in the visual builder — which is every form created since 2.1 — bounced the block editor's save to the builder before it ran, so the settings looked saved and were not. This is why submissions were being stored while no email ever arrived.
* New: a new form now starts with an admin notification already filled in, so a form emails someone from the moment it is published.
* New: a Notifications button in the visual builder, which had no way to reach them at all.
* Fixed: checkbox and radio options inherited their spacing from the theme, which on some themes meant double-spaced options that no amount of editor styling would change. Both now carry sensible spacing of their own, and consent text is no longer bold.
* Fixed: the plugin reported its version as 2.1.0 on Site Health and support screens no matter which version was installed.

= 2.2.0 =
* New: a short getting-started card on the Entries screen while you have not built a form yet, so a fresh install points somewhere instead of showing an empty table and empty filters.
* Improved: "No entries yet." now tells you what to do next rather than ending the road there.
* Improved: the form editor carries one plain line describing what Pro adds, where you are already choosing fields. No locked controls, no banners, nothing disabled — the free build still shows no teaser UI.

= 2.1.0 =
* Changed: new forms now open in the drag-and-drop Visual Builder instead of the block editor. Forms you built before upgrading are left exactly where they were and keep opening in the block editor — upgrading never moves your existing work. Change any form individually, or set the site default under Forms → Settings → "Open forms in".
* Fixed: choosing "Block editor" as the site default used to hide the very setting you needed to switch back.
* Improved: the entries list colour-codes the Status and Payment columns, so a refund or an unpaid registration stands out instead of reading as one more lowercase word. The single-entry view matches.
* Improved: the Visual Builder's field settings panel now has proper spacing — labels sit above their inputs, help text belongs to the field it describes, and the Required toggle reads as its own setting rather than being crammed against the field above it.

= 2.0.1 =
* Fixed: the "Allow save & continue later" option no longer appears in the form editor on the free plan — it required Trinity Forms Pro and had no effect without it.

= 2.0.0 =
* New: Visual Builder — an opt-in drag-and-drop form canvas. Turn it on under Forms → Settings → "Open forms in" → Visual builder. Drag field types from the palette to place them exactly where you want, reorder by dragging, undo/redo, edit the form title inline, and start from any of the 12 built-in templates without leaving the builder. The block editor stays the default and is always available; the builder reads and writes the same form, so you can switch either way.
* Fixed: entry-retention cleanup now removes each expired submission's stored files and field data (previously the daily cleanup could leave uploaded files and metadata behind).
* Fixed: a form field whose ID matched a reserved merge tag (for example {site_url}) can no longer override that tag in notification emails.
* New: a Feedback & Support page under the Forms menu to send the plugin author a message (with optional site info) straight from your dashboard.

= 1.12.0 =
* Fixed: major bug fix in conditional logic. Every field type now has a "Conditional logic" panel in the block editor — show or hide a field when other fields match rules you choose.
* New: format validation — text, textarea, email and phone fields accept a regular expression pattern with a custom error message.
* Fixed: form updates made over the REST API are now validated, so an incomplete request can no longer overwrite a form's fields.

= 1.11.0 =
* New: GDPR tools — Trinity Forms submissions are now included in WordPress' Export and Erase Personal Data requests (Tools → Export/Erase Personal Data), matched by the submitter's email.
* New: Form analytics — a views → submissions funnel with a completion rate, shown per form on the Forms list.
* New: Akismet spam protection — when Akismet is configured, submissions are checked against it automatically.
* New: Google reCAPTCHA v3 (invisible, score-based) joins Turnstile, reCAPTCHA v2 and hCaptcha as a spam-protection option.

= 1.10.0 =
* New: "My submissions" — add the [trinity_forms_my_submissions] shortcode to any page to show logged-in visitors a list of their own past submissions.
* New (Pro): create a WordPress post or custom-post-type entry automatically from each submission, and design a custom PDF template.

= 1.9.0 =
* Maintenance and compatibility. This release deepens Pro-only payments (recurring billing, coupon codes, and the Mollie gateway); the free tier is unchanged.

= 1.8.0 =
* Maintenance and compatibility. This release adds Pro-only native integrations and hardens credential encryption; the free tier is unchanged.

= 1.7.0 =
* Maintenance and compatibility. This release adds a Pro-only conversational (one-question-at-a-time) render mode; the free tier is unchanged.

= 1.6.0 =
* Maintenance and compatibility. This release adds a Pro-only AI form assistant (bring your own API key); the free tier is unchanged.

= 1.5.3 =
* Improved: consistent author branding and new Settings, Upgrade, Docs and Support links on the Plugins screen. No functional changes.

= 1.5.2 =
* Maintenance and compatibility. The free tier is unchanged.

= 1.5.1 =
* Fixed: front-end form submission could fail — a leftover identifier from an earlier plugin rename made the front-end script error, so forms fell back to a non-AJAX POST that was rejected. Submissions now work reliably.
* Fixed: composite fields (Name, Address) did not submit their values because the client did not serialize their sub-fields. Now fixed.

= 1.5.0 =
* Maintenance and compatibility. This release adds Pro-only PDF export and a filterable front-end submissions view; the free tier is unchanged.

= 1.4.0 =
* Maintenance and compatibility. This release adds Pro-only integrations and spreadsheet export; the free tier is unchanged.

= 1.3.0 =
* Maintenance and compatibility. This release adds a Pro-only calculation field; the free tier is unchanged.

= 1.2.0 =
* New: Results report — Trinity Forms → Results aggregates any form's choice (radio/select/checkbox) and rating/range/number responses into per-option counts, percentages and averages, turning any form into a survey or poll with instant analytics.
* New: Quiz scoring — set a correct answer + points on radio/select fields and each submission is scored automatically, with {quiz_score} and {quiz_max} merge tags for confirmations and notifications.

= 1.1.1 =
* New: Google reCAPTCHA v2 and hCaptcha are now supported for spam protection alongside Cloudflare Turnstile — choose your provider and enter its keys under Trinity Forms → Settings, then enable the challenge per form.

= 1.1.0 =
* New: five field types — Range slider, Name, Phone (with optional international validation), Address, and Country (full ISO list) — all free.
* New: a starter template library — 12 ready-made forms (contact, quote, event, appointment, RSVP, newsletter, support, feedback, job application, order, volunteer, testimonial) under Trinity Forms → Templates. Pick one to create an instantly-editable form.

= 1.0.8 =
* Improved WordPress.org listing discoverability (expanded tags, clearer contact form / form builder description). No functional changes.

= 1.0.7 =
* Fixed: the "Upgrade to Pro" and "Feedback/Docs" admin screens linked to a
  retired domain and would 404. Both now point at the current site.

= 1.0.5 =
* Hardened for the WordPress.org review: enqueued the form-editor CSS/JS, tightened input sanitization and file-upload handling, restricted management endpoints to administrators, and documented the block build.

= 1.0.4 =
* Changed: minimum supported PHP is now 8.2 (8.1 reached end-of-life).
* Fixed: entries CSV export downloaded an unusable, double-encoded file — it now exports a proper CSV.
* Hardened: output escaping, internationalization comments and file-handling for the WordPress.org plugin review.

= 1.0.0 =
* Initial release.
* Feedback & Support form (sends to the author via your site email).

== Upgrade Notice ==

= 2.10.1 =
Important if you used Import or the Gravity Forms importer: those forms looked empty in the builder, and saving them there could wipe them. Update before editing them.

= 2.2.1 =
Important fix: per-form settings saved in the block editor — including notifications — were being discarded. If you configured a notification and never received email, set it once more after updating and it will stick.

= 2.0.0 =
Adds the new opt-in drag-and-drop Visual Builder and fixes entry-retention cleanup. Safe in-place update — your forms and entries carry over untouched.

= 1.0.7 =
Fixes broken Upgrade/Feedback links in the admin; safe in-place update — your forms and entries are preserved.

= 1.0.4 =
Hardening and a CSV-export fix; safe in-place update — your forms and entries are preserved.
