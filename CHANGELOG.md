# Changelog

Notable changes to Greywarden. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
versions follow [Semantic Versioning](https://semver.org/). Releases are signed tags (SECURITY.md, Verifying
releases).

## [Unreleased]

## [0.10.0-alpha] - 2026-10-29

### Added

- Evidence files on controls: the control's create / edit form can attach files under its Evidence field. They are
  checked like any other upload (a refused file keeps the whole form from saving), listed under Evidence on the
  Details tab and in the control PDF, and also appear on the Files tab marked *Evidence of the control*.

### Fixed

- Demo reset (`php yii seed/demo --reset=1`): it now also removes the notes and attached files of the wiped records,
  including the stored files. Before, they were left behind and, once ids restarted at 1, showed up on the new demo
  records.

## [0.9.0-alpha] - 2026-10-29

### Added

- Files on incident timeline entries: an entry can link a file already on the incident or attach a new one in the
  same step (it also appears on the incident's **Files** tab). The entry keeps the file's name if the file is later
  removed, and the incident PDF names the file under the entry. A refused entry keeps its text for another try.
- Notes on risks, decisions, cloud accounts and cloud assets: the same **Notes** tab as controls (dated, signed,
  never edited, optionally linking a file), with *Note added* / *Note removed* in each record's History and a
  **Notes** section in the risk and decision PDFs. Incidents keep using their Timeline.
- Completed reviews on risks and decisions: a **Reviews** tab with **Record a review** (reviewed on, reviewed by,
  outcome, next review date). Recording one moves the record's review date on, so it no longer shows as overdue, and
  writes a single *Review recorded* entry in History. The review history shows the date each review was due and
  flags late ones, and the details panel shows **Last reviewed** with the date and reviewer. The risk and decision
  PDFs have a **Review history** section. A closed risk, or a decision no longer in force, can be given a final review
  with no next date. The reviewer, whoever recorded the review, or an administrator can remove a review: the review
  date stays as it is and History keeps the text. Migration `m261029_300001`. The demo data includes a few reviews.

### Changed

- Controls, decisions, incidents, cloud accounts and cloud assets now list the latest record first (reference
  descending) by default, as the Risk Register already did. Decisions were sorted by decision date and incidents by
  detection time. Column sorting is unchanged.

## [0.8.1-alpha] - 2026-10-29

### Fixed

- Scheduler container: it inherited the php-fpm image's `SIGQUIT` stop signal, so CLI `php yii schedule/run` dumped
  core (systemd-coredump reports) every time the stack was stopped, recreated or deployed. It now stops with
  `SIGTERM`. The test runner checks this for the development and production compose files.

## [0.8.0-alpha] - 2026-10-29

### Added

- Risk form: a collapsible **How to score likelihood and impact** guide above the inherent score. It explains each
  of the five likelihood levels (over the next 12 months) and impact levels (the realistic worst case), how
  inherent, residual and target scores differ, and the rating bands. Controls that only monitor or report, such as
  DMARC `p=none`, do not lower residual likelihood.

## [0.7.0-alpha] - 2026-10-29

### Added

- Notes on controls: a **Notes** tab logs short dated notes ("Emailed DevOps requesting xyz, copy saved in Files"),
  stamped with the time and author and never edited. A note can link a file already on the control or attach a new
  one in the same step, and keeps the file's name if the file is later removed. Notes appear in the control's
  History (*Note added* / *Note removed*) and in a **Notes** section of the control PDF. Authors and administrators
  can remove a note; archived controls are read-only.

### Fixed

- Dashboard **Overdue reviews** tile: it counts risks and decisions but always opened the risk list, which was empty
  when only decisions were overdue. It now opens the list that has overdue reviews. When both lists do, the
  **Risks** and **Decisions** rows each link to their own list.

## [0.6.2-alpha] - 2026-10-29

### Fixed

- Edge pulls: the security-events query sent the `requestSource` filter, which only request analytics accept, so
  Cloudflare rejected it ("unknown arg requestSource") and addresses it had already blocked or challenged could
  still open findings.
- A fresh development stack (and CI) could break its `vendor/` folder: the php and scheduler containers both ran
  `composer install` into the shared code at the same time. The install now takes a lock, and `scripts/up.sh` /
  `make wait` wait until it is released.

## [0.6.1-alpha] - 2026-10-29

### Added

- A `scheduler` container (same image as php, `php yii schedule/run`) runs Greywarden's scheduled jobs, so no host
  crontab is needed: Cloudflare edge pulls every `EDGE_PULL_INTERVAL` minutes (default 15, `0` = off), and expired
  blocks out of Cloudflare hourly when edge pulls are off. Jobs that need Cloudflare wait until it is configured.
  `make logs s=scheduler` shows what it did; `php yii schedule/status` lists the jobs.

## [0.6.0-alpha] - 2026-10-29

### Added

- Edge findings (Threat Intel › Edge Findings): pulls the zone's Cloudflare traffic analytics (`make edge-pull`, every
  15 minutes from cron, or *Pull now*) and opens one finding per address that probes for secrets and admin pages,
  enumerates paths, sends attack payloads, uses scanner tools or floods the site, scored with explained signals and
  checked against the intel sources. A sensitive path that answered 200 is flagged as a possible exposure. Each
  finding recommends actions: raise an incident (prefilled), block for a set number of days through the Cloudflare
  rule, dismiss or ignore. The page also suggests one WAF rule that blocks the probe paths for everyone and lists
  networks with repeat findings. Migration `m261029_000001`; the Cloudflare token also needs Zone › Analytics › Read.
