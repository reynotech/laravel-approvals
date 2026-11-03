# Contributing

Everything you need to test a change goes through `bin/bench`. Run `bin/bench help` for the full list.

## Quick start

```bash
composer install
bin/bench test
```

That runs the regular suite on SQLite in memory, with nothing else to set up. It's fast enough to run on every change.

## Before opening a pull request

```bash
bin/bench test all
```

That adds MySQL and Postgres, which need Docker. The containers start by themselves on ports 33071 and 33072 and keep their data in memory. Stop them with `bin/bench db down`.

If your change touches how diffs are built, how votes are decided or how creations are held, also run:

```bash
bin/bench stress
```

## The commands

| Command | What it answers | Needs | Time |
|---|---|---|---|
| `bin/bench test` | Does the suite pass? | Nothing | Seconds |
| `bin/bench test mysql` or `pgsql` | Does it pass on a real server, with typed columns and strict rules? | Docker | About 2 min each |
| `bin/bench matrix` | Does approving store what a direct save would, for every cast and transition? | Nothing | Seconds |
| `bin/bench fuzz` | Does any random sequence of saves, votes and deletes break the expected state? | Nothing | Under a minute |
| `bin/bench concurrency` | Do votes at the same instant keep the quota and the status right? | Docker | About 1 min |
| `bin/bench stress [mysql\|pgsql]` | All three above on one server. Default: MySQL. | Docker | About 7 min |
| `bin/bench compat` | Does it pass on Laravel 11, 12 and 13? | Network | A few minutes |
| `bin/bench mutation` | Would the tests notice if this code broke? | Nothing | A few minutes |
| `bin/bench api-docs` | Rebuilds the class reference in `docs/api` from the docblocks. Commit the result. Add `-v` to list docblock problems. | Network the first time | Seconds |
| `bin/bench all` | Everything but mutation, on every engine, with a summary. Add `--with-mutation` to include it. | Docker, network | About 20 min |

The same commands are available as `composer bench <command>`, with `composer test`, `composer test:mysql`, `composer test:pgsql` and `composer test:stress` as shortcuts.

Arguments after the command reach PHPUnit:

```bash
bin/bench test mysql --filter=ApprovalWorkflowTest
```

## Where tests go

- `tests/Feature` and `tests/Unit`: the regular suite. Runs on every engine, so don't depend on key order in a value read back from a `json` column: use `assertDiffSame()`.
- `tests/Feature/ServerColumnTypesTest.php`: what only a real server does, such as typed columns and refusing bad values. Skipped on SQLite.
- `tests/Stress`: the slow suites, kept out of `bin/bench test`.

## When something fails

- **Fuzz** prints the seed and the last operations. Sequences are deterministic, so that seed replays the failure. Lower `FUZZ_SEEDS` to get there sooner (`FUZZ_SEEDS=20 bin/bench fuzz`).
- **Matrix** names the cast, the transition and the rule broken, such as "aprobar != guardar directo".
- **Concurrency** runs separate processes, so a failure can depend on timing. Raise `CONCURRENCY_ROUNDS` to reproduce it more often.
- **Mutation** lists what survived. Add a test if a survivor shows behaviour nobody checks; some can't be caught, such as a change that behaves the same.

## Adding a cast or a diff behaviour

The promise to keep is that approving stores exactly what saving directly would have. `tests/Feature/EloquentCastCoverageTest.php` checks that for every cast Eloquent ships: add new ones to its lists.

Update `CHANGELOG.md` and `docs/changelog.md` under `### Unreleased`, and `docs/upgrading.md` if existing apps have to change something.
