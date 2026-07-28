## Changelog

All notable changes to this project are documented here. This project follows [Semantic Versioning](https://semver.org/).

### 0.2.0 - 2026-07-28
- Documented the `ori`/`mod` diff structure stored in `Modification::$modifications`.
- Made the `ori`/`mod` diff keys configurable via `config('approvals.diff_keys.*')`.

### 0.1.0 - 2026-07-28

### 0.1.0
- Initial extraction from `rex-server-next`: `RequiresApprovalWhenChanges` / `ApprovesChanges` concerns, `Modification`/`Approval`/`Disapproval` models, config-based model resolution, optional `HasModificationMedia` trait, and a Capsule-based PHPUnit test suite.