- Admin › Settings › Edge monitoring: paths your sites serve, addresses never to flag, the score that opens a
  finding, the default block length and the flood threshold.
- `make edge-status` shows the zone's analytics limits and recent pulls; high-priority edge findings appear under
  Needs attention on the dashboard and in global search.

### Changed

- *Add to Cloudflare* now puts addresses and networks into a Cloudflare IP list (`greywarden_blocklist`, created on
  first use) instead of writing each one into the rule, so the rule stays short however many are blocked. A single
  WAF custom rule named **Greywarden Blocklist** blocks the list and every blocked ASN; Greywarden owns its expression
  and keeps its action on Block. When the rule is missing, Greywarden creates it disabled and tells you to move it to
  the right place in Cloudflare and enable it. The token also needs Account › Account Filter Lists › Edit and
  Zone › Zone › Read (or set `CLOUDFLARE_ACCOUNT_ID`); `CLOUDFLARE_RULE_NAME` is no longer used.
- Expired blocklist entries are now taken out of Cloudflare (`make expire-blocks`, also run by every edge pull)
  instead of staying in it until unblocked.

### Upgrading

- Run `make cloudflare-migrate DRY_RUN=1`, then `make cloudflare-migrate` once: it moves the entries already in
  Cloudflare into the list and the new rule, and takes Greywarden's conditions out of the old "Block Bad Actors"
  rule (`OLD_RULE=` for another name), keeping anything you wrote by hand.

## [0.5.0-alpha] - 2026-10-28

### Added

- Incident PDF report for management and non-technical staff: a PDF button on every incident produces an interim report
  while it is open and the final report once closed, with a plain-English summary, what the status and severity mean,
  what was affected, the regulator position, key dates and response times, actions, root cause and lessons, controls,
  the full timeline and a technical appendix. Exports are recorded in the incident's History.
- *Management summary* field on incidents (migration `m261028_200001`): a plain-English summary that opens the report;
  the description is used when it is blank. Incident search also matches it.
- Admin › Settings › Incident reports: reword what each incident status and severity means in the report.

## [0.4.2-alpha] - 2026-10-28

### Changed

- Cloud import: subnets and individual disk / database snapshots (AWS EC2 and RDS snapshots, Azure and Google Cloud
  disk snapshots) are no longer imported. Automated snapshots appear and expire daily and a cloud has dozens of
  subnets, so they buried the register. Backup vaults are still imported (Google Cloud Backup and DR vaults are new),
  and assets already imported are kept.

### Fixed

- AWS import from the Resource Explorer console CSV: resources without a Name tag were named "(not tagged)"; they are
  now named after their ARN.

## [0.4.1-alpha] - 2026-10-28

### Changed

- `make import-devices`, `make import-cloud` and `make sync-cloudflare`: `AS=` (`--user`) accepts the user's email as
  well as the username, like the sign-in form.

## [0.4.0-alpha] - 2026-10-28

### Added

- **Cloud Inventory**: a register of cloud accounts (AWS accounts, Azure subscriptions, Google Cloud projects and SaaS
  tenants such as Google Workspace, Microsoft 365, GitHub and Cloudflare; references `ACC-0001`...) and the cloud
  assets in them (`CLD-0001`...): instances, container clusters, serverless functions, VPCs, subnets, security groups,
  load balancers, gateways, buckets, volumes, backups, databases, queues, IAM roles and users, keys, secrets,
  certificates, DNS zones, domains, CDNs, WAF policies, repositories and SaaS integrations. Each asset records its
  region, resource ID (ARN), environment, owner, criticality, data classification, whether it is internet-facing,
  its public IPs and endpoints, encryption at rest and, for instances, the OS (unsupported images are flagged like
  devices). Filters for non-compliant, not assessed, internet-facing, not seen recently, OS end of support and
  archived; an account that still has assets cannot be deleted. Accounts and assets take Files (evidence) and have
  a full history.
