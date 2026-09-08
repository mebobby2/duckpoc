# duckpoc

A standalone PHP 8.3 / Laravel app — same core stack as [Figured](https://github.com/figured/figured-webapp)
— set up to prove out a DuckDB + DuckLake + GCS architecture as a proof of
concept, ahead of a possible rewrite of Figured's reporting engine.

Phase 1 is done: Figured's **Cash Flow report runs as a single DuckDB query**
over DuckLake/Parquet in GCS, and its output matches the real Figured report
cell for cell (see the oracle section below). There's a browser viewer for it
on `http://localhost:8080`.

## Stack

| Piece | What | Why |
|---|---|---|
| PHP 8.3, Laravel 13 | Standard Laravel app | Figured itself is on Laravel 11; this scaffold took whatever `composer create-project` gave it, which is fine for a PoC but is a version ahead |
| MySQL 8.0 | Laravel's own app DB (`DB_CONNECTION`) | Matches how Figured uses MySQL — app/config data, not report data |
| DuckDB | Queried via [`satur.io/duckdb`](https://github.com/satur-io/duckdb-php) (FFI binding to the official C API) | No first-party PHP client exists; this is the most-adopted community one (148k+ installs) |
| DuckLake | A DuckDB extension, attached at runtime | Catalog/table format over Parquet in GCS |
| GCS | Data files (`gs://…`), authenticated via HMAC keys | See gotcha below — this is **not** service-account JSON auth |

## Quick start

```bash
docker compose build
docker compose up -d                 # mysql + the report viewer on :8080

docker compose run --rm app php artisan duckdb:cashflow:schema   # create tables
docker compose run --rm app php artisan duckdb:cashflow:seed     # seed the oracle scenario
docker compose run --rm app php artisan duckdb:cashflow:run      # run + parity-check
```

Then open **http://localhost:8080** for the browser viewer.

### The report viewer

`http://localhost:8080` renders the Cash Flow report with its parameters as
form inputs — farm, period from/to, actuals horizon, and basis. It also shows
the partition the farm resolved to (`farm_type=…` / `region=…`, i.e. which
Parquet files the query pruned to), the elapsed time, and the generated SQL in
a collapsible panel.

The elapsed time includes **the whole per-request DuckDB lifecycle** — a fresh
instance, extension load, DuckLake attach, then the query — because that is
the model this PoC is evaluating, not just query time in isolation. Typical
figure on the oracle scenario is ~400 ms end-to-end for the page, which is
almost entirely setup rather than the query itself.

Negative values render bracketed (`(1,200.00)`) and zero as `–`, matching
Figured's own `reportNumberFormat.js`. The oracle scenario has no negatives,
so that path is written-but-unexercised.

### Commands

| Command | What |
|---|---|
| `duckdb:test` | Smoke-tests the DuckDB → DuckLake → GCS chain end to end |
| `duckdb:cashflow:schema` | Creates/recreates the Cash Flow tables (destructive) |
| `duckdb:cashflow:seed` | Seeds the 4-line parity oracle scenario |
| `duckdb:cashflow:run` | Runs the report as DuckDB SQL and diffs it against the oracle |

With `GCS_BUCKET` left empty in `.env`, this runs entirely locally (SQLite
catalog under `storage/ducklake/`, Parquet data under
`storage/ducklake/data/`) — **zero cloud setup required** to confirm the
mechanics work.

To point it at real GCS, set in `.env`:

```
GCS_BUCKET=your-bucket-name
GCS_DATA_PATH_PREFIX=duckpoc/
GCS_KEY_ID=...
GCS_SECRET=...
```

**Important gotcha if you switch between local and GCS**: DuckLake ties a
catalog file to the data path it was first attached with, and refuses to
reattach it against a different path ("DATA_PATH parameter ... does not
match existing data path in the catalog"). If you've already run
`duckdb:test` locally and now want to point at GCS (or vice versa), delete
the stale catalog first: `rm -rf storage/ducklake/catalog.sqlite`.

The command does more than check a row count — DuckLake inlines small
inserts (default threshold: 10 rows) directly into the catalog database
rather than writing a Parquet file, so a naive `SELECT count(*)` can report
success without a single real file ever having been written to the
`DATA_PATH`. `duckdb:test` inserts 20 rows in one statement (clearing that
threshold), then calls `ducklake_list_files()` to find the real Parquet
file(s) DuckLake registered, and reads each one back directly with
`read_parquet('gs://...')` or the local path — bypassing the catalog
entirely — to prove actual file I/O against local disk or GCS happened, not
just a catalog-level row count. This has been verified end-to-end against a
real GCS bucket: two separate runs each produced their own real Parquet
file, correctly persisted across process invocations, with both files
independently readable back via `read_parquet()`.

## Real gotchas hit while building this (all fixed, documented here so they don't get rediscovered)

1. **`ext-ffi` isn't in the base `php:8.3-cli` image.** It has to be compiled
   from source against `libffi-dev` — see `docker/php/Dockerfile`. Just
   running `docker-php-ext-enable ffi` fails with "module does not exist".

2. **Composer package name ≠ GitHub repo name.** The library is
   `satur.io/duckdb` on Packagist, not `satur-io/duckdb-php` (that's just the
   GitHub repo). `satur.io/duckdb-auto` (the native-library auto-installer)
   is a separate package with a Composer plugin — needs
   `composer config allow-plugins.satur.io/duckdb-auto true` before it'll run.

3. **The auto-installer plugin can fail with a stale autoloader** ("Class
   `Saturio\DuckDB\CLib\Installer` not found") on a first-time
   install-both-packages-together run. Fix: `composer dump-autoload`, then
   manually run `Saturio\DuckDB\CLib\Installer::install()` once (see git
   history of this file / just re-run `duckdb:test`, which doesn't need
   this once the library is installed correctly).

4. **DuckDB's extension directory defaults to the process's home dir**
   (`/.duckdb`), which isn't writable for a non-root container user. Fixed
   by explicitly setting `extension_directory` before installing/loading
   anything — see `config/duckdb.php` → `DuckLakeConnectionFactory`.

5. **DuckLake does not create missing parent directories.** Attaching a
   SQLite catalog (or using a local, non-`gs://` `DATA_PATH`) whose
   directory doesn't exist yet fails with a raw IO error rather than
   creating it. `DuckLakeConnectionFactory::ensureCatalogAndDataDirectoriesExist()`
   handles this — found by actually running it, not by reading docs.

6. **GCS auth is HMAC keys via the S3-compatibility layer, not a
   service-account JSON.** Generate them under Cloud Storage → Settings →
   Interoperability in the GCP console (or `gcloud storage hmac create`).
   Some org policies disable HMAC key creation by default — if
   `GCS_KEY_ID`/`GCS_SECRET` auth fails outright, that's the first thing to
   check.

7. **A `SELECT count(*)` against the DuckLake table is not proof a Parquet
   file was written.** DuckLake inlines small inserts (≤10 rows by default)
   straight into the catalog database — the row count is genuinely correct,
   but it can be satisfied with zero files ever touching the `DATA_PATH`.
   Confirmed by hand: after running the original 1-row version of this test
   twice, the catalog correctly reported 2 rows, but neither
   `storage/ducklake/data/` (local) nor the GCS bucket had a single Parquet
   file in them — both were completely empty. `duckdb:test` now verifies the
   real thing (see above) instead of relying on catalog row count alone.

8. **DuckLake does not support `PRIMARY KEY`/`UNIQUE` constraints at all** —
   `CREATE TABLE ... account_id VARCHAR PRIMARY KEY` fails with "Not
   implemented Error: PRIMARY KEY/UNIQUE constraints are not supported in
   DuckLake". Consistent with other lakehouse table formats (Iceberg/Delta):
   uniqueness is not server-enforced. Seed/query code is responsible for not
   producing duplicate ids — see `CashFlowSchema.php`.

9. **Partitioning is set via `ALTER TABLE ... SET PARTITIONED BY (...)`
   after `CREATE TABLE`, not as a table-creation clause** — and it only
   applies to data written *after* that statement runs; existing rows are
   not retroactively repartitioned. `CashFlowSchema::recreate()` always
   creates the table and sets partitioning before any seed command has a
   chance to insert rows.

10. **Default Parquet row group size is 122,880 rows** — confirmed
    empirically (see below), not assumed. Matters because row-group-level
    min/max statistics (not partitioning) are what makes farm_id sort-order
    pruning work — see partitioning strategy below.

11. **FFI is auto-enabled for the `cli` SAPI only — a web SAPI needs
    `ffi.enable=1` explicitly.** Every artisan command worked while the
    browser 500'd with "FFI API is restricted by `ffi.enable` configuration
    directive", because `php artisan serve` runs the `cli-server` SAPI, which
    inherits the default `ffi.enable=preload`. Fixed in
    `docker/php/Dockerfile` with a `conf.d` drop-in. Fine for a local PoC;
    a real deployment would want `preload` plus an opcache preload script
    rather than FFI open to the web tier.

## Partitioning strategy: `(farm_type, region, year)`, not `farm_id`

Originally partitioned by `(farm_id, year)` — correct for DuckDB's
single-farm report queries, but gives BigQuery's cross-farm cohort queries
(which filter by `farm_type`/`region`, never an individual `farm_id`) zero
pruning benefit, and creates an operational tax of thousands of small
partition directories at real scale.

**Revised to `(farm_type, region, year(date))`.** `farm_type`/`region` are
denormalized directly onto every `transaction_lines` row (DuckLake can only
partition a table by its own columns, not a joined dimension table's) — the
`farms` table is what application code resolves `farm_id -> (farm_type,
region)` from, before building a query with explicit predicates on all
three so partition pruning engages.

This is a deliberate trade, not a free win:
- **BigQuery** gets real, direct partition pruning on exactly what its
  cohort queries filter by.
- **DuckDB** loses farm-level partition pruning — a single-farm query now
  scans its whole cohort partition (~20-30 farms), not just its own
  directory. Judged an acceptable trade given real per-farm data (Figured's
  largest farms run ~180K-800K total journal rows) — even a full cohort
  partition is only single-digit millions of rows, trivial for DuckDB to
  scan-then-filter.
- To recover some of that lost precision, seed/insert code is expected to
  **sort rows by `farm_id` within each insert** — DuckLake has no separate
  "cluster by"/sort-key concept distinct from `PARTITIONED BY`, so this has
  to be enforced by insert order, not declared.

**Verified empirically, not assumed:**
- Partition paths: inserted 4,000 rows across 2 farm_types × 2 regions (5
  farms each) and confirmed via `ducklake_list_files()` that GCS produced
  exactly 4 files — one per cohort
  (`farm_type=dairy/region=waikato/year=2024/...parquet`, etc.) — proving
  BigQuery-style cohort filtering would touch only the relevant file(s).
- Sort-order-based row-group pruning: a first attempt at 4,000 rows (1,000
  rows/cohort) produced only a *single* Parquet row group per file — far
  below the 122,880-row default threshold — so no row-group pruning could
  even be observed at that volume. Re-tested with 150,000 rows for one
  cohort (3 farms × 50,000 rows, inserted `ORDER BY farm_id`): this produced
  **2 row groups**, with `farm_id` min/max of `(farm-1, farm-3)` for the
  first (122,880 rows) and `(farm-3, farm-3)` for the second (27,120 rows) —
  i.e. a query for `farm-1` or `farm-2` alone could skip the second row
  group entirely. `farm-3` straddled both groups (its rows spanned the
  122,880-row boundary), so a query for it specifically would still touch
  both — real, working pruning, but not as clean as true partition-level
  isolation.

**Known, unsolved gap** (flagged honestly, not glossed over): this
sort-order benefit only holds as long as data is written in `farm_id`
order. Real incremental writes (new transactions arriving farm-by-farm, not
in bulk sorted batches) will not naturally stay `farm_id`-sorted over time —
a real system would need periodic compaction with an explicit re-sort to
maintain this. Not solved here; a known gap for Phase 5+.

**Not yet tested**: this was verified with synthetic data at a scale large
enough to force multiple row groups, but not yet at the real Phase 1 target
(one ~800K-row "hero" farm alongside several smaller farms across multiple
cohorts) — that's the actual stress test this design still needs.

## The Cash Flow parity oracle (Phase 1, Step 2)

Captured by running a minimal, hand-built scenario through Figured's real V2
`CashFlowStructureBuilder` + `ReportRunnerService`, and independently through
the widget path (`ReportWidgetDataGeneratorService`) that actually feeds
Reporting Studio's UI. Both agree.

**Scenario** — calendar-year farm (`season_year_end_month = 12`), two
accounts, four transactions, 12 monthly intervals, `ACTUALS_FORECAST` with
the horizon at 2024-02-28 (Jan/Feb actual, Mar-Dec forecast):

| Account | Basis | Type | Date | Amount as seeded |
|---|---|---|---|---|
| Revenue "DuckPoc Sales" | cash | actuals | 2024-01-15 | **−1000.00** |
| Expense "DuckPoc Wages" | cash | actuals | 2024-01-20 | +200.00 |
| Revenue "DuckPoc Sales" | cash | forecast | 2024-08-15 | **−500.00** |
| Expense "DuckPoc Wages" | cash | forecast | 2024-08-20 | +100.00 |

**Verified expected output** (dollars, per month Jan→Dec):

```
income:             [1000, 0,0,0,0,0,0,  500, 0,0,0,0]
operating_expenses: [ 200, 0,0,0,0,0,0,  100, 0,0,0,0]
gross_profit:       [1000, 0,0,0,0,0,0,  500, 0,0,0,0]
operating_surplus:  [ 800, 0,0,0,0,0,0,  400, 0,0,0,0]
net_cash_movement:  [ 800, 0,0,0,0,0,0,  400, 0,0,0,0]
opening:            [   0, 800,800,800,800,800,800, 800, 1200,1200,1200,1200]
closing:            [ 800, 800,800,800,800,800,800, 1200,1200,1200,1200,1200]
```

### THE sign rule — get this wrong and every number is wrong

**Revenue must be seeded NEGATIVE (credit); expenses POSITIVE (debit).**
This matches Xero's own journal convention ("positive value for a debit and
negative for a credit") — which makes sense, since Figured's actuals are
synced from Xero journals and inherit their signs.

Documented in Figured's own
`src/Figured/Packages/Development/RealisticFarms/REALISTIC_FARMS_README.md` §6:

> "Revenue must be stored as credits (negative). No pipeline inverts amounts
> when `absolute` is false — pass revenue negative for both actuals and
> forecasts, or income renders sign-flipped."

**This was learned the hard way in this PoC.** A first pass at this oracle
seeded revenue as *positive* `+1000`. Nothing inverted it, so it stored
positive, and the report's display layer (which flips revenue-class accounts —
`XeroAccount::isAccountInversedForUser()` returns true for `REVENUE` only)
rendered income as `−1000`. That cascaded: `operating_surplus` came out as
`−1000 − 200 = −1200` instead of `+800`, and closing balances read `−1200 /
−1800` instead of `800 / 1200`. Every layer of the engine agreed with itself,
because the engine was correct — the *input* was malformed. The bad numbers
looked plausible and survived three independent verification passes before
the README line above surfaced the actual cause.

Lesson for the DuckDB translation: `transaction_lines.amount` must carry the
same signed convention (revenue negative, expense positive). Seeding
"intuitive positive dollars" for revenue will produce a report that is
wrong by `2 ×` the revenue figure, in a way that still looks internally
consistent.

## Catalog backend choice

Defaults to **SQLite** (`DUCKLAKE_CATALOG_DRIVER=sqlite`), per DuckLake's own
documented recommendation for local, single-writer PoCs. Postgres and MySQL
are both wired up as alternatives (see `config/duckdb.php`), but **MySQL as
a DuckLake catalog has a currently-open bug**
([duckdb/ducklake#214](https://github.com/duckdb/ducklake/issues/214)) — use
it only if you're deliberately testing that specific combination, not as a
default choice. The Postgres and MySQL `ATTACH` connection-string formats in
`DuckLakeConnectionFactory` are built from DuckLake's documented pattern but
have **not** been executed against a real Postgres/MySQL catalog in this
environment — verify against
[the DuckLake docs](https://ducklake.select/docs/stable/duckdb/usage/connecting)
if either errors.

## What's verified vs. not

**Verified by actually running it, against a real GCS bucket:**
- FFI extension loads, native DuckDB library installs and runs a query (`SELECT version()`)
- `ducklake` and `httpfs` extensions install and load
- DuckLake catalog attach with a real `gs://` `DATA_PATH`, real HMAC-authenticated writes
- DuckLake's inline-vs-Parquet-file behavior (default 10-row threshold), confirmed empirically — not just from docs — including the failure mode where a naive row-count check would have reported false success
- Real Parquet files landing in GCS at the expected `<DATA_PATH>/<schema>/<table>/` layout, confirmed two independent ways: `ducklake_list_files()` metadata, and reading each file back directly with `read_parquet('gs://...')`, bypassing the catalog entirely
- Catalog + data persistence across separate `docker compose run` invocations — a second run produced its own separate, independently-readable Parquet file, proving durable transactional write behavior across process restarts, not just in-memory
- MySQL connectivity and `php artisan migrate`
- **Cash Flow parity**: the DuckDB query's output matches Figured's real report on all 84 cells (7 rows × 12 months) — `duckdb:cashflow:run` asserts this on every run, so a regression fails loudly rather than silently
- The report rendering in a browser end to end, over real GCS-backed DuckLake, in ~400 ms per request including the full DuckDB setup/attach cycle

**Not yet verified:**
- Postgres or MySQL as the DuckLake catalog backend (SQLite remains the default per DuckLake's own PoC guidance)
- Anything at actual data volume — this proves correctness, not performance at scale (though real per-farm row counts gathered separately — Figured's largest farms run ~180K–800K total journal rows — suggest single-farm query volume is not a meaningful performance risk for DuckDB regardless)
- The `non_operating_income`, `non_operating_expenses`, `non_operating_movements`, `equity_movements` and `gst` sections. These are implemented in `CashFlowQuery` from the structure definition, but the oracle scenario has no accounts in them, so their sign handling — the last three are `setInverse(true)` sections — is written-but-unexercised. Deliberately left that way: the PoC's open question is DuckDB's performance and the multi-dimensional VJ problem, not exhaustive section coverage. GST is the one most likely to matter on real data.
- Negative-value display (bracketed) in the viewer — no negatives in the oracle scenario

## Next steps

Phased per the architecture conversation this PoC came out of. **Phase 0
(scaffold) and Phase 1 (Cash Flow) are done**; the rest is not started:

1. ~~**Phase 1 — Cash Flow.**~~ **Done.** Schema + partitioning, the oracle
   captured from Figured's real engine, the report as one DuckDB query, and a
   parity check that passes on all 84 cells. Plus a browser viewer.
   Outstanding within this phase: the 800K-row scale test (one "hero" farm at
   realistic max volume across multiple cohorts), which is what actually
   exercises the partitioning and row-group-pruning design.
2. **Phase 2 — Livestock valuation.** Research the actual virtual-journal
   handler (methodology, data dependencies, existing tests) first, then port
   it to DuckDB SQL against real synthetic tracker data. This is the
   genuinely hard, multi-dimensional case (account × type × basis ×
   tracking/mob × horizon, self-referential) — the step that actually tests
   whether this architecture avoids repeating a past internal ClickHouse
   evaluation's failure mode (swapping engines without solving the
   underlying multi-dimensional derivation problem). Sequenced after Cash
   Flow, not before — Cash Flow proves the plumbing works before spending
   effort on the hard case.
3. **Phase 3 — Overdraft interest**, built on top of the now-real Cash Flow
   output instead of stubbed test values.
4. **Phase 4 — Scale/latency test**, only once 1–3 are correct at small
   scale, on the hardest real report shape (not Cash Flow, which real
   per-farm row counts already suggest isn't a meaningful performance risk).
5. **Phase 5 — The additive derived-facts Parquet layer for BigQuery** /
   practice-wide benchmarking, built on report logic now proven correct on
   the hard case, not just the easy one.
