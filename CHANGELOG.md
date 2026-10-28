# Changelog

Notable changes to Greywarden. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
versions follow [Semantic Versioning](https://semver.org/). Releases are signed tags (SECURITY.md, Verifying
releases).

## [Unreleased]

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

[Unreleased]: https://github.com/jhmonteseguro/greywarden/compare/v0.4.0-alpha...HEAD
[0.4.0-alpha]: https://github.com/jhmonteseguro/greywarden/compare/v0.3.0-alpha...v0.4.0-alpha
[0.3.0-alpha]: https://github.com/jhmonteseguro/greywarden/compare/v0.2.0-alpha...v0.3.0-alpha
[0.2.0-alpha]: https://github.com/jhmonteseguro/greywarden/compare/v0.1.0-alpha...v0.2.0-alpha
[0.1.0-alpha]: https://github.com/jhmonteseguro/greywarden/releases/tag/v0.1.0-alpha