- **Cloud checks** in the Control Register: a control can be checked on cloud assets of chosen resource types and / or
  on cloud accounts, recorded per record (pass / fail), from an instance's OS (*Supported OS*) or from the asset's
  *Encrypted at rest* answer. Assets and accounts get a posture (compliant / non-compliant / not assessed), the
  control page shows the results with links to the matching assets, and the Cyber Essentials page lists the cloud
  checks mapped to its requirements. Compliance stays rolled up through controls, as for devices.
- Risks and incidents can name **affected cloud assets** and decisions **related cloud assets** (also in the risk
  and decision PDFs); the asset page lists them, with the ATT&CK techniques observed on its incidents and its
  Enterprise ATT&CK platforms (IaaS, SaaS, Office Suite, Identity Provider, Containers, plus the guest OS).
- The dashboard has *Cloud assets* and *Cloud compliance* tiles (two rows of five on wide screens), a *Cloud posture*
  chart, and *Needs attention* groups for internet-facing cloud assets failing checks, non-compliant cloud assets and
  accounts, cloud instances on an unsupported OS and cloud assets not seen recently. Global search finds accounts
  and assets (reference, name, resource ID, account identifier, public IP or endpoint), and an IP investigation
  recognises your own cloud assets' public addresses.
- The demo data includes five cloud accounts, fifteen assets and three new cloud-check controls; the existing
  *Cloud storage guardrails* and *Supported operating systems* controls are also checked on buckets and instances.
- **Cloud imports**: `make import-cloud FILE=… PROVIDER=aws|azure|gcp AS=<user> [ACCOUNT=ACC-…] [DRY_RUN=1]` reads an
  AWS Resource Explorer or AWS Config export, an Azure Resource Graph export or a Google Cloud Asset Inventory export
  (the CLI's JSON or the console's CSV) and creates or refreshes the matching cloud assets. Only security-relevant
  resource types are imported (the rest are skipped and counted); missing accounts are created for review; assets are
  matched on the provider's resource ID, so later exports update them and *Not seen recently* starts working; fields
  edited in Greywarden are never overwritten. Greywarden stores no cloud credentials: the README lists the commands
  that produce each export.
- `make sync-cloudflare` adds and refreshes your Cloudflare zones as DNS zone assets, using the existing
  `CLOUDFLARE_API_TOKEN` (needs Zone › Zone › Read).

## [0.3.0-alpha] - 2026-10-28

### Added

- Unsupported operating system detection: devices are matched against a built-in catalogue of vendor end-of-support
  dates (Windows, Windows Server, macOS, iOS/iPadOS, Android, Ubuntu, Debian, RHEL and rebuilds, CentOS, Amazon
  Linux). The inventory and device page flag *Unsupported OS* (red) or *OS support ending* (amber, within
  `device.osWarningDays`, default 90), the device page shows the end-of-support date (Apple and Android dates are
  marked as estimated), and there is an *OS end of support* filter, an *Unsupported OS* count on the Active devices
  KPI and a *Needs attention* group. Add or correct dates in `device.osEndOfSupport`.
- *Supported OS* device-check method in the Control Register: the check fails once a device's OS is past its end of
  support, so the device is non-compliant, and is not assessed when the OS or version has no known date. A migration
  adds a *Supported operating systems* control (laptops, desktops, servers, VMs, phones and tablets; implemented,
  mapped to A.8.8, CE-SU-1, CE-SU-2, CC7.1, PR.PS-02, B4.d and ATT&CK M1051), so existing devices on an unsupported OS
  become non-compliant after upgrading.
- Last seen is graded: amber after `device.staleDays` (30) and red, flagged *missing*, after
  `device.staleCriticalDays` (90). The last-patched date is red when the device fails a patch-age check.

### Fixed

- `make import-devices` without `AS=` no longer fails with *User 'as' not found*: make's built-in `AS` (the
  assembler, `as`) was being passed as `--user`. The import now runs with no recorded user, as documented.

## [0.2.0-alpha] - 2026-10-28

### Added

- `SPAMHAUS_DQS_KEY`: with a free Spamhaus Data Query Service key, the DNS blocklist check queries ZEN via
  `zen.dq.spamhaus.net`, which works through public resolvers.
- SOC 2 compliance module: the 61 AICPA 2017 Trust Services Criteria (paraphrased), mappable from controls, with a
  readiness view under *Compliance → SOC 2*. Security is always in scope; Availability, Confidentiality, Processing
  integrity and Privacy are added in Admin › Settings.
- NIST CSF 2.0 compliance module: the 106 CSF 2.0 subcategories in NIST's wording, mappable from controls, with a
  current-profile view under *Compliance → NIST CSF 2.0*.
- NCSC Cyber Assessment Framework 4.0 compliance module: the 41 contributing outcomes (Open Government Licence v3.0),
  mappable from controls, with an assessment view under *Compliance → NCSC CAF*.
