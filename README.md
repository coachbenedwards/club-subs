# Club Subs

Kids' and members' subs by Direct Debit, through the club's **own** GoCardless account, from the club's **own** WordPress site. Built for Eastwood FC first; written so any club can install it.

Money never touches the plugin. Parents set the Direct Debit up on GoCardless' hosted page; GoCardless collects and pays the club out at its own rate (1% + 20p, capped at £4, no monthly fee on the Standard plan). The plugin tells GoCardless what to collect and records what happened.

## What it does (v0.1)

- **Plans** — one per team or age group: amount, day of month, collections per season (10 or 12), season start month, optional one-off with the mandate (registration fee).
- **Sign-up block** `[club_subs_signup]` — parent picks the team, enters the child and themselves, goes to GoCardless, comes back. The mandate arrives by webhook and the monthly subscription is created for the collections left this season.
- **Members** — the child, the parent, the plan, status, GoCardless ids. Cancel at GoCardless from the member screen.
- **This month** — who's paid, due, failed or has nothing, per plan. CSV export. "Refresh from GoCardless" re-reads a month if a webhook was missed.
- **Chase list** (Subs → Chase list) — read straight from GoCardless, so it covers subscriptions Pitchero set up as well as ours: Direct Debit stopped, payment failed, nothing collected, paid, on its way. Emailed with a spreadsheet to whoever chases — weekly, monthly or on request.
- **Parent account** `[club_subs_account]` — emailed one-hour link; see each child's plan, status and next collection; cancel.
- **Import** — pulls every active subscription already in the GoCardless account (the ones Pitchero set up) so a club can leave the other system without parents signing up again. Child names aren't in GoCardless; imported members are titled after the payer for the club to rename.
- **Emails** — to the club on sign-up, failed payment or cancelled mandate; to the parent on sign-up. From the club's address, never a person's.
- **Log** — every GoCardless call and event, under Subs → Log. Tokens never logged.

## Set-up

1. Install, activate. Subs appears in the admin menu.
2. GoCardless → Developers → Create → **Access token** (read-write). Sandbox from `manage-sandbox.gocardless.com` first. Paste at **Subs → Settings**.
3. GoCardless → Developers → Create → **Webhook endpoint**, address shown on the settings screen (`/wp-json/club-subs/v1/webhook`). Paste the secret it gives you.
4. **Test the connection** on the settings screen — it names the account it reached.
5. Add plans (Subs → Plans). Tick *Open for sign-up*.
6. Put `[club_subs_signup]` on a page and `[club_subs_account]` on another; pick the account page in settings so emails can link to it.
7. Sandbox: sign up with a test name; GoCardless' sandbox page accepts any bank details and the mandate fulfils immediately. Check Members goes `pending → active`, then **This month** after the first payment event.
8. Flip to **Live** only when the live token and live webhook secret are both held.

## Blocks: every word is a field

Both shortcodes take attributes for every string they show, so wording is edited in the Elementor shortcode widget, not in code. Defaults are in `includes/signup.php` (`cs_signup_defaults`) and `includes/account.php` (`cs_account_defaults`). Example:

```
[club_subs_signup title="Join the Eastwood juniors" button="Set up my Direct Debit" consent="I'm the parent or guardian."]
```

`{amount}`, `{day}`, `{count}` fill in on the notes; `{club}`, `{name}`, `{link}` in the account email; `{child}` on the cancel button.

## How a sign-up moves

```
parent submits form
  → POST /club-subs/v1/signup
      member saved (pending)
      GC: POST /billing_requests   (mandate_request bacs, + payment_request if the plan has a one-off)
      GC: POST /billing_request_flows (redirect_uri = page?cs_return=done)
  → parent on GoCardless' page → back to the page: "All set."
GC webhook: billing_requests.fulfilled
  → read billing request → mandate id
  → GC: POST /subscriptions (amount, day_of_month, count = collections left this season)
  → member active, club emailed, parent emailed
GC webhook: payments.* → payment record → This month report
GC webhook: mandates.cancelled/failed → member cancelled/failed, club emailed
```

## Files

```
club-subs.php                 bootstrap
includes/gocardless-api.php   cs_gc() — the one place that talks to GoCardless
includes/post-types.php       cs_plan / cs_member / cs_payment, admin lists
includes/plans.php            plan fields, collections-left maths
includes/settings.php         Subs → Settings, Log
includes/signup.php           [club_subs_signup] + POST /signup
includes/webhook.php          POST /webhook, signature check, event routing
includes/subscriptions.php    mandate → subscription, cancel
includes/report.php           This month, CSV, refresh
includes/account.php          [club_subs_account] + magic link + cancel
includes/import.php           Subs → Import from GoCardless
includes/notify.php           emails
assets/subs.css, subs.js      front-end
```

## Not yet

- Sibling discount, registration questionnaire, consents/medical, kit sizes.
- Pause / change plan from the parent page (cancel and re-join for now).
- Partner-app OAuth ("Connect your GoCardless" button) — the licensing route. v0.1 uses a pasted access token per club, which works for any club today; OAuth needs GoCardless partner approval.
- Licence keys / update server for other clubs.
- GoCardless `Success+` automatic retries (an account-level setting, not code).

## Rules carried over from the Eastwood site

- Every block is Elementor-editable; every visible word is a field.
- No key in the repo. Tokens are write-only in options.
- Report changes by URL, not plumbing.
- Live mode takes real money. Say which mode the site is in before saying anything is ready.
