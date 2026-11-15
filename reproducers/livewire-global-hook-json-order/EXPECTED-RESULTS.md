# Expected assertions

These are the assertions the public test fixture is designed to make. They are not presented as a new test run from this environment.

| Scenario | Expected HTTP status | Expected database result |
|---|---:|---|
| Non-admin `#[Json]` call protected only by global hook | 403 | Admin-owned row changed by non-admin |
| Non-admin ordinary action protected by global hook | 403 | Row unchanged |
| Non-admin `#[Json]` with inline Gate check | 403 | Row unchanged |
| Non-admin `#[Json]` with `#[Authorize]` | 403 | Row unchanged |
| Administrator `#[Json]` call | 200 | Row changed by administrator |

The original end-to-end Laravel HTTP-kernel results are recorded separately in [the cross-user SQLite evidence](../../reports/evidence/livewire-cross-user-db-proof.txt).
