# duckpoc

A standalone PHP 8.3 / Laravel app — same core stack as [Figured](https://github.com/figured/figured-webapp)
— set up to prove out a DuckDB + DuckLake + GCS architecture as a proof of
concept, ahead of a possible rewrite of Figured's reporting engine.

Phase 1 is done: Figured's **Cash Flow report runs as a single DuckDB query**
over DuckLake/Parquet in GCS, and its output matches the real Figured report
cell for cell (see the oracle section below). There's a browser viewer for it
at `/cashflow`.

Phase 1b is done too, and it is the more interesting result. The volume test
(880K journals, ~1.7s) turned out to measure the axis that was never the
bottleneck — **farm complexity is**, via a per-tracker query fan-out in
`LivestockQuantities`. So per-tracker Cash Flow sections now resolve by
grouping a single scan, and **a farm with 50 trackers costs ~5% more than one
with 1** — same journal volume, same SQL text. Start at
[Tracker scaling](#tracker-scaling--the-axis-that-actually-hurts-today) for
that, including an explicit note on what it does not yet show.

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
docker compose run --rm app php artisan duckdb:cashflow:flush    # write inlined rows out to Parquet
```

Then open **http://localhost:8080** — the root is an index of the available
reports, linking to each viewer.

### The report viewer

`/cashflow` renders the Cash Flow report with its parameters as
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

### Flushing inlined data to Parquet

```bash
docker compose run --rm app php artisan duckdb:cashflow:flush
```

**Nothing flushes on its own.** DuckLake sends small inserts (10 rows or
fewer, by default) to the catalog database instead of writing a Parquet file
per insert — deliberately, so transactional-scale writes don't each pay a
round trip to object storage. There is no background compaction and no timer.
Inlined rows become Parquet only when a single insert clears the threshold, or
when you run the command above.

The oracle scenario is 4 transaction lines, 2 accounts and 1 farm, so **every
one of its inserts is under the threshold** — before flushing, the GCS bucket
holds nothing for these tables and all the data lives in
`storage/ducklake/catalog.sqlite`.

Crucially, **queries are correct either way**: DuckLake reads inlined rows and
Parquet as one table, so the report passed all 84 parity cells while the
bucket was still empty. That is the trap — a green parity run proves the
*calculation*, not the *storage*. Partition pruning and row-group statistics
need files to prune, so run the flush when you want to verify the storage
layer rather than the maths.

After flushing, the layout in the bucket is:

```
main/transaction_lines/farm_type=dairy/region=waikato/year=2024/ducklake-….parquet
main/accounts/ducklake-….parquet
main/farms/ducklake-….parquet
```

Only `transaction_lines` is partitioned — it is the only table with
`SET PARTITIONED BY` on it. The dimension tables are small and always read
whole. Verify with:

```bash
gcloud storage ls -r "gs://<bucket>/<prefix>/main/transaction_lines/"
```

Note that a single file in a single partition still does not exercise
*pruning* — there is nothing to prune away. That needs multiple cohorts and
enough volume for multiple row groups, i.e. the 800K scale test.

### Commands

| Command | What |
|---|---|
| `duckdb:test` | Smoke-tests the DuckDB → DuckLake → GCS chain end to end |
| `duckdb:cashflow:schema` | Creates/recreates the Cash Flow tables (destructive) |
| `duckdb:cashflow:seed` | Seeds the 4-line parity oracle scenario |
| `duckdb:cashflow:seed-scale` | Seeds ~880K synthetic lines across 3 cohorts for the scale test |
| `duckdb:cashflow:run` | Runs the report as DuckDB SQL and diffs it against the oracle |
| `duckdb:cashflow:flush` | Writes DuckLake's inlined rows out to Parquet and lists the resulting partition paths |
| `duckdb:tracker:seed` | Seeds 3 farms x 200K journals differing only in tracker count (1/10/50) |
| `duckdb:tracker:curve` | Measures report time against tracker count, volume held constant |

The root URL (`/`) is an index of the reports below.

Two report viewers:

| Page | What |
|---|---|
| `/cashflow` | Plain Cash Flow — the parity-checked report (84/84 against Figured) |
| `/tracker-cashflow` | Cash Flow with per-tracker income sections, and the tracker-count timings |

The two are deliberately separate controllers and views. `/cashflow` is the
artefact parity is asserted against, so nothing in the tracker work can
regress it.

## Why this PoC uses an Australian bucket

The PoC bucket is **`bobby-poc-au` in `australia-southeast1` (Sydney)**. That
is deliberately *not* a recommendation about where Figured's data should live —
production data and production compute are both in `us-central1`, co-located,
and that is correct.

The bucket region here is about **measurement validity, not speed**.

Development happens from Auckland. Measuring against `us-central1` from there
means every number carries ~13,000 km of round trip that production never
pays, since production's compute sits next to its data. That conflates
"is this architecture viable" with "how far is my laptop from Iowa", and the
second question is not the one the PoC exists to answer.

Three options, and why Sydney is the least bad:

| Option | Exercises the real GCS path? | Latency realistic vs production? |
|---|---|---|
| Local Parquet (`GCS_BUCKET=` empty) | **No** — bypasses httpfs, auth, network entirely | n/a |
| `us-central1` bucket | Yes | No — models a distance production does not have |
| **`australia-southeast1`** | **Yes** | **Closest available proxy** |

Sydney is ~2,150 km from Auckland (~25–30 ms RTT). There is no New Zealand
region — GCP has only `australia-southeast1` (Sydney) and
`australia-southeast2` (Melbourne) in Oceania. So this keeps the full real
code path — `httpfs`, HMAC auth, DuckLake over object storage — at a latency
in the same order as an in-region production fetch.

The local-Parquet fallback is still the right choice when iterating on report
*logic*, because it is instant and free. Use this bucket when the thing under
test is the storage layer.

### What the move actually bought — less than expected

Recorded because the reasoning was wrong in an instructive way. Raw single
object GETs did improve:

```
Sydney:  tcp 35ms -> tls 234ms -> ttfb 300ms   (data fetch only ~66ms)
Iowa:    tcp 35ms -> tls 240ms -> ttfb 500ms   (data fetch ~260ms)
```

But the Cash Flow report time did **not** change at all: 2,504 ms against
Iowa, 2,535 ms against Sydney.

The reason is visible above. **The TLS handshake is ~200 ms and identical in
both regions** — it terminates at a nearby anycast frontend either way, so
bucket distance never touches it. Repeated handshakes, not distance, dominated
the report. Enabling `httpfs_connection_caching` (see
`DuckLakeConnectionFactory::tuneRemoteReads`) cut ~40%, which the region move
could not.

Lesson worth keeping: the TLS breakdown was already sitting in the curl probe
output before the bucket was created. Reading it properly would have found the
cheaper fix first.

**But the read path was only half the story — the write path improved ~5×.**
Re-seeding the same 880,004-row scale dataset:

| Target bucket | Seed time |
|---|---|
| `bobby-poc` (us-central1, Iowa) | 47.1 s |
| `bobby-poc-au` (australia-southeast1) | **9.3 s** |

Writes benefit where reads did not because a bulk seed issues *many* PUTs and
pays a full round trip on each, and unlike the read path there is no connection
reuse hiding the distance. So "the move bought less than expected" is true of
report latency specifically, and false of ingest. Worth separating the two
whenever a placement decision comes up — they have different cost structures.

## Tracker scaling — the axis that actually hurts today

Volume was the wrong thing to measure first. Figured's ex-CTO, on being shown
the 583K-journals-in-1.7s result:

> the complexity and slowness in current reports though comes from teh
> complexity of the farm, rather than the outright number of journals — ones
> with 50 trackers — means it has to do 50x queries

He is right, and the codebase says exactly where. **It is not in journal
aggregation:** `QueryMongoReportDataService` buckets intervals into
`first`/`actuals`/`forecast`/`other` and issues one aggregation per bucket —
**4 queries, whatever the tracker count.** Tracker sections do not multiply it.

The fan-out is in the non-journal tracker data.
`LivestockStructureBuilder::getSectionsByTracker()` loops
`foreach ($trackers as $tracker)` and per tracker calls:

```php
$this->livestockQuantities->getTrackerQuantitiesSection($id.'_quantities', [$tracker->id], …)
```

Note `[$tracker->id]` — a single-element array, once per tracker. Inside,
`TrackerQuantityService` does a `Tracker::findOrFail()` plus a stock-quantity
computation. Textbook N+1, at report-structure level, gated behind
`showTrackersQuantities`.

So the real cost model is not `f(journals)` but roughly
**`f(journals) + trackers × g(period)`**, and the second term is the one that
was never tested here.

### The experiment

Three farms, **journal volume held constant** at 200,000 rows each, varying
only tracker count (1 / 10 / 50). Vary both and the curve means nothing.
Each farm gets its own `region` so it lands in its own partition and cannot be
charged for another's files.

```bash
php artisan duckdb:tracker:seed     # 3 farms x 200K journals, 1/10/50 trackers
php artisan duckdb:tracker:curve    # the timing curve
```

**Result — fresh process per run, farms measured in REVERSE order so the
50-tracker farm runs first and gets no warmed cache:**

| Farm | Trackers | Runs (ms) | Median |
|---|---|---|---|
| `tracker-farm-50` | 50 | 60 / 62 / 58 | **60 ms** |
| `tracker-farm-10` | 10 | 57 / 55 / 59 | 57 ms |
| `tracker-farm-01` | 1 | 57 / 69 / 56 | 57 ms |

**50× the tracker cardinality costs ~5% more time.** The ordering is
deliberately stacked against the conclusion: if tracker count were expensive,
measuring 50 first would exaggerate it, not hide it.

The structural half of the claim is cheaper to prove than the timing, and
`duckdb:tracker:curve` asserts it: **the generated SQL is byte-identical
(4,195 bytes) for 1 tracker and for 50.** Nothing interpolates a tracker id or
count — the count reaches the query only as `GROUP BY tracker_id` cardinality.
The command fails if a farm id ever appears in the SQL text, so a future change
cannot quietly reintroduce per-tracker SQL.

Query count: **2 for any farm** (consolidated report + per-tracker breakdown),
against Figured's 4 + one per tracker.

### The comparison worth quoting

Figured builds the consolidated gross profit by string concatenation, one term
per tracker:

```php
implode(' + ', $trackerGrossProfitIds) . ' + other_income - direct_costs'
```

At 50 trackers that is a 50-term formula string assembled at runtime and
evaluated per interval. The equivalent here is one `SUM()` over the tracker
groups. That difference is structural, not cosmetic — it is the same reason
the fan-out exists at all.

### What this does NOT show

Being explicit, because it would be easy to overclaim:

- **Only the journal-driven half is done.** Per-tracker *quantities and
  valuation* — the stateful opening → movements → closing chain, which is
  where `getStockQuantities()` spends its time — is Phase 2. If the real cost
  in Figured is per-tracker *computation* rather than per-tracker *round
  trips*, then the win comes from window functions (Phase 2), not from query
  elimination (measured here). Both are DuckDB strengths, but they are
  different claims.
- **The N+1's structure is verified; its magnitude is not.** 50 cheap
  `findOrFail`s would be ~100 ms, not seconds. The weight has to be inside
  `getStockQuantities()`, which this PoC has not measured.
- Tracker sections here are livestock only, and use a synthetic chart of
  accounts, not Figured's real account mappings.

### A seed calibration worth recording

First run produced a permanently *negative* tracker gross profit. The signs
were correct — verified against raw storage: income stored −106,117 (credit),
costs +209,816 (debit), and 106,117 − 209,816 = −103,699, exactly what the
report showed. The cause was the chart of accounts: **four cost accounts
against two income accounts**, with rows spread evenly per account, makes costs
~2× income by construction. Arithmetically right, completely unrealistic, and
on a demo page it reads as a bug in the report. Fixed with an explicit
`REVENUE_WEIGHT` in the seeder rather than by touching the report.

The general lesson, which this project has now learned twice: synthetic data
that is *self-consistent* still proves nothing about whether the inputs are
plausible.

## Scale test results — the headline finding

Seeded 880,000 transaction lines across 5 farms in 3 `(farm_type, region)`
cohorts, which DuckLake wrote as **41 Parquet files across 24 partitions**
(3 cohorts × 8 years). Bulk inserts bypass inlining entirely, so no flush was
needed. Seed time: 47s.

The hero farm shares a cohort with the oracle farm deliberately, so the
oracle's parity check became a find-4-rows-inside-820,004 test. **It still
passes all 84 cells.** Correctness holds at volume.

### DuckDB's compute is genuinely fast

Same query, three consecutive calls in one process:

| Farm | call 1 | call 2 | call 3 |
|---|---|---|---|
| oracle (4 rows) | 1,079 ms | **19 ms** | **18 ms** |
| isolated cohort (20K rows) | 3,766 ms | **20 ms** | **19 ms** |
| hero (800K rows) | 6,082 ms | **31 ms** | **29 ms** |

A full 12-month Cash Flow over an 800K-row farm in **~30 ms** — only ~10 ms
more than the 4-row case. The earlier inference that "800K rows is nothing for
DuckDB" is now measured rather than assumed.

### The cold-start cost, and where it actually comes from

Three real HTTP requests to the viewer, hero farm:

```
request 1: 6.26s     request 2: 6.21s     request 3: 5.85s
```

**No warm-up** — each request builds a fresh DuckDB instance, so nothing
survives between requests and every page load re-reads Parquet from GCS.

The cause is **per-file GCS latency**, and it is latency-bound rather than
bandwidth-bound. Reading four distinct files cold through DuckDB's httpfs:

| File | Cold read | Rows in file |
|---|---|---|
| 1 | 1,327 ms | 4 |
| 2 | 1,048 ms | 100,000 |
| 3 | 843 ms | 100,000 |
| 4 | 875 ms | 100,000 |

**A 4-row file costs the same as a 100,000-row file** — so the cost is per
object, not per byte. ~900 ms × the number of files a query touches is the
whole page-load budget.

Breaking one cold request into phases (oracle farm — four rows):

```
connect/attach :    86 ms
farms listing  : 3,073 ms     <- a SIX-row table
report query   : 5,207 ms
```

The `farms` listing taking 3 s for six rows is the clearest illustration:
volume is irrelevant, file count and per-object latency are everything.

**Why this looked like a regression from the mass seed, but wasn't.** Page
loads measured ~258–404 ms earlier in the PoC. Those measurements were taken
*before* `duckdb:cashflow:flush` — all data was still inlined in the local
local file catalog, so the queries did **zero** GCS reads. The slowdown came from
data moving local → remote, not from row count. The bucket's region
(`us-central1`, far from NZ) inflates the per-object figure, but cannot
explain the change, since it was the same bucket before and after.

### Partition pruning: `farm_type`/`region` yes, `year(date)` no

Two tests, both cold:

**1. Widening the date span should read ~8× the files if year pruning works:**

| Span | Time |
|---|---|
| 1 year (2024) | 13,234 ms |
| 8 years (2018–2025) | 10,716 ms |

The wider query was *faster*. No pruning effect.

**2. Adding the partition expression explicitly should prune if it can:**

| Predicate | Cold time | Rows |
|---|---|---|
| `date BETWEEN …` only | 4,674 ms | 102,504 |
| `+ year(date) = 2024` | 5,423 ms | 102,504 |

Identical results, no improvement — the difference is noise.

**Re-measured on the Australian bucket, and the earlier conclusion was only
half right.** Test 2 above couldn't distinguish two hypotheses, because its
*baseline already contained* the `date BETWEEN` predicate. Comparing each
predicate form on its own — `SUM(amount)` to force real Parquet reads, a
**fresh process per probe** so no metadata cache carries over — separates them:

| Predicate (same partition cohort) | Time | Rows |
|---|---|---|
| `year(date) = 2024` | 1,310 ms | 102,504 |
| `date BETWEEN '2024-01-01' AND '2024-12-31'` | **817 ms** | 102,504 |

Identical results, 38% apart. So the sharper finding is:

> **The raw `date` range predicate is what prunes; the `year(date)` transform
> does not.** DuckDB maps a range on `date` onto per-file min/max statistics
> and skips files. It cannot map an opaque function call onto those same
> statistics, so `year(date)` is evaluated as a row filter *after* the file
> is read.

That also explains test 2's null result: the baseline was already pruning, so
adding `year(date)` on top had nothing left to contribute.

**Cost tracks file count, not row count.** Across the cohort probes:

| Cohort | Files | Rows | Time |
|---|---|---|---|
| `sheep/otago` | 8 | 20,000 | 639 ms |
| `dairy/waikato` | 17 | 820,004 | 1,252 ms |
| all | 41 | 880,004 | 1,348 ms |

41× fewer rows buys only ~2× less time, and time rises roughly with files.
Compute is not the variable here — remote round trips are. (Resisting the urge
to fit per-file and fixed-cost coefficients to these: two different pairs of
points give two incompatible models, so the honest claim is the direction, not
a formula.)

**Design implication:** partitioning by `year(date)` multiplies file count ~8×
while the transform itself never prunes, and cost is per-file — so the year key
earns its keep *only* because queries filter on raw `date` ranges, which prune
via statistics whether or not year is a partition key. Before Phase 2, worth
testing an explicit `year` INTEGER column as a plain partition key, and testing
dropping year from the key entirely; fewer, larger files may simply win.

**No code change needed today:** `ReportSqlBuilder::inScopePredicate()` already
filters on `tl.date BETWEEN … AND …`, the form that prunes. `year(date)` appears
only in the partition DDL, which is where it belongs. The rule to keep is:
**never filter on `year(date)` in query predicates — always a raw date range.**

### A seeder bug worth recording

The first scale seed produced an uneven year spread (55K/95K/105K instead of
100K) and spilled into a spurious ninth year, giving a 7/16–9/16
actuals/forecast split instead of 50/50. Cause: **DuckDB's `/` is float
division** — `SELECT 21/20` returns `1.05`, so `(i / 20) % 8` never cycled
cleanly. Fixed by using `//` (integer division); the re-seed produced exactly
440,000 / 440,000.

With `GCS_BUCKET` left empty in `.env`, this runs entirely locally (file
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

0. **`catalog.sqlite` is not SQLite, and deleting it orphans the whole lake.**
   Two traps in one file.

   The attach string is `ducklake:<path>` with no `sqlite:` prefix, so DuckLake
   uses its default metadata backend, which is **DuckDB**. The file begins with
   the magic bytes `DUCKD`; `sqlite3` and PDO's sqlite driver both reject it
   with "file is not a database". The `sqlite` driver label and the `.sqlite`
   extension are both misnomers. Inspect it with DuckDB instead:

   ```sql
   ATTACH 'storage/ducklake/catalog.sqlite' AS c (READ_ONLY);
   SELECT value FROM c.ducklake_metadata WHERE key = 'data_path';
   SELECT count(*) FROM c.ducklake_data_file;
   ```

   More importantly it is the **only** record of what the bucket contains —
   schemas, the Parquet file list, partition values, snapshots. Delete it and
   every Parquet object in GCS is orphaned: the data survives, but nothing can
   find it, and there is no rebuild-from-bucket command. Back it up before any
   experiment that might clobber it.

   Note that MySQL being attached does **not** make MySQL the catalog. MySQL
   holds the *dimension* tables (`farms`, `accounts`) as the `appdb` alias;
   the catalog is entirely separate. Easy and expensive to conflate.

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
   file catalog (or using a local, non-`gs://` `DATA_PATH`) whose
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

Defaults to a **local file catalog** (`DUCKLAKE_CATALOG_DRIVER=sqlite`), per
DuckLake's own documented recommendation for local, single-writer PoCs.

Despite the driver name and the `.sqlite` filename, that file is a **DuckDB**
database, not SQLite — see gotcha 0. The single-writer limitation that blocks
compaction while the web container is running is therefore DuckDB's file lock,
not SQLite's; same practical effect, different cause. Postgres and MySQL
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
- **Tracker-count independence**: per-tracker Cash Flow sections resolved by grouping one scan, with the 1/10/50 curve flat (~5% for 50x the trackers, measured worst-case-first in fresh processes) and the generated SQL byte-identical across tracker counts

**Not yet verified:**
- Postgres or MySQL as the DuckLake catalog backend (a local DuckDB file remains the default per DuckLake's own PoC guidance)
- Anything at actual data volume — this proves correctness, not performance at scale (though real per-farm row counts gathered separately — Figured's largest farms run ~180K–800K total journal rows — suggest single-farm query volume is not a meaningful performance risk for DuckDB regardless)
- The `non_operating_income`, `non_operating_expenses`, `non_operating_movements`, `equity_movements` and `gst` sections. These are implemented in `CashFlowQuery` from the structure definition, but the oracle scenario has no accounts in them, so their sign handling — the last three are `setInverse(true)` sections — is written-but-unexercised. Deliberately left that way: the PoC's open question is DuckDB's performance and the multi-dimensional VJ problem, not exhaustive section coverage. GST is the one most likely to matter on real data.
- Negative-value display (bracketed) in the viewer — no negatives in the oracle scenario

## Next steps

Phased per the architecture conversation this PoC came out of. **Phase 0
(scaffold) and Phase 1 (Cash Flow) are done**; the rest is not started:

1. ~~**Phase 1 — Cash Flow.**~~ **Done.** Schema + partitioning, the oracle
   captured from Figured's real engine, the report as one DuckDB query, and a
   parity check that passes on all 84 cells. Plus a browser viewer. The
   880K-row scale test is done too.
2. ~~**Phase 1b — tracker sections in Cash Flow.**~~ **Done.** Added because
   the volume result turned out to measure the axis that was never the
   bottleneck: farm complexity is, and Cash Flow *already* has tracker
   sections in real Figured — they were simply missing here. `tracker_id` is
   now a fact-table column, per-tracker income/direct-cost sections resolve by
   grouping one scan, and the 1/10/50 curve is flat. See the tracker scaling
   section above, including what it deliberately does not show.
3. **Phase 2 — Livestock valuation.** Research the actual virtual-journal
   handler (methodology, data dependencies, existing tests) first, then port
   it to DuckDB SQL against real synthetic tracker data. This is the
   genuinely hard, multi-dimensional case (account × type × basis ×
   tracking/mob × horizon, self-referential) — the step that actually tests
   whether this architecture avoids repeating a past internal ClickHouse
   evaluation's failure mode (swapping engines without solving the
   underlying multi-dimensional derivation problem).

   **Phase 1b sharpened what this phase has to prove.** The per-tracker
   *quantity and valuation* chain — opening stock → movements → closing →
   valuation, stateful and ordered within each tracker — is where
   `getStockQuantities()` actually spends its time, and it is the half Phase
   1b did not touch. The claim to test is that it becomes a window function
   `PARTITION BY tracker_id`, computed for every tracker in one pass. That is
   the thing Mongo cannot express, and therefore the real reason the current
   engine loops in PHP at all — not an implementation slip.
4. **Phase 3 — Overdraft interest**, built on top of the now-real Cash Flow
   output instead of stubbed test values.
5. **Phase 4 — Scale/latency test**, only once 1–3 are correct at small
   scale, on the hardest real report shape (not Cash Flow, which real
   per-farm row counts already suggest isn't a meaningful performance risk).
6. **Phase 5 — The additive derived-facts Parquet layer for BigQuery** /
   practice-wide benchmarking, built on report logic now proven correct on
   the hard case, not just the easy one.
