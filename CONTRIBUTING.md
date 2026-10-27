# Contributing

Thanks for your interest in Greywarden. The project is in **alpha**: bug reports, ideas and pull requests are
welcome. For anything bigger than a small fix, please open an issue first so we can agree on the approach. Security
problems go through [SECURITY.md](SECURITY.md), never a public issue.

Greywarden is licensed under the [Apache License 2.0](LICENSE). Unless you say otherwise, anything you submit is
licensed under the same terms (section 5 of the licence). Sign off your commits (`git commit -s`) to confirm you
have the right to submit the work under that licence ([Developer Certificate of Origin](https://developercertificate.org/)).

## Development setup

```bash
scripts/up.sh --seed        # builds and starts the development stack with demo data
make hooks                  # installs the git hooks in .githooks
```

The development stack bind-mounts `./src`, so edits are live. Never point it at real data: production runs as a
separate stack in its own checkout (README, Production).

## Tests

Every change ships with tests: unit tests for logic, functional tests for controllers, access rules and views, and
a smoke-test check (`scripts/smoke-test.sh`) for every new page or route. Before sending a change, run:

```bash
scripts/run-all-tests.sh --coverage
```

All suites must pass and line coverage must not go down. The script runs against a throwaway test database and
never touches the development data.

## Keeping private material out

`make hooks` installs three git hooks:

* **pre-commit** refuses `.env` files, database dumps, `storage/` and `backups/`, AI-assistant instruction files,
  secret values from your `.env`, and any term in your private denylist
* **commit-msg** refuses AI-attribution trailers
* **pre-push** runs the same checks on every commit being pushed, and requires valid PGP signatures on commits and
  tags pushed to the release repository

The denylist is a plain text file outside the repository (`~/.config/greywarden/denylist`, or
`$GREYWARDEN_DENYLIST`), one case-insensitive term per line: client names, internal hostnames, anything that must
never be published. `make leak-check` runs the check by hand.

## Commits

* One logical change per commit, with a short imperative subject ("Add …", "Fix …").
* Sign your commits (`git commit -S`); release commits and tags are always signed.
* Add a line to the `Unreleased` section of [CHANGELOG.md](CHANGELOG.md) for anything a user would notice.
