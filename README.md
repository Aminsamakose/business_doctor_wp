# Business Doctor by Samakose (WordPress plugin)

Free Business Health Assessments for WordPress: **SME360**, **AgriFood360** and **ESO360**, each with 25 questions across 6 dimensions. Visitors get a scored result, a printable report and an optional email copy. Staff work the results as leads, edit the question banks, and see which business areas score weakest. Everything runs inside WordPress, with no external service.

This is **phase 1** of moving Samakose AI into WordPress. It ports the free assessment from the Next.js platform (`samakose-ai`), with the same approved questions and the same scoring. Later phases are listed under [Roadmap](#roadmap).

## Install

1. Download this repository as a ZIP (Code > Download ZIP), or zip the folder yourself.
2. In WordPress, go to **Plugins > Add New > Upload Plugin**, choose the ZIP and click **Install Now**, then **Activate**.
3. Go to **Business Doctor > Settings** and set:
   - the page the "Explore the full Business Health Assessment" button links to;
   - your privacy notice page (if it isn't the site's privacy policy page);
   - who receives new-lead emails.
4. Create a page, add a **Shortcode** block and paste one of these:

| Shortcode | Shows |
|---|---|
| `[business_doctor_assessment]` | A choice of SME360, AgriFood360 or ESO360 |
| `[business_doctor_assessment platform="SME360"]` | SME360 only |
| `[business_doctor_assessment platform="AGRIFOOD360"]` | AgriFood360 only |
| `[business_doctor_assessment platform="ESO360"]` | ESO360 only |

Requirements: WordPress 6.2 or later, PHP 7.4 or later, MySQL 5.7+ or MariaDB 10.3+. For email delivery, use the site's normal mail setup; an SMTP plugin is recommended on shared hosting.

## What it does

**For visitors**
- Enter name and email, then acknowledge the privacy notice.
- Answer 25 questions, one at a time, with a progress bar. It works on phones.
- See the overall score, band, six dimension scores, strengths and priority areas.
- Download a branded PDF of the result, open a printable A4 report, or email a copy to themselves (the email includes the PDF as an attachment).
- One free assessment per email address per platform, as in Samakose AI.

**For staff (Business Doctor menu)**
- **Dashboard:** totals, last 30 days, average score, results by band and by platform, and the weakest dimensions across all respondents.
- **Results and Leads:** search, filter by platform, band or status, and sort. Bulk-mark as New, Contacted, Converted, Closed or Spam. Add follow-up notes and export to CSV (Excel-safe).
- **Question Banks:** view every version. Copy the live version into a draft, edit questions and answers, then publish. Earlier results keep the version they were taken under.
- **Settings:** score band thresholds (default 40, 60, 80), which assessments to offer, branding, notifications, rate limit, proxy trust, and whether to delete data on uninstall.

## How scoring works

A = 4, B = 3, C = 2, D = 1, and every question carries equal weight. A dimension score is its points over the maximum, as a percentage to one decimal place. The overall score is the same calculation over all 25 questions. The bands are:

| Score | Band |
|---|---|
| Below 40 | Critical Attention |
| 40 to below 60 | Needs Improvement |
| 60 to below 80 | Developing |
| 80 and above | Strong Foundation |

This matches `scoreFreeAssessment()` in Samakose AI exactly. `tests/run.php` replays 912 answer sets scored by the original TypeScript and checks that PHP gives identical results.

**One deliberate improvement.** In Samakose AI the browser calculated the score and sent only the result, so a visitor could submit any number. Here the answers go to the server, are scored there, and are then discarded. Individual answers are still never stored, which was the original privacy design, but a score can no longer be forged.

## Security and privacy

- Answers are never stored; only name, email, scores, the version taken and timestamps.
- IP addresses are stored only as a salted hash, for rate limiting.
- Public endpoints are rate-limited and the form has a honeypot against bots.
- Report links use a 128-bit random token. Reports are marked `noindex` and are not cached.
- Every admin action checks capabilities and nonces. All SQL uses prepared statements, and all output is escaped.
- CSV export neutralises spreadsheet formulas in visitor-supplied text.
- WordPress's own **Tools > Export / Erase Personal Data** covers assessment results. Suggested privacy-policy text is added under **Settings > Privacy**.
- Acknowledging the privacy notice is not treated as marketing consent.

Administrators get the `manage_business_doctor` capability. To let another staff member work leads without full admin rights, give their role that capability with any role-editor plugin.

## Public API

Namespace `business-doctor/v1` (for example `/wp-json/business-doctor/v1/platforms`):

| Method | Route | Purpose |
|---|---|---|
| GET | `/platforms` | Assessments on offer |
| POST | `/eligibility` | Whether an email may still take a platform's free assessment |
| GET | `/questions/{platform}` | The published questions |
| POST | `/complete` | Score answers and store the result |
| GET | `/results/{token}` | A result, by its private token |
| POST | `/results/{token}/email` | Email the result to the address it was taken with |
| GET | `/results/{token}/pdf` | Download the result as a branded PDF |

Developers can hook `bd_result_completed`, which receives the stored row, for example to push leads into a CRM.

## Testing

```
php tests/run.php
```

This runs scoring parity (912 cases from the original TypeScript), band boundaries, question-bank integrity and input rejection, and needs no WordPress install. The plugin was also tested end to end on WordPress 6.8.3 with MariaDB 10.11 and the Twenty Twenty-Five block theme. That run covered the visitor flow on desktop and phone widths, the result surviving a page reload, the email-once rule, the report page, rate limiting, lead follow-up, question-bank draft and publish, and CSV export nonce rejection.

## Roadmap

| Phase | Scope | Notes |
|---|---|---|
| **1** | Free assessments, scoring, results, leads, question banks, reports, settings | Complete |
| **2 (this release)** | Accounts for business owners (WordPress users with a Business Owner role), organisation profiles, the full paid assessments (SME360 v2, AgriFood360 v1 and ESO360 v1, 100 evidence-weighted questions each) with an evidence class, note and supporting file on every answer | Question banks pulled from the published Samakose AI database and the evidence-weighted scorer ported from `samakose-ai`; parity-tested at 12596/12596 checks and end-to-end tested on a real WordPress 6.8 + MariaDB 10.11 install |
| 3 | Payments with Paystack (Ghana cedis), plans and entitlements, invoices | Webhook and verify-on-return from day one |
| 4 | AI diagnosis and prescription drafts with expert review and approval, action plans, KPIs and re-assessment | Reuses the Claude prompts and the four-eyes approval rules |
| 5 | Programmes for donors and cohorts, expert network, reporting dashboards | Highest effort; decide scope after phases 2 to 4 are in use |

## Known limitations (Phase 2)

- The "Question Banks" admin screen can now view the full-assessment banks (dimension, weight, criticality, rating anchors, evidence requirement), but still cannot edit them: they are read-only there until a dedicated editor for the richer question shape is built. A correction today needs a change to the source data and a re-import.
- Routing a new lead to a specific staff member (as in Samakose AI's admin) is not ported; Phase 1's single notification-email setting covers both phases for now.
- No payments, plans or entitlements yet (Phase 3): a business owner can currently register and take the full assessment without being charged.

## Licence

GPL-2.0-or-later. Copyright Samakose Accelerator Lab.
