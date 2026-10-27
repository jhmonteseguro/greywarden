# Security policy

Greywarden holds an organisation's risks, incidents, device inventory and evidence files, so security reports are
welcome and are handled before other work.

## Supported versions

Greywarden is in **alpha**. Only the latest release receives fixes; upgrade with `scripts/deploy.sh <tag>`
(README, Production).

## Reporting a vulnerability

Report privately through GitHub: **Security › Report a vulnerability** on
[jhmonteseguro/greywarden](https://github.com/jhmonteseguro/greywarden/security/advisories/new).
Please do not open a public issue, pull request or discussion for a vulnerability.

A useful report includes:

* the release or commit you tested
* the steps to reproduce, and the role of the signed-in user (admin, analyst, viewer or none)
* what an attacker gains, and any configuration it depends on

Reports are handled on a best-effort basis while the project is in alpha. You will get an acknowledgement, a fix is
released as a new signed tag, and the advisory credits you unless you would rather stay anonymous.

Please test against your own installation with demo data (`scripts/up.sh --seed`), never against someone else's
instance.

## Verifying releases

Every release tag and release commit is signed with this PGP key:

```
8853 BCD0 E894 520E F954  AF18 07DF 7BE9 572F 7E0E
```

```bash
curl -s https://github.com/jhmonteseguro.gpg | gpg --import
gpg --fingerprint 07DF7BE9572F7E0E          # compare with the fingerprint above
git verify-tag v0.1.0-alpha
```

`scripts/deploy.sh` refuses to deploy a tag without a valid signature.

## Running it safely

* The web UI binds to `127.0.0.1` only. Publish it through a reverse proxy with TLS, and set `COOKIE_SECURE=1`.
* Production runs with `YII_DEBUG=0` and `YII_ENV=prod` (README, Production); the debug toolbar and Gii exist
  only in development.
* Change `ADMIN_PASSWORD` and the database passwords in `.env` before the first start. The admin must change the
  password again at first sign-in.
* Database dumps in `backups/` and the files in `storage/` contain everything in the registers. Keep them
  encrypted and off shared machines.
* Keep credentials that act on real systems (such as `CLOUDFLARE_API_TOKEN`) out of development and test
  environments.
