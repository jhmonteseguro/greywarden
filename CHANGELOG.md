# Changelog

Notable changes to Greywarden. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
versions follow [Semantic Versioning](https://semver.org/). Releases are signed tags (SECURITY.md, Verifying
releases).

## [Unreleased]

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

[Unreleased]: https://github.com/jhmonteseguro/greywarden/compare/v0.1.0-alpha...HEAD
[0.1.0-alpha]: https://github.com/jhmonteseguro/greywarden/releases/tag/v0.1.0-alpha