- *Admin › Settings › Compliance frameworks*: switch ISO 27001, Cyber Essentials, SOC 2, NIST CSF 2.0 and the NCSC CAF
  on or off. A framework that is off is hidden everywhere, but its applicability decisions and control mappings are
  kept. All are on by default.
- Incidents record which security properties were affected (confidentiality, integrity, availability). Any
  combination can be ticked; the incident log shows them as C / I / A and can be filtered by each.

### Fixed

- Without a DQS key, Spamhaus ZEN refusing a public resolver now shows as *unavailable* with a hint, not as an
  error. Unknown Spamhaus error codes (127.255.255.x) are reported as errors instead of being counted as listings.

### Removed

- The committed `.vscode/` editor settings. Editor settings are now ignored and the leak check refuses `.vscode/`,
  `.idea/` and `*.code-workspace`.

## [0.1.0-alpha] - 2026-10-27

First public release.

### Added

- Risk Register: 5×5 inherent and residual scoring, treatment, acceptance, target risk, heatmap, review and
  treatment deadlines, and a flag for risks that rely on a weak control.
- Control Register: owners, types, status, effectiveness tests and a test schedule; links to risks, decisions,
  incidents and ATT&CK mitigations; ISO 27001:2022 Statement of Applicability and Cyber Essentials readiness;
  device checks applied to the inventory.
- Decision Log with options, rationale and supersession; Incident Log with the NIST lifecycle, a timeline, the
  72-hour regulator deadline and response metrics.
- Device Inventory with lifecycle, network data, device-check posture and Google Workspace import.
- Dashboard with KPI tiles, heatmap, incident trend, device posture, control effectiveness and a "needs attention"
  list.
- MITRE ATT&CK Enterprise bundled for offline use: matrix, technique links, mitigation coverage and gap analysis.
- Threat intel: an explainable IP / ASN assessment from free sources and cached feeds, analyst verdicts, and a
  blocklist with firewall rules and an optional Cloudflare WAF update.
- Evidence attachments, PDF export of risks, controls and decisions, roles (admin / analyst / viewer), a per-field
  audit trail, archive and restore, and global search.
- Branding in Admin › Settings: display name, organisation, logo, sign-in text and the PDF marking.
- A production stack built from signed release tags (`scripts/deploy.sh`), separate from the development stack.
- Licensed under the Apache License 2.0.
- Repository safeguards: git hooks with a leak check and signed-push rules, and CI with all test suites, a secret
  scan and a production image build.

[Unreleased]: https://github.com/jhmonteseguro/greywarden/compare/v0.10.0-alpha...HEAD
[0.10.0-alpha]: https://github.com/jhmonteseguro/greywarden/compare/v0.9.0-alpha...v0.10.0-alpha
[0.9.0-alpha]: https://github.com/jhmonteseguro/greywarden/compare/v0.8.1-alpha...v0.9.0-alpha
[0.8.1-alpha]: https://github.com/jhmonteseguro/greywarden/compare/v0.8.0-alpha...v0.8.1-alpha
[0.8.0-alpha]: https://github.com/jhmonteseguro/greywarden/compare/v0.7.0-alpha...v0.8.0-alpha
[0.7.0-alpha]: https://github.com/jhmonteseguro/greywarden/compare/v0.6.2-alpha...v0.7.0-alpha
[0.6.2-alpha]: https://github.com/jhmonteseguro/greywarden/compare/v0.6.1-alpha...v0.6.2-alpha
[0.6.1-alpha]: https://github.com/jhmonteseguro/greywarden/compare/v0.6.0-alpha...v0.6.1-alpha
[0.6.0-alpha]: https://github.com/jhmonteseguro/greywarden/compare/v0.5.0-alpha...v0.6.0-alpha
[0.5.0-alpha]: https://github.com/jhmonteseguro/greywarden/compare/v0.4.2-alpha...v0.5.0-alpha
[0.4.2-alpha]: https://github.com/jhmonteseguro/greywarden/compare/v0.4.1-alpha...v0.4.2-alpha
[0.4.1-alpha]: https://github.com/jhmonteseguro/greywarden/compare/v0.4.0-alpha...v0.4.1-alpha
[0.4.0-alpha]: https://github.com/jhmonteseguro/greywarden/compare/v0.3.0-alpha...v0.4.0-alpha
[0.3.0-alpha]: https://github.com/jhmonteseguro/greywarden/compare/v0.2.0-alpha...v0.3.0-alpha
[0.2.0-alpha]: https://github.com/jhmonteseguro/greywarden/compare/v0.1.0-alpha...v0.2.0-alpha
[0.1.0-alpha]: https://github.com/jhmonteseguro/greywarden/releases/tag/v0.1.0-alpha
