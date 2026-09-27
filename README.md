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

One number to keep in proportion before reading further: **a realistic farm's
report runs cold in 0.94 s with 10 HTTP requests** (200K rows, 50 trackers).
Everything below about seconds and thousands of requests concerns the
deliberately oversized hero farm, which is ~625x any real farm — see
[The hero farm](#the-hero-farm--what-it-is-and-what-it-is-not) for why it
exists and what it does not prove.

The biggest single win, though, was neither SQL nor architecture but **physical
layout**: DuckDB's default Parquet row group size (122,880) is tuned for local
disk, and over object storage it dominated everything. Raising it to 1,000,000
made the same report **5.9x faster** and writes **1.55x faster**, and partition
granularity halved it again. See
[Row group size](#row-group-size-the-single-biggest-win-measured-in-this-poc).

## Stack

| Piece | What | Why |
|---|---|---|
| PHP 8.3, Laravel 13 | Standard Laravel app | Figured itself is on Laravel 11; this scaffold took whatever `composer create-project` gave it, which is fine for a PoC but is a version ahead |
| MySQL 8.0 | Laravel's own app DB (`DB_CONNECTION`) | Matches how Figured uses MySQL — app/config data, not report data |
| DuckDB | Queried via [`satur.io/duckdb`](https://github.com/satur-io/duckdb-php) (FFI binding to the official C API) | No first-party PHP client exists; this is the most-adopted community one (148k+ installs) |
| DuckLake | A DuckDB extension, attached at runtime | Catalog/table format over Parquet in GCS |
| GCS | Data files (`gs://…`), authenticated via HMAC keys | See gotcha below — this is **not** service-account JSON auth |
| AlloyDB Omni | PostgreSQL 17 + Google's in-memory columnar engine, via `pdo_pgsql` | The alternative being tested: one database serving the app *and* its reports, with no lake and no catalog |

## Quick start

The stack is split into four groups, one per storage backend, selected with
Compose profiles. **A bare `docker compose up` starts nothing** — every service
carries a profile, so you have to say which stack you want.

```bash
docker compose build

docker compose --profile gcs     up -d   # :8080  DuckLake on gs://   + mysql
docker compose --profile minio   up -d   # :8081  DuckLake on s3://   + minio + mysql
docker compose --profile alloydb up -d   # :8082  PostgreSQL 17, no lake, no MySQL
docker compose --profile mongo   up -d   # :8083  MongoDB + MySQL — the current stack
docker compose --profile '*'     up -d   # everything

docker compose --profile gcs --profile minio up -d   # combine with repeated flags
```

| profile | port | engine | storage |
|---|---|---|---|
| `gcs` | 8080 | DuckDB + DuckLake | `gs://` — carries the real ~8 ms Tasman round trip |
| `minio` | 8081 | DuckDB + DuckLake | `s3://` on localhost — the no-network control |
| `alloydb` | 8082 | PostgreSQL 17 + columnar engine | none; rows live in the database |
| `mongo` | 8083 | MongoDB 7 + MySQL + PHP | journals in Mongo, dimensions in MySQL |

GCS and MinIO are deliberately **separate** profiles rather than one "duckdb"
group. They are the same engine over different storage, and the whole point of
running both is to isolate network latency — so a timing should never be
ambiguous about which backend produced it.

The `mongo` profile is the **baseline**, not a fourth candidate. It reproduces
what Figured runs today so the other three have something to be measured
against, and it shares MySQL with the lake profiles on purpose — see
"The MongoDB baseline" below.

Then open **http://localhost:8080** (or `:8081` / `:8082` / `:8083`) — the root
is an index of the available reports, linking to each viewer.

### Seeding the lakehouse stack

```bash
docker compose run --rm app php artisan duckdb:cashflow:schema   # create tables
docker compose run --rm app php artisan duckdb:cashflow:seed     # seed the oracle scenario
docker compose run --rm app php artisan duckdb:cashflow:run      # run + parity-check
docker compose run --rm app php artisan duckdb:cashflow:flush    # write inlined rows out to Parquet
```

### Seeding the MongoDB baseline

```bash
docker compose --profile mongo run --rm app-mongo \
    php artisan mongo:setup --farm=gm-dairy-farm --fresh

docker compose --profile mongo run --rm app-mongo \
    php artisan mongo:setup --farm=gm-dairy-farm-1m --region=gm-bulk --rows=1000000
```

`--fresh` drops the journal collection. Dimensions are **upserted** into the
shared MySQL rather than replaced, so seeding this stack cannot pull the
dimension rows out from under the `gcs` / `minio` profiles.

### Seeding the AlloyDB stack

```bash
docker compose --profile alloydb run --rm app-alloydb \
    php artisan alloydb:setup --farm=gm-dairy-farm --fresh --columnar
```

`--columnar` populates the in-memory column store, which is what makes the
report fast — and is rebuilt from scratch on every restart, because it is
memory-resident. The report page's **Table in memory** card shows coverage;
below 100% the engine is falling back to heap scans.

### Two things that will bite you

**Stop with the wildcard.** `docker compose down` without a profile leaves
profiled containers running, so a previous group keeps answering on its ports
and it looks as though the profile you just started is broken — or worse, a
measurement gets attributed to the wrong backend:

```bash
docker compose --profile '*' down
```

**`depends_on` cannot cross a profile boundary.** A service in one profile that
depends on a service only in another fails the *whole project* with
`depends on undefined service: invalid compose project`. Each group has to be
self-contained, which is why the two stacks share nothing.

### Why the AlloyDB stack has no MySQL

That is the proposition under test: one database holding the operational
records *and* reporting on them, rather than a lake plus a catalog plus a
relational store. `AlloyDbSeeder` generates farms, accounts, trackers, milk
production, stock movements and journals straight into PostgreSQL, and the
report reads only that connection.

Getting there needed more than dropping the dependency. Laravel's own plumbing
was keeping MySQL alive: `.env` sets `SESSION_DRIVER`, `CACHE_STORE` and
`QUEUE_CONNECTION` to `database`, so every request touched MySQL before
reaching a controller. `app-alloydb` overrides those to `file`/`sync` and
points `DB_CONNECTION` at `alloydb`.

The lake reports (`/cashflow`, `/gross-margin-v2`) will not work on `:8082` —
they need MySQL for dimensions and a bucket for facts. Use `:8080` or `:8081`
for those.

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
| `duckdb:hero:seed` | Seeds the hero stress-test farm — 50 trackers over 30 years, `--rows=` (default 10M) |
| `duckdb:hero:bench` | Report time on the hero farm across widening period windows |
| `duckdb:cashflow-af:seed` | Seeds the actuals-plus-forecast oracle farm and the 13-milk-tracker dairy farm, `--lines=` (default 200K) |
| `duckdb:cashflow-af:run` | Runs the actuals-plus-forecast Cash Flow as one statement; on the oracle farm checks 687 cells across three passes |

The root URL (`/`) is an index of the reports below.

Two report viewers:

| Page | What |
|---|---|
| `/cashflow` | Plain Cash Flow — the parity-checked report (84/84 against Figured) |
| `/tracker-cashflow` | Cash Flow with per-tracker income sections, and the tracker-count timings |
| `/cashflow-actuals-plus-forecast` | The whole Figured request — a from/to period, horizon, tracker blocks, EOY exclusion, Total column, and the milk/GST/overdraft virtual journals — as one statement. See [the section below](#cash-flow--actuals-plus-forecast-the-whole-request) |

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

## The hero farm — what it is, and what it is not

`hero-tracker-farm-50` currently holds **500,000,000 journal lines across 30
years (1996-2025)**, 16,666,666 per year, with 50 livestock trackers.

**It is not a realistic farm, and the gap is not close.** Figured's largest real
farms run ~800K journals over ~10 years (~80K/year). Put side by side:

| | Span | Total | Per year | 2-month window |
|---|---|---|---|---|
| Largest real farm | 10 years | 800,000 | ~80,000 | ~13,300 rows |
| `hero-tracker-farm-50` | 30 years | 500,000,000 | 16,666,666 | ~2,700,000 rows |
| Ratio | 3x | **625x** | **208x** | **~200x** |

The sharpest way to put it: **one month of the hero farm holds more journals
than the largest real farm accumulates in a decade.**

The 30-year span is also longer than any real farm could have — Figured has
only existed since ~2013. It is stretched for a partitioning reason, not a
realism one, and the seeder says so in its docblock so the number is not later
mistaken for a claim about real data. 500M cannot be a realistic single farm at
any span; only fewer rows would fix that.

### So what is it for?

Two questions that a realistic farm cannot answer:

1. **DuckDB's ceiling** — where does the engine stop coping? (It did not, at
   1e9.)
2. **Practice-wide volume** — 500M is roughly the whole platform's journals in
   one table (~3,300 farms at ~150K each), which is the cross-farm
   benchmarking problem this architecture is also meant to serve. Read that
   way it is a *realistic aggregate*, just not a realistic farm.

### The realistic per-farm number

This is the figure to quote when the question is "is this fast enough for
Figured". Cold, on `tracker-farm-50` (200K rows, 50 trackers):

```
total            0.94s
SQL engine       0.00s
storage/waiting  0.94s
HTTP GETs        10
transferred      64.1 KiB
```

**Under a second, 10 requests, 64 KiB — and SQL time is literally zero.** At
realistic volume the query is free and the entire cost is a handful of round
trips to Sydney, which co-located production compute would not pay either.

### Partition granularity: worth as much as row group size

Reseeding the same 500M rows from a 10-year span to a 30-year span triples the
partition count (`year(date)` is the partition key) and so thirds the rows per
partition. Same volume, same row group size, cold profiles:

| Window | 10-year span | 30-year span | |
|---|---|---|---|
| 2 months | 5.14 s / 502 GETs | **2.37 s / 189 GETs** | 2.2x faster |
| 1 year | 10.02 s / 950 GETs | **4.40 s / 320 GETs** | 2.3x faster |

This is the answer to a puzzle that came up earlier: narrowing a report's
window from a year to two months barely helped (67s vs 37s at one point),
because at 10 years a two-month query opened *the same files* as a one-year
query — a month is far smaller than a year-sized partition, so there was
nothing further to prune.

Note the limit, visible in the numbers above: **1-month and 2-month windows
both cost 189 GETs**, because both still read one whole year partition. Finer
granularity would need month-level partitioning, which multiplies file count —
and file count is the other cost driver. Year looks like the right balance.

### Seeding it

```bash
php artisan duckdb:hero:seed --rows=500000000 --chunk=25000000
```

500M takes ~6.7 min at ~1.25M rows/s. The seeder clears the farm first, so the
previous version's Parquet files become tombstoned — **run the cleanup
afterwards** or the bucket keeps both copies:

```sql
CALL ducklake_expire_snapshots('lake', older_than => now());
CALL ducklake_cleanup_old_files('lake', cleanup_all => true);
```

Skipping that left the bucket at 7.74 GB when the live data was 3.87 GB.

### A distribution bug worth recording

The seeder used to map inserts to years with `chunk % YEARS`, which only
distributes evenly when the chunk count is a multiple of the year count. It
worked for 1e9/25M over 10 years (40 chunks) and 500M/25M over 10 years (20
chunks) — both exact multiples — purely by coincidence.

Moving to a 30-year span would have made it 20 chunks over 30 years, leaving
**ten years with zero rows while reporting complete success**. It now derives
the plan from the years (one insert per year, split further only if a year
exceeds `--chunk`), so even coverage is structural. Verified after seeding:
30 distinct years, zero empty, min 16,666,666 / max 16,666,686 rows per year.

## Row group size: the single biggest win measured in this PoC

**DuckDB's default Parquet row group size is 122,880 rows. Over object storage
that default is the dominant cost of a query.** Changing it to 1,000,000 made
the same report **5.9x faster** and, unexpectedly, writes **1.55x faster**.

### Why it matters

DuckDB issues **one HTTP range request per (row group x column)**. Row group
count therefore sets the request count, and each request pays a full network
round trip. Bytes barely matter; requests do.

Measured on the hero farm when it held **1,000,000,000 rows over 10 years** —
kept as the historical record because this is the comparison that isolates row
group size, and re-deriving it would mean re-seeding a billion rows twice. The
farm has since been reseeded smaller (see *The hero farm* below); the ratios
below are what the setting changes, not the current absolute times.

Same 2-month report, profiled cold both times:

| | 122,880 (default) | 1,000,000 | Change |
|---|---|---|---|
| **Total** | 54.80 s | **9.26 s** | **5.9x faster** |
| Storage / waiting | 53.06 s | 8.03 s | 6.6x less |
| SQL engine | 1.74 s | 1.23 s | ~unchanged |
| **HTTP GETs** | 4,247 | **1,014** | 4.2x fewer |
| Transferred | 26.5 MiB | 36.3 MiB | **+37%** |
| Per request | 12.5 ms | 7.9 ms | |

Note the direction of the bytes column: the faster configuration **reads more
data**. Coarser row groups mean a filter pulls slightly more than it needs, and
that trade is overwhelmingly worth it. Any explanation of this report's cost in
terms of download volume is wrong — that was the first two theories, and both
were wrong.

### Writes got faster too

Not predicted. Fewer column chunks to encode, smaller footers, and fewer
catalog rows for DuckLake to track:

| | Row groups | Seed time (1e9 rows, 10-year span) | Throughput |
|---|---|---|---|
| Default | 122,880 | 1,190 s | 840,584 rows/s |
| Tuned | 1,000,000 | **768 s** | **1,302,188 rows/s** |

### How to set it

It is a **DuckLake catalog option**, not a DuckDB setting — `SET
parquet_row_group_size` does not exist and errors as an unrecognised parameter:

```sql
CALL lake.set_option('parquet_row_group_size', '1000000');
```

It persists in the catalog and applies to every subsequent write. Captured in
code as `CashFlowSchema::ensureWriteOptions()`, called by every seed command so
a rebuilt catalog cannot silently revert to the default.

**It does not rewrite existing files.** Parquet row groups are fixed at write
time, so changing it only affects new writes. Benefiting from it means
rewriting the data — and for synthetic data **re-seeding is faster than
rewriting in place**, because seeding generates rows locally at >1M/s while a
rewrite has to read them back from GCS first, which is the slow path being
fixed.

### 1,000,000 is a request, not a guarantee

The setting is an upper bound that interacts with
`write_buffer_row_group_memory_limit` (default 250 MiB). Actual result on this
data: **47-48 row groups per 25M-row file at 653K-958K rows each**, not the
25 groups of exactly 1M that the setting alone implies. Pushing closer to 1M+
per group would need that memory limit raised too.

### The diagnostic that found it

Three wrong diagnoses preceded this, each a theory fitted to one elapsed-time
number: "it is downloading gigabytes" (wrong — column pruning means it reads a
fraction), "it is request latency x count" (right mechanism, wrong magnitude,
asserted before isolating anything), and "it is the SQL" (wrong — SQL was 3%).

What settled it was `EXPLAIN ANALYZE`, which reports HTTPFS request counts and
per-operator timings directly. That is now built in:

- `QueryProfiler` — parses total time, HTTP GETs, bytes, operator timings
- The report page's **Query profile** panel (`?explain=1`), showing **SQL engine
  vs storage/waiting** side by side — the one split that answers "is it my
  SQL?" without guessing
- `duckdb:tracker:profile` — the same profile from a fresh process, for cold
  numbers

The panel's caveat is stated in the UI: it profiles *after* the report has run
in the same request, so httpfs is warm and its GET count understates a cold
read (it showed 0 on a small farm). Use the command for cold figures.

**The general lesson worth carrying into the real system:** the storage layer's
physical layout — row group size, partition granularity, file count — dominated
every SQL-level concern in this PoC, by roughly an order of magnitude. And
elapsed time alone never once identified the cause correctly.

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

## Big-farm findings (the 500M-row farm)

`gm-dairy-farm-500m` holds 499,999,724 rows, all `basis='cash'`: 333,333,148
actuals (2024-01-01 .. 2026-08-28) and 166,666,576 forecast (2026-09-01 ..
2027-12-28). With the default horizon of `2026-08-31` sitting cleanly between
the two, `period_from=2024-01-01` / `period_to=2027-12-31` reaches every row —
useful as the worst-case report window. Only 14 of the 50 accounts carry a
`report_group`, but the seeder used exactly those, so nothing is lost to the
report's `report_group IS NOT NULL` filter.

### The spill — the largest single win after row group size

Gross Margin V2 spent most of its time writing to local disk, not reading the
lake. On a 12-month window: **25,596 local writes and 12,646 local reads**
against `.tmp/duckdb_temp_storage_*.tmp`, with `FileSystem` overtaking
`HTTPFSInfo` as the dominant cost once MinIO had removed the network.

The cause was ordering. `report_lines AS MATERIALIZED` joined `accounts` onto
every journal line *first* — 125M rows each carrying four repeated VARCHARs
(`account_name`, `account_class`, `report_group`, `report_group_label`),
roughly 12 GB against a 13.4 GiB memory limit — and only aggregated afterwards.
The fix aggregates the fact table to one row per (month, account) **before** any
dimension column is attached, which collapses 125M rows to ~600, then joins the
labels to those. `MATERIALIZED` moved onto the small aggregate, because `levels`
is read twice below and without pinning it DuckDB may re-derive the CTE and scan
the lake twice.

Two things make it equivalent rather than merely faster: `SUM` is associative,
so per-account subtotals summed per section equal the raw lines summed once; and
the REVENUE sign flip is safe to apply to a per-account subtotal because
`account_class` is a property of the account, so every line under it shares the
sign.

| window | rows | before | after |
|---|---|---|---|
| 1 year | 125.0M | 3,673 ms | **875 ms** |
| 3 years | 281.2M | 30,274 ms | **1,685 ms** |

Local filesystem operations went from 38,242 to **zero**. Verified by diffing
the full report output against the pre-change SQL: identical across 249 rows
(1 year) and 561 rows (3 years), every field including per-unit margins and the
derived Gross Margin row.

This also retired an earlier wrong conclusion recorded here — that the 3-year
window was compute-bound and beyond what co-location could fix. It was
spilling.

### Wide windows are decode-bound, not request-bound

Requests track row groups almost exactly — **requests = 9 x row_groups**
(245/27, 726/81, 964/108) — but request count is not what wide windows pay for:

| window | rows | row groups | files | reqs | time |
|---|---|---|---|---|---|
| 1 month | 10.4M | 27 | 2 | 245 | 160 ms |
| 1 year | 125.0M | 27 | 2 | 245 | 841 ms |
| 3 years | 281.2M | 81 | 6 | 726 | 1,680 ms |
| full span | 500.0M | 108 | 8 | 964 | 2,925 ms |

The first two rows issue **identical requests** and differ 5x in time, so the
difference is decoding 114M more rows: about **6 ms per million rows**. That
model predicts 500M x 6 ms = ~3,000 ms against 2,925 ms measured.

So ~2.9 s is close to the floor for scanning half a billion rows with this
design, and storage tuning will not move it. The report is already frugal with
bytes: it transfers ~38 MB of a 4.3 GB file, because `line_id` (2.44 GB) and
`_ducklake_internal_row_id` (1.83 GB) are 99% of the file and neither is read.
Beating it means not reading 500M rows at all — a pre-aggregated monthly rollup
(~600 rows per farm), i.e. the direction in `docs/bigquery-dbt-separation.md`.

Raising row group size does not help here and cannot be done in place:
`ducklake_merge_adjacent_files` only merges small *adjacent* files, so on an
already-compacted lake it is a **no-op (0.0 s)**. Row group size is fixed at
write time.

### Not tested: sorting by date within a partition

The lever worth trying next, recorded because the diagnostic that found it is
cheap to repeat. Every row group in the `year=2025` files carries the same
`date` statistics:

```
group 0   2025-01-01 .. 2025-12-28
group 1   2025-01-01 .. 2025-12-28
group 2   2025-01-01 .. 2025-12-28
...
```

Rows are in random date order within each partition, so every row group's
min/max spans the whole year and **DuckDB cannot skip a single one**. That is
why a 1-month window reads 27 row groups and decodes ~125M rows to return
10.4M — roughly 12x more work than needed, and why the 1-month and 1-year
windows issue identical requests.

Sorting by date within each partition should make those statistics selective, so
a 1-month window touches ~2-3 row groups instead of 27. Predicted ~30 ms rather
than 160 ms, and more importantly it would stop narrow reports scaling with the
size of the year they sit in. This matters more than the full-span number,
because production reports are monthly and quarterly.

**Untested.** DuckLake has no sort-key concept, so it means controlling insert
order in the seeder and rewriting a farm's data to measure it. Note the
trade-off with the row group size finding above: sorted data favours *smaller*
row groups (finer pruning), unsorted data favours larger ones (fewer requests).

### `temp_directory` defaults onto the Docker bind mount

DuckDB's default is `.tmp` — a **relative** path, so it resolves to the process
working directory, which in this container is the macOS bind mount, the slowest
filesystem available here. Every spilling query silently paid for it. Now set
via `config/duckdb.php` (`DUCKDB_TEMP_DIRECTORY`, default `/tmp/duckdb-spill`)
and applied in `DuckLakeConnectionFactory`. Worth ~25% on a cold process, ~11%
through the warm page — but a development-environment artifact, not an
architectural finding.

## AlloyDB Omni — the comparison, and the one rule that decides it

Run with `docker compose --profile alloydb up -d`; report at
`/alloydb/gross-margin` on `:8082`. PostgreSQL 17 plus Google's in-memory
columnar engine, holding the same farms as the lake, returning byte-identical
output — verified across four farm/period combinations so a timing difference
is attributable to the engine and not to a rewrite.

### Porting cost was almost nothing

Four changes in ~200 lines of SQL:

```
strftime(x, '%Y-%m')  ->  to_char(x, 'YYYY-MM')
INTERVAL 1 MONTH      ->  INTERVAL '1 month'
month(x)              ->  EXTRACT(MONTH FROM x)
$param                ->  :param
```

`GROUPING SETS`, `GROUPING()`, `any_value()` (PG16+) and `WITH … AS
MATERIALIZED` (PG12+) are all native, so the nine CTEs and the
aggregate-before-join ordering carried over unchanged.

### GROUP BY a raw column, never an expression

**This is the finding.** The columnar engine pushes aggregation down only when
it can read the grouping key directly from the column store. A function call in
the `GROUP BY` moves the whole aggregate above the scan, and every row then
streams through Postgres's row-at-a-time executor.

Measured on the 500M-row farm, identical filters, identical output:

| grouping key | pushdown | time |
|---|---|---|
| `date_trunc('month', date)` — an expression | none | **40,687 ms** |
| `date` — a raw column | `Rows Aggregated by Columnar Scan` | **2,740 ms** |

A join between the scan and the aggregate blocks it for the same reason, so the
account semi-join has to move out of the hot path too. Applying it afterwards is
equivalent, because an account is either in scope for the whole query or not at
all.

`GrossMarginV2PgSqlBuilder` is written that way: `by_day` groups on `(date,
account_id)` inside the columnar scan, and `monthly_by_account` rolls those
~16k rows up to months. **The full-span report went from 147,697 ms to
2,605 ms — 57x — with no extra memory and no hardware change.**

Read it off the plan rather than inferring it from timing. `EXPLAIN (ANALYZE,
BUFFERS)` shows `Rows Aggregated by Columnar Scan` when pushdown fires, and the
report page reports the same thing as a badge.

Two results that map the boundary: a bare `count(*)` with no `GROUP BY` runs in
**34 ms** over 500M rows, and `GROUP BY account_id` — also a raw column — in
1,753 ms.

### Where it lands against the lake

Same farms, same windows, same host, identical output:

| | lake / MinIO | AlloyDB |
|---|---|---|
| 500M farm, full span | ~2.9 s | **2.6 s** |
| 500M farm, 2 months | 171–220 ms | **119 ms** |
| 1M farm, full span | 65–67 ms | 240 ms |

Competitive at 500M, behind on the small farm. Worth remembering that the 500M
single-farm shape is 625x Figured's largest real farm and penalises the lake's
own strengths too — one farm is 99.8% of that table, so no index has any
selectivity.

### The case against is operational, not speed

- **Two SIGSEGVs in a few hours**, on an idle machine. The image ships
  `restart_after_crash=off`, so a segfault shuts the whole instance down and it
  stays down until someone restarts it. Specifically Omni 17.5.0 on aarch64;
  managed AlloyDB on x86 is a different build and may not do this.
- **~25 minutes of warmup after every restart** — populating the store is one
  full table scan per column, six of them. The store is memory-resident and
  cannot be persisted; `enable_configuration_persistence` only makes the engine
  remember *what* to rebuild. Reports run at heap-scan speed throughout.
- **~20x the storage.** 80 GB (77 GB heap + 3.4 GB index) against ~4 GB of
  Parquet for the same 500M rows, because Postgres stores repeated text inline
  per row where Parquet dictionary-encodes it. Hot database storage, no cheap
  cold tier.
- **The column store is table-wide**, not per farm, so farms compete for one
  fixed pool. Loading a second farm consumes budget the first one's reports
  depend on, and a large arrival can evict another farm's columns and silently
  drop it to heap scans.

### Capacity: read coverage, not the budget

The two disagree, and only one predicts behaviour. At a 1 GB budget the store
sat at 88% *of budget* — which reads as healthy — while holding **31% of the
table's blocks**: it had filled up and stopped. Everything outside those blocks
is read from the heap.

`g_columnar_relations.block_count_in_cc / total_block_count` is the number to
watch; the report page shows it as **Table in memory**. At ~5.8 bytes per row,
500M rows needs ~2.9 GB, so the budget was raised to 4 GB to reach full
coverage.

### Two traps in the tooling

- **`google_columnar_engine_add` is a no-op on a column already registered.**
  After reloading a table it keeps the previous snapshot — it reported 1.3 KB
  for a million rows because it still held a 620-row version. A refresh fixes
  that, but on a *fresh* registration the adds have already populated the store
  and refreshing rebuilds it a second time: six more full passes, roughly
  doubling a 25-minute job. `AlloyDbSchema::columnarize()` refreshes only when
  coverage is short.
- **Dropping an index needs an ACCESS EXCLUSIVE lock**, which the columnar
  engine's background rebuild holds. A 620-row demo seed blocked on it for ten
  minutes and then full-scanned 501M rows to delete 620. `alloydb:setup` drops
  the index only for bulk loads (>= 1M rows).

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

12. **`php artisan serve` strips environment variables, and it will make you
    draw the wrong conclusion.** Passing `-e DUCKDB_STORAGE=s3` to the
    container had no effect on served requests: the parent `serve` process
    saw it (so `docker exec ... tinker` reported `s3`, which is what made it
    convincing), but `ServeCommand` spawns a `php -S` child and filters the
    environment through a hardcoded allowlist —

    ```php
    $hasEnvironment = file_exists($environmentFile);   // .env exists -> true
    ...
    return $this->shouldPassThroughEnvironmentVariable($key) ? [$key => $value] : [$key => false];
    ```

    `static::$passthroughVariables` is `APP_ENV`, `PATH`, `XDEBUG_*` and a few
    Herd paths. Anything else is forwarded as `false`, i.e. **explicitly
    unset**, so the child fell back to the config default and read GCS. Every
    "MinIO made no difference" page measurement was a GCS run. Fixed with
    `--no-reload`, which bypasses the filter entirely.

    The lesson beyond the flag: **verify a backend switch by removing the
    backend.** `docker compose stop minio` and re-requesting the page settled
    in seconds what config inspection had got wrong — the old server still
    rendered a full report, the fixed one renders nothing. Do that before
    trusting any A/B measurement between storage backends.

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

## Cash Flow — actuals plus forecast, the whole request

Figured's Cash Flow was benchmarked on a real farm with the diagnostics URL

```
/reports/data/cash_flow?type=actualsForecast&period=2027&actuals_horizon=2026-06-30
  &display=monthly&group_by=tracker&exclude_eoy_journals=1&with_total=1&with_overdraft=0 …
```

and came back at **16.4 s**, of which 61% was the three virtual-journal
handlers a cash flow fires (milk tracker income 4.7 s, GST payments/refunds
4.1 s, overdraft 0.9 s), 24% was the data fetch, and only ~3.3 s was spent in
MySQL and Mongo at all. The earlier `/cashflow` viewer reproduces the journal-
driven part of that request and none of the virtual journals — so it could not
be held against that number.

`/cashflow-actuals-plus-forecast` reproduces the request. Every option in the
URL has a form input under its Figured name, and every piece of report-time
work Figured does for it is a CTE in **one DuckDB statement**
(`CashFlowActualsForecastSqlBuilder`, driven by
`CashFlowActualsForecastReportDefinition`, which mirrors
`CashFlowStructureBuilder` section for section):

| Figured does | The statement does |
|---|---|
| resolves `period=2027` from the farm's balance date | `from` / `to` bound as dates; `ReportPeriod::financialYear()` makes the default FY2027 range in PHP |
| types each column actuals / forecast / `actualsForecast` | `months` CTE, from the horizon |
| `BuildAggregationPipeline` `$match`, EOY tags `$nin` | `scan` CTE, materialised once |
| `Balance::getReport()` for the opening bank balance | `opening` CTE — the bank accounts' lines before the period |
| `MilkTrackerVirtualJournalService`, per tracker | `milk_vj` — production x payout per tracker, paid the 20th of the next month, virtual only after the horizon |
| `GstPaymentsRefundsVirtualJournal` | `gst_*` CTEs — the NZ two-monthly calendar with the January and May exceptions, predictions after the horizon, actual settlements moved to the payments line |
| `OverdraftCalculationService` over its own sub-report | `inner_cashflow` + `od_accrual` — Phase 3's `WITH RECURSIVE` recurrence, over scan + the other handlers' journals, as Figured nests it |
| per-tracker income/costs sections, then `implode(' + ', $trackerGrossProfitIds)` | `tracker_agg` grouped by tracker, `tracker_rollup` as one `SUM` |
| the eight farm sections and four calculation rows | `farm_agg`, then one chained CTE per formula |
| `OpeningClosingBalance` | two named windows |
| `with_total=1` | a `UNION ALL` row summing flows, `arg_min`/`arg_max` for the balances |
| `with_overdraft=1` limit and headroom rows | `with_limits` CTE |
| `group_by=tracker` blocks | `tracker_rows`, unioned into the same result |

The result is long — `(scope, column)` rows, `scope` being `farm` or a tracker
id — and PHP binds four parameters and pivots. Option gates are compiled into
the SQL text, so an option that is off leaves nothing in the plan.

### Checked, not assumed

`duckdb:cashflow-af:run` seeds a farm small enough to work out on paper
(`CashFlowActualsForecastOracleSeeder` — one line per mechanism, including a
May production row whose June payment falls *before* the horizon and must not
be synthesised) and checks **687 cells across three passes**: the default
request, `exclude_eoy_journals=0`, and with an overdraft configured. The
overdraft pass computes its expectation with Figured's recurrence in PHP over
the hand-derived closing balances, so the SQL is checked against an
independent evaluation rather than against itself.

What it is *not* checked against is Figured itself, unlike `/cashflow`. The
milk payout calendar, the GST due-date rule and the monthly-only overdraft
term are this PoC's reading of the handlers, not captures from the real
services.

### Measured, `cfaf-dairy-nz`

A farm shaped like the benchmarked one: NZ dairy, May balance date, thirteen
milk trackers and two livestock trackers, two-monthly GST, an overdraft the
winter milk trough pushes it into, EOY adjustments, four seasons of history.
`--lines=` fans each account-month out while holding the monthly totals, so
the report reads the same at any volume. MinIO, fresh process per cold run:

| lines seeded | lines in FY2027 | cold | warm (5 runs, median) | page |
|---|---|---|---|---|
| 201,141 | ~50K | 106 ms | 53 ms | 0.36 s |
| 2,000,565 | 500,043 | 116–123 ms | 60 ms | 0.36 s |

**At 500M lines and 52 trackers.** `--milk-trackers=40 --stock-trackers=12`
adds generated milk blocks and herds past the named ones, each with its own
curve. 500,006,256 lines seeded in 172 s (3.3 GB of Parquet, 65 files), about
125M of them inside FY2027. MinIO, web container stopped, fresh process per
cold run, 3 cold runs per variant:

| request | cold | warm (6 runs, one process) |
|---|---|---|
| default (tracker blocks, EOY excluded, Total) | 4.07 / 5.35 / 4.45 s | median 5.27 s, min 3.62 s |
| `--with-overdraft` | 3.99 / 4.23 / 4.27 s | median 5.33 s, min 3.91 s |
| `--consolidated` | 3.72 / 3.83 / 3.58 s | median 5.32 s, min 4.46 s |
| `--include-eoy` | 3.89 / 3.75 / 4.11 s | — |

Warm is not faster than cold here: nothing caches the Parquet reads between
runs, so every run re-reads ~125M rows from MinIO. Tracker rows match the 2M
seed to within 0.2% (the per-line noise averages out), and milk income matches
exactly, since it is production x payout rather than a function of volume.

```bash
docker compose exec app-minio php artisan duckdb:cashflow-af:seed --lines=500000000 --milk-trackers=40 --stock-trackers=12
```

The single-writer catalog lock bites here: once the web server has served a
lake page it holds the catalog, and every CLI run fails to attach until
`app-minio` is restarted. Stop it for the benchmark and run each timing with
`docker compose --profile minio run --rm --no-deps app-minio …`.

### From and to instead of a financial year

The report takes `from` and `to` dates (inclusive) in place of Figured's
`period=` year, on the page and as `--from` / `--to` on
`duckdb:cashflow-af:run`. Leaving both out gives the farm's FY2027, so the
default request and the oracle's three passes are unchanged. There is one
column per calendar month the range touches, and a range starting or ending
mid-month gets a short first or last column: the scan stops at the exact
dates, and the column's end date and type are clamped to them.

The whole 500M-line farm in one request, June 2023 to May 2027, is 48
columns and 52 tracker blocks.

### Summing at the scan: 72 s to 1.9 s

The first cut of the whole-farm request took 72.3 s. `EXPLAIN ANALYZE` put
the largest single cost on the `scan` CTE: `AS MATERIALIZED` over the raw
lines held all 500M of them (18.6 s), under a nested-loop join against the
`period` CTE (3.5 s) and a second pass over the type filter (3.0 s).

Two changes, both in `CashFlowActualsForecastSqlBuilder`:

1. **`scan` is summed to one row per (date, account, tracker, tag).** Every
   reader of it — the sections, the GST handler, the overdraft's inner cash
   flow — only sums amounts within those keys, so nothing is lost. On this
   farm the materialised result drops from 500M rows to a few thousand.
2. **The period and horizon are bound straight into the predicate**
   (`CAST($period_from AS DATE)` and friends) instead of joined from the
   `period` CTE, so the filter reaches the table scan rather than running as
   a join over every line. The opening-balance read got the same change.

Measured cold, one fresh process each, web container stopped:

| range | before | after |
|---|---|---|
| whole farm, 2023-06-01 → 2027-05-31 | 72.3 s | 1.92 s |
| whole farm, with overdraft | 71.8 s | 1.80 s |
| FY2027 (default) | 4.97 s | 0.69 s |
| FY2027, with overdraft | 4.53 s | 1.02 s |
| 2026-06-15 → 2026-09-10 | 1.29 s | 0.44 s |

Every report is **byte-identical** before and after, all 52 tracker blocks
included, and the oracle still passes its 687 cells. The earlier timings in
this README predate the change.

On the page the statement is ~1.75 s but the page is ~6.5 s: the four
diagnostic queries it runs afterwards (virtual-journal and source-line
listings, and the in-scope count) still read the raw lines, and the source
listing sorts all 500M by date to show the first 300.

```bash
php artisan duckdb:cashflow-af:run --farm=cfaf-dairy-nz --from=2023-06-01 --to=2027-05-31
```

Against the 16.4 s the same request cost Figured on its real farm — with the
caveat that this farm is synthetic and the handlers are ports, not the real
services — the statement does all three virtual journals, the two scans and
the sections in roughly a tenth of a second. The page's own time is mostly
the four diagnostic queries it runs after the report (virtual-journal and
source-line listings) plus the render.

```bash
docker compose exec app-minio php artisan migrate
docker compose exec app-minio php artisan duckdb:cashflow-af:seed --lines=2000000
docker compose exec app-minio php artisan duckdb:cashflow-af:run             # oracle, 687 cells
docker compose exec app-minio php artisan duckdb:cashflow-af:run --farm=cfaf-dairy-nz --repeats=5 --with-overdraft
open http://localhost:8081/cashflow-actuals-plus-forecast
```

Two schema changes came with it, both additive: `milk_payout_rates` and
`trackers.income_account_id` (the price and account halves of the milk
handler), and `accounts.farm_id`, so a farm's GST, GST-payments and overdraft
system accounts resolve to *its* accounts. The `DataPipelineSqlBuilder` and
`DataPipelineOracle` lookups were scoped to `farm_id IS NULL OR farm_id =
$farm_id` at the same time; the pipeline check still passes all 24 stages.

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

**Verified for AlloyDB Omni, same farms and byte-identical output:**
- The Gross Margin V2 query ported to PostgreSQL 17 with four dialect changes,
  output identical to the lake across four farm/period combinations
- Aggregate pushdown fires on a raw grouping column and not on an expression —
  40,687 ms vs 2,740 ms on the same 500M rows, read off the plan rather than
  inferred from timing
- Column store capacity and coverage measured separately; ~5.8 bytes per row,
  100% coverage of 501M rows at a 4 GB budget
- Two SIGSEGVs, each shutting the instance down because the image ships
  `restart_after_crash=off`

**Not yet verified:**
- **Whether managed AlloyDB on x86 shares Omni's aarch64 instability.** Both
  crashes were on the local container build; the hosted service is a different
  build and this PoC has not touched it
- **AlloyDB under concurrency.** As with the lake, every figure here is one
  query at a time — and with a table-wide memory pool, concurrent reports across
  farms are exactly where contention would show
- **Sorting by date within a partition to enable row-group pruning** — the
  single largest untested lever for narrow report windows. See "Big-farm
  findings" above for the diagnostic and the predicted effect
- Postgres or MySQL as the DuckLake catalog backend (a local DuckDB file remains the default per DuckLake's own PoC guidance)
- Anything at actual data volume — this proves correctness, not performance at scale (though real per-farm row counts gathered separately — Figured's largest farms run ~180K–800K total journal rows — suggest single-farm query volume is not a meaningful performance risk for DuckDB regardless)
- The `non_operating_income`, `non_operating_expenses`, `non_operating_movements`, `equity_movements` and `gst` sections. These are implemented in `CashFlowQuery` from the structure definition, but the oracle scenario has no accounts in them, so their sign handling — the last three are `setInverse(true)` sections — is written-but-unexercised. Deliberately left that way: the PoC's open question is DuckDB's performance and the multi-dimensional VJ problem, not exhaustive section coverage. GST is the one most likely to matter on real data.
- Negative-value display (bracketed) in the viewer — no negatives in the oracle scenario

## Next steps

Phased per the architecture conversation this PoC came out of. **Phases 0
(scaffold), 1 (Cash Flow), 1b (tracker sections) and 3 (overdraft interest)
are done.** Phase 2 has been rescoped (below); Phases 4 and 5 are not started.

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
3. **Phase 2 — Livestock valuation movement.** *Rescoped 2026-09-17 after
   reading the real handler and measuring the real data. The original scope
   is kept below under "What this used to say" because the correction is the
   useful part.*

   Port `NonCashMovementVirtualJournal` — the livestock valuation movement —
   to DuckDB SQL, and show that Figured's per-interval PHP loop collapses
   into **one windowed statement**:

   ```sql
   SUM(signed_qty) OVER (PARTITION BY tracker_id, stock_class_uuid ORDER BY interval)
     * per_head_value                                                    -- closing valuation
     - LAG(...) OVER (PARTITION BY tracker_id, stock_class_uuid ORDER BY interval)
   ```

   The claim is about **latency from PHP object churn**, not volume. Measure
   it at realistic farm size, against the same oracle-parity bar as Phases 1
   and 3.

   **The measurement that forced the rescope.** `stock_transactions` in the
   dev database (current rows only, `_valid_to IS NULL`, not soft-deleted):

   | | |
   |---|---|
   | Total rows, whole database | **13,752** |
   | Farms with any stock | 63 |
   | **Largest single farm** | **5,039 rows** |
   | Mean per farm | 218 |
   | Largest single tracker | 865 |
   | Stock classes per tracker | 6 mean, 35 max |
   | Valuation templates | 557 |

   The largest farm in the database is 5,039 rows — that is the *entire*
   input to one valuation report, not per month. A scan that size is free on
   every engine including the MySQL one it runs on today. Seeding a synthetic
   500M-row livestock farm would have measured an axis that does not exist in
   production — **the same trap Phase 1's volume test fell into**, one layer
   down.

   The real cost is above the query: `getValuationTotal()` runs a full
   `allocateManagementValuationStock()` → `calculate()` cycle per interval per
   template, and `StockQuantitiesCollection::getClosingTotal()` re-filters and
   re-sums the whole collection in PHP once per interval. 557 templates × 84
   intervals is ~47,000 PHP object calculations over 13,752 rows of input —
   **O(intervals × templates), almost independent of row count.**

   **What this used to say, and why it was wrong.** The original entry called
   this "the genuinely hard, multi-dimensional case (account × type × basis ×
   tracking/mob × horizon, self-referential)" and "the thing Mongo cannot
   express, and therefore the real reason the current engine loops in PHP."
   Reading the handler does not support any of that:

   - **Not self-referential.** The generator emits a first difference —
     `$movement = $intervalValue - $previousValue` — which is `LAG`. Nothing
     feeds back into its own input. Phase 3's overdraft interest, already
     built, is the genuinely recursive one.
   - **Not Mongo.** `stock_transactions` is **MySQL**, and the interval
     bucketing is already one `GROUP BY` with a generated `CASE` ladder
     (`StockQuantity::getForPeriod()`). Mongo is not in this path at all.
   - **Not multi-dimensional in the hard sense.** Most of the dimensions are
     conditional routing, not simultaneous grouping — `shouldHandle()` skips
     cash basis, scenarios, budgets and actuals outright. The one genuinely
     awkward dimension is the **horizon split** (local actuals before the
     horizon date, forecast after, in one scan), and `addSplitDateRangeToQuery`
     handles it with an OR of two date+type predicates, which is a plain
     `CASE` in DuckDB.
   - **The valuation maths is `quantity × per-head value`.** No FIFO, no cost
     layers. The scheme classes (`HerdScheme`, `NationalStandardCost`,
     `AusTax`) only run for **EOY tax** valuations — a once-a-year
     user-completed workflow, not a report-time calculation.

   So this phase no longer claims to be the ClickHouse-failure-mode test.
   **Nothing in the PoC currently is**, and that should be stated plainly
   rather than assumed — see the honesty note below.

   ### Built. The latency claim failed; the portability claim is the real one.

   The report is one statement (`ValuationMovementSqlBuilder`), checked against
   `ValuationMovementOracle` — a PHP transliteration of Figured's interval loop
   — by `duckdb:valuation`. Parity holds at every shape tried, including the
   30-year history and the first-month `LAG` NULL edge, and the movements
   telescope to closing minus opening:

   | farm | rows checked | parity | conservation |
   |---|---|---|---|
   | `gm-dairy-farm` (5 trackers, 12 mo) | 60 | pass | pass |
   | `tracker-farm-50` (50 trackers, 12 mo) | 600 | pass | pass |
   | `hero-tracker-farm-50` (50 trackers, 12 mo) | 600 | pass | pass |
   | `hero-tracker-farm-50` (50 trackers, **354 mo**) | 17,700 | pass | pass |

   #### It is not faster, and that is fine

   Measured A/B inside one process, so the numbers are comparable to each other
   (across-run comparisons on this box are not — AlloyDB's column store moves
   the memory baseline by gigabytes):

   | shape | PHP loop | statement, MySQL-attached | statement, DuckDB-native |
   |---|---|---|---|
   | 5 trackers, 12 mo | **2.6 ms** | 5.9 ms (0.4x) | 3.7 ms (0.7x) |
   | 50 trackers, 12 mo | 53.8 ms | 80.8 ms (0.7x) | **14.2 ms (3.8x)** |
   | 50 trackers, 354 mo | 115.3 ms | 184.2 ms (0.6x) | 95.7 ms (1.2x) |

   Through the MySQL connector the statement loses at every shape. Over DuckDB's
   own storage it wins 3.8x on a large farm. The window functions themselves
   cost about 7 ms; everything else is moving rows between engines.

   **A wrong diagnosis, corrected.** The first version of this section blamed a
   "near-fixed ~23 ms connector overhead". It was mostly a **missing predicate
   pushdown**: `farm_id` lives on `trackers`, and the DuckDB MySQL scanner
   cannot push a predicate through a join, so every farm's rows crossed the wire
   and were discarded locally. The tell was that the cost did not move between a
   5-tracker and a 50-tracker farm. Resolving the tracker ids first and pasting
   them in as a redundant `IN` on the scanned table fixes it — 37.6 ms to 5.9 ms,
   **6.4x** — and it is why `ValuationMovementSqlBuilder::trackerPredicate()`
   exists. The fix narrows the gap but does not flip it, so the conclusion holds;
   the reasoning behind it was wrong for a while and the number was too.

   Note the pushdown is a small *loss* on `hero-tracker-farm-50` (0.8x), which
   holds ~18,000 of the table's 25,632 rows. Filtering to 70% of a table saves
   nothing and a 50-id `IN` list costs something. It pays when one farm is a
   small slice of a large table — which is the production shape.

   #### What the phase is actually worth: one definition, several engines

   Latency was never going to justify this — 2.6 ms of PHP on a typical farm is
   not a problem anyone has. The reason to move the rule into SQL is that the
   **same rule then runs wherever the data does**: the app, the warehouse, and
   the data science team's notebooks, instead of three reimplementations of a
   valuation that must agree and cannot be diffed.

   That is a testable claim, and it was tested. `ValuationMovementPgSqlBuilder`
   runs the same logic on AlloyDB, and all three agree exactly:

   ```
   rows: duckdb=60  alloydb=60  php-oracle=60
   PASS: 60/60 rows identical across DuckDB, AlloyDB and the PHP loop
   sum of movements: duckdb=308940.00 alloydb=308940.00  diff=0.000000
   ```

   Diffing the two statements with comments, catalog prefixes and parameter
   style normalised away: **4 hunks differ out of 66 lines, and none of them
   touch the window functions.**

   | # | difference | what it is |
   |---|---|---|
   | 1 | `CAST(m AS DATE)` vs `m::DATE` | cast syntax |
   | 2 | `INTERVAL 1 MONTH` / `AS g(m)` vs `INTERVAL '1 month'` | date spine |
   | 3 | the pushdown subquery | DuckDB-only connector workaround, not logic |
   | 4 | `strftime` vs `to_char` | display format |

   The two clauses that *are* the valuation are byte-identical between the two
   files:

   ```sql
   SUM(...) OVER (PARTITION BY tracker_id ORDER BY month
                  ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW)
   LAG(closing_value) OVER (PARTITION BY tracker_id ORDER BY month_start)
   ```

   BigQuery needs the same three substitutions and no others:
   `UNNEST(GENERATE_DATE_ARRAY(a, b, INTERVAL 1 MONTH))` for the spine,
   `FORMAT_DATE('%Y-%m', d)` for the label, and a subquery alias instead of the
   `AS g(m)` column list. Not yet run against BigQuery — that is an assertion
   from the dialect, not a measurement, and should be marked as such until
   someone runs it.

   **The risk worth naming is drift, not expressibility.** Three hand-maintained
   copies of a rule will diverge. `ValuationMovementPgSqlBuilder` being a
   near-clone of its DuckDB counterpart is the evidence: if this becomes real,
   the SQL wants generating from one source (a dbt model, or a builder with a
   dialect shim), not copying. The cross-engine identity check above is the
   minimum guard, and it should run in CI rather than by hand.

   **Still outstanding:** the HTML report page with the diagnostics the other
   reports carry, and an actual BigQuery run.

4. **Phase 3 — Overdraft interest.** The recurrence is done and at parity;
   the pipeline it sits in is not — see "Re-assessed against `DataPipeline`"
   under the Phase 3 section. Built on the real Cash
   Flow output rather than stubbed values. The genuinely self-referential
   case — interest accrues on a balance that the previous month's interest
   already moved — expressed as one `WITH RECURSIVE` statement on **both**
   DuckDB and AlloyDB, at parity with Figured's oracle, with a conservation
   check across every repayment term. Both engines have their own report page
   and diagnostics. See the overdraft sections above.
5. **Phase 4 — Scale/latency test**, only once 1–3 are correct at small
   scale, on the hardest real report shape (not Cash Flow, which real
   per-farm row counts already suggest isn't a meaningful performance risk).
6. **Phase 5 — The additive derived-facts Parquet layer for BigQuery** /
   practice-wide benchmarking, built on report logic now proven correct on
   the hard case, not just the easy one.

### What this PoC has not tested

Stated plainly because the Phase 2 entry above used to claim otherwise, and
because a PoC that only reports its wins is not worth much to the people
deciding on it.

**No report in this PoC is the ClickHouse-failure-mode test.** The original
Phase 2 framing assumed livestock valuation was that test; reading the handler
showed it is a `LAG` over `quantity × per-head value`. The four reports now
built or scoped — Cash Flow, Gross Margin, overdraft interest, valuation
movement — are all expressible in one statement, and three of them are proven
so. That is a real result, but it is a result about *these* reports, not a
general claim that every Figured derivation collapses into SQL.

If there is a report that genuinely does not, it has not been found yet. Worth
asking Richard directly which one he would nominate, rather than the PoC
picking its own exam questions.

**The data volumes are synthetic where it matters least.** The engines have
been pushed to 1.75B rows on Gross Margin, a shape that real per-farm counts do
not approach. The measured reality — 5,039 stock rows for the largest farm,
and per-farm journal counts already noted above — says the production risk is
latency and concurrency, not volume. The concurrency sweep is the number that
speaks to the real risk; the billion-row numbers mostly establish headroom.

## The MongoDB baseline — what the current stack actually costs

Added so the other three numbers mean something. Before this, the PoC could say
"DuckDB does the Gross Margin report in 62 ms" without being able to say what it
was faster *than*.

The `mongo` profile reproduces Figured's topology rather than porting the SQL:

- **Journals in MongoDB.** One document per line, indexed `(farm_id, basis, date)`.
- **Dimensions in MySQL.** Accounts, trackers, milk production, stock movements —
  the same tables the lake profiles read, in the same database.
- **PHP in between**, because no query can span the two.

It shares MySQL with the `gcs` and `minio` profiles deliberately. Giving it a
private copy would have quietly removed the constraint under test.

### The Mongo half is smaller than people assume

`MongoJournalQuery` mirrors `QueryMongoReportDataService`: the period is
partitioned by budget type and one aggregation is issued per non-empty bucket —
**at most four for a whole report, independent of tracker count**. The pipeline
is two stages, `$match` then `$group`, and it returns
`(account_id, month) → sum` with no names, no ordering and no structure.

No `$lookup`, because `accounts` is in another database. No `$setWindowFields`,
because the stock movements it would run over are in that same other database.
Mongo is a sum-by-key engine here, which is all this topology lets it be — and
worth being precise about, because "Mongo can't do window functions" is false
(it has had them since 5.0) while "the data isn't there to window over" is true.

### Everything else moved into PHP

| Step | DuckDB / AlloyDB | Baseline |
|---|---|---|
| Attach account names | `JOIN account_scope` | `classify()` |
| Account / group / section subtotals | `GROUPING SETS` | `rollUp()` |
| Gross margin line | derived CTE | `marginRows()` |
| Livestock running balance | `SUM(...) OVER (...)` | `stockUnits()` |
| Per-unit margin | projection | `emit()` |

Output is **identical to AlloyDB cell for cell**, verified at both scales
(22 rows × 12 months on the demo farm, 22 × 48 on the 1M farm). AlloyDB was
already verified against the lake, so all three agree transitively.

### Measured, `gm-dairy-farm-1m`, 2024-01-01 → 2027-12-31, horizon 2026-08-31

999,980 journal lines in scope, same farm and period on both engines:

| | MongoDB baseline | AlloyDB |
|---|---|---|
| Report time | **1,346 ms** | **62 ms** |
| ↳ Mongo | 1,341 ms (99.7%) | — |
| ↳ MySQL | 2.1 ms (0.2%) | — |
| ↳ PHP | 2.0 ms (0.2%) | — |
| Load time | 5.3 s (190k docs/sec) | ~1 s |
| Storage | 253 MB + 20 MB index | — |
| Bytes per journal line | ~253 B | — |

**21× on the same data and the same hardware.**

### The result that corrects the hypothesis

Going in, the expectation was that PHP would dominate — that the assembly layer,
not Mongo, was the ceiling. On this report it does not: PHP is 0.2% of the run,
because Mongo returns only ~600 grouped rows however many lines it aggregated.
The roll-up, the stock chain and the sort are all O(accounts × months).

So for Gross Margin V2, **the engine is the constraint**, and the 21× is real.

The honest caveat, which belongs next to that number: this reproduces *this
report's* PHP half, not Figured's whole pipeline. Two real costs are absent —

- **Virtual journals.** Figured synthesises journal lines at report time from
  business rules (`VirtualJournalsService`, tracked as `report_vj_duration`). No
  engine in this PoC does that work, so no stack here is charged for it.
- **Per-tracker assembly.** V2 keeps query count independent of tracker count,
  but tracker count still multiplies in-process section assignment and
  formatting. This farm has five trackers; a 50-tracker farm would not scale the
  Mongo half at all and would scale the PHP half linearly.

Both push in the same direction: the real Figured report spends a larger share
outside the database than this baseline does. `report_mongo_duration` ÷
`report_duration` from production Prometheus is the number that settles it, and
it costs nothing to pull.

### The scaling curve — and where the wall actually is

| Farm | Lines | Naive pipeline | Per million | Covered pipeline | Per million |
|---|---|---|---|---|---|
| `gm-dairy-farm-1m` | 999,980 | 1,346 ms | 1.35 s | **975 ms** | 0.98 s |
| `gm-dairy-farm-10m` | 9,999,980 | 14,717 ms | 1.47 s | **9,640 ms** | 0.96 s |
| `mongo-gm-dairy-farm-25m` | 24,999,596 | 40,666 ms | 1.63 s | **23,772 ms** | 0.95 s |

PHP stayed at ~2.5 ms at all three volumes and in both pipelines. Every
millisecond of the growth is Mongo.

The **covered pipeline is what the code now ships** — see "Giving Mongo its best
shot" below. It is 1.4-1.7x faster, and the improvement widens with volume
because it removes the cause of the degradation rather than just the constant:
per-million cost goes from rising (1.35 -> 1.63) to flat (0.98 -> 0.95).

**It is CPU-bound on one core, not IO-bound.** Sampled during the 25M
aggregation: **101% CPU on a 16-core machine**, and container block reads flat
at 1.07 GB for the whole scan. MongoDB runs a pipeline on an unsharded
collection single-threaded, so a report gets one core no matter how large the
box. DuckDB spends 16 threads on the same work and AlloyDB uses parallel
workers over a columnar scan — which is most of the 21x.

The other half is that Mongo must materialise and decode all ~253 bytes of each
document to read the two fields the pipeline sums. There is no projection
pushdown to storage and no columnar path, so the cost scales with document size
rather than with the number of fields actually used.

**The expected cache cliff did not appear, and the reason matters.** At 25M the
collection is 9.3 GB against a 4 GB WiredTiger cache, so the cache is missing
constantly — yet degradation was only 21% per million from 1M to 25M. Block IO
says why: nothing was read from disk. The whole 9.3 GB collection fits in the
16.8 GB Docker VM's page cache, so a WiredTiger miss is a memcpy and a
decompress, not a seek.

So this measured the **cache-miss-but-RAM-resident** regime, not the disk
regime. A genuine cliff needs a collection larger than host RAM — beyond roughly
65M documents here. That test has not been run.

The practical consequence cuts the other way from the usual intuition: because
the bottleneck is single-threaded BSON decode rather than IO, **more RAM does
not help and more cores do not help a single report**. Extrapolating the
measured line at 1.63 s/million, and it is still rising:

| | extrapolated Mongo | measured AlloyDB | measured lake |
|---|---|---|---|
| 500M | ~8 min | — | — |
| 1B | ~16 min | 3.3 s | 3.9 s |

The interactive threshold — 5 s for a page — lands at about **5M journal
lines**, before virtual journals are charged for at all.

### Giving Mongo its best shot

Before quoting any of the numbers above as "Mongo's ceiling", the pipeline was
tuned. Measured on the 25M farm, actuals bucket, medians of four runs:

| variant | median |
|---|---|
| naive — fetching scan, `$in`, `$dateToString` | 27,546 ms |
| `$year`/`$month` instead of `$dateToString` | 25,473 ms |
| drop the date bucketing entirely (floor) | 23,461 ms |
| `$match` + `count` only, no field access | 16,368 ms |
| **covered scan + `$project` + `$year`/`$month`** | **15,364 ms** |
| covered, and also dropping the `$in` | 13,590 ms |

Two things fall out of that table.

**Expression tuning is nearly pointless.** Swapping `$dateToString` for
`$year`/`$month` buys 7%, and removing the date bucketing *altogether* buys only
15%. A pipeline that does nothing but match and count still costs 16.4 s. The
cost is walking documents, not computing over them.

**Not fetching the documents is the whole game.** The `covering_gm` index
carries every field the pipeline touches, so the scan is answered from index
keys — `PROJECTION_COVERED <- IXSCAN`, `docsExamined: 0`. That halves the
fetching scan's 26.0 s. It works because the report reads six fields out of a
~253-byte document; Mongo otherwise materialises all of it to reach them.

It also explains why per-million cost stopped degrading. The naive scan pushes
9.3 GB of documents past a 4 GB WiredTiger cache; the covered scan touches only
a 0.18 GB index, which stays resident at any volume this PoC reaches.

Three caveats that stop this being a free lunch:

- **The index is report-shaped.** It covers Gross Margin V2's six fields. A
  report needing a seventh field falls back to fetching, silently. Figured runs
  many report types, so this is one index per report shape, each of which has to
  be maintained on every write.
- **It does not change the asymptote.** Still single-threaded, still ~0.95 s per
  million, still one core out of sixteen. It moves the wall from ~3.5M lines to
  ~5M, it does not remove it.
- **The `$in` was left in place.** Dropping it buys a further 13%, and
  `classify()` already discards out-of-scope accounts so the output would be
  identical — but Figured's real query does filter by account, and a baseline
  that quietly removes work the real system does is not a baseline.

### Why loading is slow, and why that is a real finding

The Postgres and DuckDB seeders generate rows *inside* the engine from
`generate_series`, so nothing crosses a client connection. Mongo has no
server-side generator, so every document is pushed from PHP — 190k docs/sec
here. That is a property of the topology, not a handicap imposed on it, and it
is the same reason a full reload of production data is a very different
proposition on each of these stacks.

## The non-aggregated farm — what the chaff actually costs

Every other farm in this PoC is unrealistically pure: 100% of its journal lines
are accounts the Gross Margin report sums. Measured on Figured's own seeded
dairy farm, only **27.7%** are. The rest are Accounts Payable, Farm Current
Account and GST — lines the report reads and immediately discards, because
double-entry means one expense line drags a payable, a GST and a bank line
along behind it.

So the engines here had been handed 3.5x more relevant data per row than the
system they were being compared against. `gm-dairy-farm-500M-non-aggregated`
removes that advantage.

### The farm

```bash
docker compose --profile minio exec app-minio \
    php artisan duckdb:gm2:seed --raw --rows=500000000
```

`--rows` counts **report** lines, so this is the same report-relevant volume as
`gm-dairy-farm-500m` with the bookkeeping lines added on top:

| | rows | report lines | ratio |
|---|---|---|---|
| `gm-dairy-farm-500m` | 499,999,724 | 499,999,724 | 100% |
| `gm-dairy-farm-500M-non-aggregated` | 1,749,999,212 | ~500M | **28.6%** |

28.6% against Figured's measured 27.7%. Seeded in 790 s.

### The result

Same period, same horizon, three cold runs each:

| farm | median | output |
|---|---|---|
| `gm-dairy-farm-500m` | 5,290 ms | 996 cells |
| `gm-dairy-farm-500M-non-aggregated` | **9,846 ms** | 996 cells, **identical** |

The reports match cell for cell, because the bookkeeping accounts carry no
`report_group` and `account_scope` excludes them. Same answer, same useful
rows, 3.5x the rows scanned to reach it — which is what makes this an A/B on
chaff rather than on data.

**Chaff costs 1.86x for 3.5x the rows.** Sub-linear, but not free, and not the
"nearly free" this PoC predicted before measuring.

### Why it is not free, and how it could be

The scan still reads `account_id`, `date`, `basis`, `type` and `farm_id` for
every one of the 1.75B rows in order to decide what to discard. Only `amount`
is spared. Columnar storage means unread columns cost nothing — but the columns
the predicate needs are not unread.

Row-group skipping does not help either, because the chaff is **interleaved**
with the report lines: the generator emits a report line and its legs together,
so every row group holds a mix and none can be skipped wholesale. Sorting the
partition by `account_id` would make row groups homogeneous and should collapse
most of the 1.86x. That is untested, and belongs with the other unexplored
ordering work noted under row group size.

### What this changes

The headline comparison, restated on consistent data. Figured V2's measured
volumes are total lines including chaff, so the PoC's had to be too:

| basis | Figured V2 | DuckDB + DuckLake | ratio |
|---|---|---|---|
| total lines scanned | 25.0M in 10,182 ms | 1.75B in 9,846 ms | **~70x** |
| report-relevant lines | ~6.9M | ~500M | **~72x** |

Both framings agree, which the earlier comparison could not claim: it put
chaff-free PoC data against Figured's chaff-laden data and reported ~90x. The
honest figure is **~70x**, and it is now apples to apples whichever way the
lines are counted.

## Nested vs flat — what Figured's document shape costs a columnar engine

Figured stores one document per TRANSACTION with a nested `lines[]` array: an
invoice is one document carrying its expense line, its GST line and its payable
line. Every table in this PoC stores one row per LINE. That difference was
assumed immaterial and never tested — "there is no array to unwind, so the step
is inapplicable rather than skipped".

That assumption was worth checking, because the report's filtering column
(`account_id`) lives INSIDE the nesting. Nothing can be skipped until the list
is expanded, and `UNNEST` is the relational `$unwind`.

`gm-dairy-farm-500M-non-aggregated` now exists in both layouts, holding
**exactly the same 1,749,999,212 lines**:

| layout | rows scanned | Parquet | fact aggregation |
|---|---|---|---|
| `transaction_lines` (flat) | 1,749,999,212 rows | 17,311 MB | **11,277 ms** |
| `transactions` (nested) | 499,999,724 txns x 3.5 lines | **9,671 MB** | **22,495 ms** |

Aggregates identical, 812 groups either way. Three cold runs each.

### The trade

**Nesting halves the storage and doubles the query.** 44% smaller on disk,
1.99x slower to read.

Both halves have the same cause. Packing 3.5 lines into one row amortises the
per-row overhead — the transaction's farm, basis, date and type are stored once
instead of 3.5 times, and Parquet's dictionary encoding does the rest. But the
same packing puts `account_id` behind a list indirection, so the scan has to
materialise 1.75B struct values out of 500M list values before it can decide
what to discard.

That is the same trade Mongo makes, which is why the earlier Mongo numbers
looked the way they did: Figured's nested documents buy a density advantage of
roughly 2.3x and give most of it back expanding lines the report throws away.

### What it means for a migration

**Normalise on the way in.** Nothing forces a lake to copy Mongo's shape —
`lines[]` exists because Mongo is a document store, not because the data is
naturally nested. Writing one row per journal line during migration is trivial
and worth 2x on every report afterwards, at the price of 79% more Parquet.

So the flat layout everywhere else in this PoC is not a benchmark convenience.
It is the correct design choice, and this is the measurement that says so
rather than assuming it.

The nested table is kept because the question will be asked again, and because
a lift-and-shift migration that preserved the document shape would land on the
22,495 ms column rather than the 11,277 ms one.

## Making the chaff free — clustering by account

The non-aggregated farm showed bookkeeping lines costing 1.86x. That penalty
turns out to be entirely avoidable, and the fix is in the data layout rather
than the query.

### Two levers, and neither works alone

Measured on the fact aggregation over 1.75B rows:

| | filter after scan | filter inside scan |
|---|---|---|
| **interleaved** | 7,792 ms | 6,460 ms |
| **clustered by account** | 7,699 ms | **2,835 ms** |

Clustering on its own is worth **nothing** — 7,699 against 7,792 is noise.
Sorting gives Parquet's row-group statistics something meaningful to describe,
but if the query never names `account_id` in the scan there is no predicate to
test them against: the data is prunable and nothing prunes it.

The filter on its own is worth 17%. It avoids hash-aggregating rows it will
discard, but still has to READ every one, because an interleaved row group
always contains some report accounts and can never be skipped.

Together they are worth 2.28x. The filter asks the question; the clustering
makes the answer "no" for most row groups, which are then never read.

**The lake's report already had the filter** — `factScopePredicate()` has always
carried `tl.account_id IN (SELECT account_id FROM account_scope)`. It is the
AlloyDB builder that moves it out, because there a semi-join in the scan blocks
columnar pushdown (40,687 ms against 2,740 ms). So the two builders diverge
here on purpose, and the lake needed no query change at all.

### The full report

Same period and horizon, three cold runs, output identical in every case:

| farm | rows held | report |
|---|---|---|
| `gm-dairy-farm-500m` (no chaff) | 500M | 4,106 ms |
| `gm-dairy-farm-500M-non-aggregated` | 1.75B | 7,390 ms |
| `gm-dairy-farm-500M-non-aggregated-sorted` | 1.75B | **4,101 ms** |

**1.80x, and exact parity with the farm that has no bookkeeping lines at all.**
1.25 billion rows are sitting in the data and costing nothing, because the scan
never touches them.

### How the clustering is produced

Not with `ORDER BY`. Sorting the cross join has to order 36.5M rows per
statement at this scale and was OOM-killed — silently, because the command was
piped through `tail` and the pipeline returned tail's exit code. Only the 44
milk rows landed and the seed reported success.

Emitting each leg as its own INSERT gives the same physical clustering for
free: every row a statement writes belongs to one account, so no sort is
needed. Write cost is 834 s against 790 s, a 5.5% penalty on a one-time load.

### What this means

The chaff finding still stands — a farm with realistic bookkeeping lines holds
3.5x the rows. What changed is that those rows no longer have to be read, so
they stop being a tax on every report.

It also sharpens the migration advice: **cluster the fact table by account on
the way in.** Alongside normalising out Mongo's nesting, that is the second
layout decision worth making deliberately rather than inheriting.

Two things left open. Whether per-leg inserts fragment partitions into more
files is unmeasured — `ducklake_table_info` reports one row per table, not per
file, so the check that was run proved nothing, and request count rather than
bytes is what drives lake cost. And clustered data appears to compress better
(the table grew ~8.2 GB for 1.75B clustered rows against a ~5.3 bytes/row
average before), which is an inference from a delta rather than a measurement.

## Phase 3 — Overdraft interest

Done, and it is the first report here that a window function cannot express.

Every other report in this PoC is a projection or a prefix sum. Overdraft
interest is a genuine **recurrence**: the cash balance it charges against
excludes interest, so the running total of interest already accrued has to be
subtracted to find the true overdrawn position.

```
P(n)   = closing(n) - cum(n-1)
cum(n) = cum(n-1) + (P(n) < 0 ? -P(n) * rate : 0)
```

Month N's interest raises month N+1's charge base, and the `P(n) < 0` branch
depends on `cum` itself — so `WITH RECURSIVE` is the only way to write it.
Figured does the same thing imperatively, with `$interestRunningTotal` carried
across a `foreach`.

### Parity

`php artisan duckdb:overdraft` seeds `overdraft-oracle-farm` and checks the
result against Figured's own committed tests (`OverdraftTest`,
`OverdraftInterestTest`). The scenario is theirs: one $1,000 expense, nothing
afterwards, so the closing balance sits flat at -$1,000 for twelve months. Flat
is the point — any growth in the charge can only be interest compounding on
itself.

At 5% annual the accrual matches **cell for cell**:

```
41666, 41840, 42014, 42189, 42365, 42541,
42719, 42897, 43075, 43255, 43435, 43616
```

Summing to 511,612, which is the other half of Figured's pinned final balance
of -10,511,612.

`--all-terms` checks the distribution. Interest accrues every month whatever
the term, so the posted amounts must always sum to the accrued amounts:

| term | posts in months |
|---|---|
| monthly | 1–12 |
| bi-monthly | 2,4,6,8,10,12 |
| quarterly | 3,6,9,12 |
| semi-annual | 6,12 |
| annual | 12 |

All conserved.

### Three things worth recording

**DOUBLE is enough.** Figured's arithmetic is bcmath at scale 14, truncating,
and DuckDB's doubles diverge from it around the tenth significant digit
(43616.67626065 against 43616.67626058). It does not matter: only the *posted*
amount is truncated to an integer, and the divergence is far below one unit.
No DECIMAL gymnastics were needed, which was not obvious going in.

**The repayment calendar has an off-by-one that looks right.** Figured builds it
as `(start_month + i - 1) mod 12` for i = step, 2*step, …, 12. Drop the `- 1`
and a quarterly overdraft starting in April posts in July rather than June —
still four evenly spaced months, still plausible, still wrong. The four cases
pinned by `OverdraftRepaymentMonthTest` are the check.

**Conservation caught a bug that per-month assertions would not.** The bucket
index came from a window over *preceding* rows, which returns NULL rather than
0 for the first month. Month one then partitioned on its own and its accrual
never reached the posting month that should have carried it. Every month that
did post still looked correct; only the total gave it away.

### Known gaps

- **GST.** The movement CTE treats GST as an ordinary account, where the Cash
  Flow report inverts the whole GST section. The oracle carries no GST lines, so
  this is untested either way.
- **The split (horizon) interval.** Figured skips the interval whose type is
  `actualsForecast` entirely. The PoC's month grid has no such interval, so
  there is nothing to skip — but a period straddling the horizon on a monthly
  grid needs checking against `testBiMonthlyOverdraftWithSplitHorizon`.
- **Scenarios, multi-farm, reporting groups.** Out of scope here; the
  `overdrafts` table deliberately omits `budget_id` and `budget_type`.
- **The interest is not fed back into the Cash Flow report.** Figured emits
  virtual journals that land on the closing balance, the P&L and the balance
  sheet. This computes the number; wiring it back is the remaining work.

### Stress testing it — and a limit on the clustering win

Two axes, and the interesting one turns out not to matter.

**The recursion scales linearly and is negligible.** Widening the period on the
oracle farm, which needs no extra data at all:

| years | months | median |
|---|---|---|
| 1 | 12 | 23 ms |
| 10 | 120 | 55 ms |
| 30 | 360 | 121 ms |
| 100 | 1,200 | 370 ms |
| 250 | 3,000 | 1,023 ms |

About **0.33 ms per month** on top of ~19 ms fixed. A recursive CTE is a
sequential loop — it cannot parallelise the way every other report here does
across row groups — so this was the cost worth being suspicious of. At any
period a farmer would actually ask for it is irrelevant: a ten-year report
spends 55 ms in the recursion.

**Volume is the whole cost.** One overdraft config row and a negative opening
balance turn any existing farm into an overdrawn one, so this needed no seeding
either:

| farm | lines scanned | median |
|---|---|---|
| oracle | 1 | 32 ms |
| `gm-dairy-farm-1m` | 999,980 | 44 ms |
| `gm-dairy-farm-500m` | 499,999,724 | 3,825 ms |
| `…-non-aggregated-sorted` | 1,749,999,212 | 13,464 ms |

At 48 months the recursion is ~16 ms of that 13,464 — **0.1%**. The report is an
ordinary cash-flow scan with a small loop on the end.

**Clustering by account does nothing for this report.** Same two farms, same
1.75B rows, same period:

| report | interleaved | clustered | |
|---|---|---|---|
| Gross Margin V2 | 7,390 ms | 4,101 ms | **1.80x** |
| Overdraft interest | 12,795 ms | 12,604 ms | 1.02x — noise |

Gross Margin filters to the 14 accounts carrying a `report_group`, so most row
groups can be skipped on statistics. Overdraft needs the **whole cash
position** — every account, including the payables, bank and GST lines Gross
Margin discards — so there is no predicate to prune with and the scan reads
everything.

That is a real limit on the clustering result, and it generalises: a physical
layout is tuned to one report's predicate, and a table has only one physical
order. Clustering by something that helped overdraft would give back the Gross
Margin win. Layout is a per-workload trade, not a table-wide improvement.

**Still untested:** many farms in one statement. The SQL is single-farm; a
practice-wide run would join the recursive term on `(farm_id, n)` so N
independent recurrences advance together. That should be cheaper per farm than
looping — 120 iterations of N rows rather than 120×N iterations — but nobody has
measured it, and it is the shape Phase 5 needs.

### Phase 3 on AlloyDB

The same report ported to PostgreSQL 17, at the same parity, on
`/alloydb/overdraft`. `php artisan alloydb:overdraft` checks it, and
`--all-terms` checks the distribution.

**The recursion ported unchanged.** `WITH RECURSIVE`, `LATERAL` and window
frames are standard and needed no translation at all. What differed was
cosmetic, and the same list the Gross Margin port produced:

| DuckDB | PostgreSQL |
|---|---|
| `strftime(x, '%Y-%m')` | `to_char(x, 'YYYY-MM')` |
| `month(x)` | `EXTRACT(MONTH FROM x)::INT` |
| `INTERVAL 1 MONTH` | `INTERVAL '1 month'` |
| `range(0, n)` | `generate_series` |
| `DOUBLE` | `DOUBLE PRECISION` |
| `$param` | `:param` |

Two schema additions were needed: an `overdrafts` table, and an
`opening_balance` column on `farms`. The lake has carried the latter since
Phase 1; AlloyDB had no use for it until a report charged interest against the
bank position.

**One deliberate difference from the Gross Margin port.** That one moves the
account filter out of the scan, because a semi-join there blocks columnar
pushdown. This one has no account filter to move: overdraft reads the whole
cash position, so there is nothing selective to push down and nothing for the
columnar engine to prune — the same reason clustering by account was measured
at 1.80x for Gross Margin and 1.02x here.

`by_day` still groups on the raw `date` column and rolls up to months
afterwards, because grouping on `date_trunc()` is a function call on the
grouping key and moves the whole aggregate above the scan. That rule applies
whatever the report.

### Re-assessed against `DataPipeline` — 2026-09-27

Richard's feedback on the result above, verbatim from Slack:

> i think the thing more than pure speed is to look at
> `/src/Figured/Packages/Core/Reporting/Pipes/DataPipeline` rather than any
> specific implementation, it sort of nicely lays out all the business logic
> … and the logic is ordering dependent … some of those steps are data steps
> of course (like the merging / preparing arrays) but others are logic — ie
> adding GST Payments / Refunds, contra accounts, current year earning
> calculations, opening balances yadda yadda. and of course the consolidated
> account handling between entities which is a pickle

He is right, and the gap is larger than a missing feature or two. **What
Phase 3 ported is pipe 8 of 25.** `DataPipelineService::$pipes` is an ordered
list, and `MergeVirtualJournals` — the step that pulls the overdraft handler's
output into the report — sits eighth. Seventeen pipes run after it, and every
item Richard named lives in one of them. The PoC took the hard input as given:
it computes the closing balance the interest compounds on as
`SUM(-tl.amount)` over cash lines, validated against a farm holding **one
transaction line**.

The real structure also nests, which nothing here reproduces:

```
DataPipeline (outer report, 25 pipes)
  └─ pipe 8  MergeVirtualJournals
       └─ OverdraftVirtualJournals            priority 150, both bases
            └─ OverdraftCalculationService
                 └─ CashflowReport            a full sub-report, cash basis,
                      └─ DataPipeline         tracking=CONSOLIDATED, tags dropped,
                                              excludeEoyJournals=true — all 25 again
```

`OverdraftVirtualJournals` carries `private static bool $running` to stop
that recursion. The interest is charged on the **closing** row of the inner
report's `OpeningClosingBalance` dynamic section, and that inner report's net
cash movement is `income - expense + gst` with GST its own inverted section and
**bank accounts captured into a hidden section and excluded** — the PoC sums
every line, bank included, and treats GST as an ordinary account (its own
comment says so).

#### The 25 pipes, in order, against what the PoC does

Read from `DataPipelineService.php` and each pipe's `shouldHandle()`. "Gate"
is the report option or state that turns the pipe on. *Data* pipes shape the
Mongo query and result arrays; *logic* pipes change numbers.

| # | pipe | kind | gate | what it does | PoC |
|---|---|---|---|---|---|
| 1 | `PrepareEmptyArray` | data | always | zero cell per account × interval | the month spine |
| 2 | `BuildAggregationPipeline` | data | always | the Mongo `$match`/`$group`; ytd start, basis, tags, `excludeEoyJournals` (MYOB only: `$nin` tag) | `p02_scan` — horizon split, YTD widening, EOY tag `$nin` |
| 3 | `UpdatePipelineForV3MultiFarmTrackers` | data | mf trackers | maps tracker ids onto multi-farm tracking options | n/a — one tracking dimension |
| 4 | `AddMappedAccountsToPipeline` | data | always | adds internal Figured accounts mapped to Xero accounts to the query | `report_accounts` includes mapped targets |
| 5 | `CheckMaxNesting` | data | always | guards Mongo query depth | n/a |
| 6 | `QueryMongo` | data | always | runs it | the scan |
| 7 | `AddResultsToEmptyArray` | data | always | fills the cells | the `GROUP BY` |
| 8 | `MergeVirtualJournals` | data | VJs present | adds VJ amounts by account/interval/basis/tracking; **overdraft (150) and GST payments/refunds (145) arrive here** | `p08_merge_vj` — GST handler + overdraft recurrence, each over its nested report |
| 9 | `AddOpeningBudgetBankBalance` | logic | budget, budget_id 0 | opening bank → default bank account, contra → retained earnings, first interval of each FY; value from `Balance::getReport()` | `p09_opening_bank` from `opening_balances` (budget) |
| 10 | `AddOpeningBudgetGstBalance` | logic | budget, ytd, `includeOpeningBudgetGst`, GST/RE rows present | opening GST → GST account (inverse), contra → retained earnings | `p10_opening_gst` |
| 11 | `ReportingGroupOffsetAccounts` | logic | reporting group, `mergedAccounts` | inter-entity transfers: adds the *from* entity's account balances (a nested `AccountBalances` run per source farm) onto the *to* account, zeroes the *from* — including its mapped alias | **pass-through — next tranche** |
| 12 | `AddGstPaymentsRefunds` | logic | **hard-disabled** — `return false;` since `d43fc7ab0d2` / `3c1c742f797` (FIG-16282, Feb–Mar 2024) | predicted GST payments/refunds line; actual payments removed from net GST | dead in Figured; logic now lives in `GstPaymentsRefundsVirtualJournal` at pipe 8 |
| 13 | `MergeMappedResults` | logic | always | folds each internal Figured account's cells into its Xero account and drops the internal row; includes adopted accounts | `p13_merge_mapped` (adopted accounts not modelled) |
| 14 | `CurrentYearEarnings` | logic | `calculateCurrentYearEarnings` | runs a **nested YTD sub-report** (`allincome - allexpenses`) and copies the inverted total into the CYE equity account | `p14_cye` — nested YTD scan |
| 15 | `RetainedEarnings` | logic | RE account present, `calculateRetained` | runs a **nested yearly sub-report** of net profit for every season since the farm's first transaction (a Mongo `findOne` sorted by `accrual_date`; 1990 for reporting groups), sums seasons before each interval's FY into a running RE line, resets at season start; plus opening-balance RE from MYOB | `p15_retained` — nested season scan (MYOB opening not modelled) |
| 16 | `FixYearToDateValues` | logic | `ytd`, not offsets sub-run | running sum per account across intervals; resets at season start for `ytd_type=season`; skips RE and CYE | `p16_ytd` |
| 17 | `ContraGstPaymentsRefunds` | logic | **hard-disabled**, same commits | contra the GST payments line against the bank | dead; see 12 |
| 18 | `ShowExpectedSign` | logic | `showExpectedSign` | flips accounts the user views inverted | `p18_expected_sign` from `accounts.inverted_for_user` |
| 19 | `InverseAmounts` | logic | `inverse` | multiplies every cell by −1 | `p19_inverse` |
| 20 | `ReportingGroupConsolidateAccounts` | logic | reporting group, `consolidateAccounts` | moves each `old_account_id`'s cells onto `new_account_id`, summing when several map to one | **pass-through — next tranche** |
| 21 | `DynamicBankBalance` | logic | `dynamicBankAccount`, liability account exists, not reporting group | a negative default-bank cell moves to the Figured liability account (US lines of credit) | `p21_dynamic_bank` |
| 22 | `HideEmpty` | data | `hideEmpty` | flags all-zero rows | n/a |
| 23 | `HideEmptyAccounts` | data | `hideEmptyAccounts` | flags all-zero internal/mapped/typed rows | n/a |
| 24 | `FormatCells` | data | always, **last** | deflates ×10,000 | the `/ 10000.0` |

(`DataPipe`, `BaseDataPipe`, `FlatTransactionsClass` are plumbing, not
stages.)

Two of Richard's five items — GST payments/refunds and contra accounts — are
**no longer pipeline pipes at all**. Both `shouldHandle()` methods open with an
unconditional `return false;`, dated to the FIG-16282 commits that moved the
logic into `GstPaymentsRefundsVirtualJournal`. The business rule is alive; it
just arrives at pipe 8 as journals rather than at pipes 12 and 17 as array
edits. That matters for a port: GST payments/refunds must be produced *before*
the merge, not after it, and `MergeVirtualJournals` filters by basis, date
window and tracking, so the journals have to carry all three correctly to be
counted.

#### What "opening balance" actually is

The PoC's is one integer on `farms`. Figured's, for a budget period
(`Balance::getReport()`), is

```
opening_bank(FY)                    a per-FY user Variable; summed over every
                                    tracking entity when the farm is consolidated
  + bank account transactions       CurrentBalance over every BANK-type account,
                                    season start → day before the period
  + depreciation balance            cash basis only, DEPRECIATION-type accounts
  + GST value                       GstCalculator over the same window
  - opening GST(FY)                 another per-FY Variable, per entity
```

and for an actuals/forecast period it is a whole `AccountBalances` sub-report
over every bank account, ending the day before the period, split around the
horizon. Five terms and two nested reports where the PoC has a column.

#### What the numbers say

Only **one** farm has an overdraft configured — `overdraft-oracle-farm`, one
transaction line — so the overdraft statement has never run against
realistic data. Applying its movement expression to a realistic chaff farm
(`gm-dairy-farm-500M-non-aggregated-sorted`, cash, 2024, 437M lines):

| | |
|---|---|
| PoC net cash movement, all lines | **2,203,397.59** |
| report-account legs only | **2,235,559.03** |
| bank balancing legs | −29,861.09 |
| GST legs | −2,300.35 |

A 1.4% error in the base the recursion compounds on, every month, for the
horizon. The `accounts` table already carries `is_gst_account` and
`is_default_bank_account`; `OverdraftSqlBuilder` reads neither.

#### The honest summary

Phase 3 proved that a self-referential monthly recurrence fits one
`WITH RECURSIVE` statement at parity with Figured's pinned series. That stands.
It did not prove the thing Richard is asking about, which is whether an
**ordering-dependent 25-stage pipeline** — with three nested sub-reports
inside it — can be expressed as composable SQL at all. That is the
ClickHouse-failure-mode test the "What this PoC has not tested" section
admits nothing here currently is. It is now the work below.

#### The port — `duckdb:pipeline`

Scope, agreed 2026-09-27: exercise the whole pipeline on DuckDB/MinIO first,
one CTE per pipe in `$pipes` order, gated the way Figured gates them, and
check parity **after every stage** against a PHP transliteration of the same
pipe, so an ordering mistake is caught at the stage that made it rather than
in the final total. The seed farm has to carry every input the pipes read:
a GST account and NZ two-monthly settings, a default bank and a second bank,
a depreciation account, retained-earnings and current-year-earnings system
accounts, an EOY-tagged journal, an internal account mapped to a Xero one,
two entities in a reporting group with a merged (offset) account and a
consolidated account, opening bank and GST balances per FY, and an overdraft.
Progress is recorded in the pipe table above as stages land.

#### First tranche — landed 2026-09-27

`php artisan duckdb:pipeline --seed --all` seeds `pipeline-oracle-farm` (97
lines: NZ, FY ending June, GST two-monthly on a payments basis, an actuals /
forecast horizon at 31 December, and an input for every pipe) and checks
`DataPipelineSqlBuilder` against `DataPipelineOracle` — a PHP transliteration
of the same pipes — **after each of the 24 stages**, in three configurations:

| run | gates on | stages passing |
|---|---|---|
| `duckdb:pipeline` | none | **24 / 24** |
| `duckdb:pipeline --all` | ytd, excludeEoy, openingGst, cye, retained, expectedSign, dynamicBank | **24 / 24** |
| `duckdb:pipeline --type=budget --all --ytd-type=season` | as above, budget path (pipes 9 and 10 live) | **24 / 24** |

Per-stage checking is the point: an ordering-dependent pipeline can be wrong
at stage 9 and right again by stage 24 if two mistakes cancel, and the
transliteration keeps a snapshot after every pipe so the diff lands on the
pipe that made it. Pipes 2–6 are the scan and are diffed as cells the way
pipe 7 will bucket them.

What the final table shows on the `--all` run, each visibly the work of one
pipe:

- the internal fertiliser account is gone and the Xero fertiliser line carries
  3,000 + 400 a month — pipe 13;
- June wages are 144,000, not 153,000: the $9,000 EOY adjustment is excluded
  — pipe 2's tag predicate;
- each actual IRD settlement is off the net GST line and on the payments line,
  and the predicted payments net off the settlements already made in their
  window — the GST handler at pipe 8;
- **overdraft interest posts January to June — $524.63 falling to $223.07 as
  the balance climbs back out** — the recurrence at pipe 8, over an inner
  cash flow with bank excluded and GST inverted, which the outer report then
  carries forward. This is the row Phase 3 could never populate on a
  one-line farm;
- the term loan shows sign-flipped — pipe 18;
- a negative default-bank cell moves to the liability account — pipe 21;
- current-year earnings and retained earnings rows are populated from their
  nested sub-reports — pipes 14 and 15.

The statement is ~65 ms for the whole chain on this farm (median of 7, one
pass); the transliteration is ~15 ms. Not a speed result and not meant as one
— the data is 97 lines, and the ~1.1 s the command reports is 24 full runs,
one per stage checked.

**So: yes, the ordering-dependent pipeline composes as SQL**, on this
tranche, including the three nested reports and the recursion, with the
ordering itself under test. It is one `WITH RECURSIVE` statement of ~16 KB.

#### What this tranche does not yet do — stated so it is not mistaken for done

- **Pipes 3, 11 and 20** (`UpdatePipelineForV3MultiFarmTrackers`,
  `ReportingGroupOffsetAccounts`, `ReportingGroupConsolidateAccounts`) are
  pass-throughs. They need a multi-entity scan — a parent farm whose report
  sums child entities — and the seed farm is single-entity. The dimension
  tables for them exist (`reporting_group_farms`, `merged_accounts`,
  `consolidated_accounts`); the stages do not. This is the "pickle" Richard
  named, and it is the next tranche.
- **The GST payment schedule** is NZ two-monthly with the exception-month
  *dates* applied (December → 15 January, April → 7 May) but the
  exception-month *windows* unverified against `PaymentsDates::getSchedule()`,
  which was not read. The transliteration implements the same rule, so parity
  proves the SQL matches the rule, not that the rule matches Figured. A farm
  whose FY end makes April or December a payment month would exercise it;
  this one does not.
- **Pre-financial-year balances under YTD.** The YTD scan starts at the FY
  start, as `BuildAggregationPipeline` does, so a balance-sheet account's
  balance from before the FY is not in it. How Figured's balance sheet carries
  that forward (a report-type-specific start, or the opening pipes only) was
  not verified, so pipe 21 here fires on a bank *movement* rather than a
  balance. The overdraft's inner cash flow is unaffected — it reads the bank
  accounts' own lines before the period, as `Balance::getReport()` does.
- **Sign convention of the equity rows.** `CurrentYearEarnings` and
  `RetainedEarnings` are transliterated literally — `inverse(allincome -
  allexpenses)` on stored signs — and come out positive for a profitable
  farm. Whether a rendered Figured balance sheet flips them again for display
  was not checked. The transliteration and the statement agree; a rendered
  page is the missing oracle.
- **Both bases.** The handlers emit journals for cash and accrual; this
  lake holds one basis per line and the runs are cash. Accrual is a
  `--basis=accrual` away but unexercised.
- **Only the monthly repayment term.** Phase 3's `--all-terms` distribution is
  not yet inside the pipeline.
- **No AlloyDB port.** DuckDB/MinIO first, as agreed. The AlloyDB overdraft
  page still runs the Phase 3 statement, not the pipeline.

The overdraft page (`/overdraft`) now renders the pipeline's output as the
report — one table, months as columns, the interest row on top — with the
per-stage check, the options, the statement and the usual diagnostics in
collapsed sections underneath. The Phase 3 statement is no longer shown there;
`OverdraftSqlBuilder` remains for `duckdb:overdraft`'s check against Figured's
pinned series and for the page's source-line listing, whose predicate is the
pipeline scan's. On `overdraft-oracle-farm` the two agree to the cent
($51.16), which is the evidence the re-work enclosed Phase 3 rather than
replacing it.

The oracle here is a transliteration, not Figured's output. The stronger
oracle — seeding this same farm into figured-webapp and diffing its real
cash flow and balance sheet — is the step that would convert "the SQL matches
my reading of the pipes" into "the SQL matches Figured".

#### Stress test 1 of 3 — volume, with the per-stage check still on

`duckdb:pipeline --scale=N --all` seeds `pipeline-scale-<N>` — the oracle
farm's shape over thirty years, generated in SQL from a hash of each row's
coordinates so it reseeds identically, with the overdraft-triggering purchase
sized from the data — and runs the full check against it. Four decades of
volume, every gate on, the same twelve-month period each time:

| lines | seed | p02 scan | p07 cells | p08 merge | p14 CYE | p15 RE | p24 final | stages |
|---|---|---|---|---|---|---|---|---|
| 10,346 | 0.2 s | 15 ms | 24 | 59 | 69 | 76 | **79 ms** | 24/24 |
| 100,346 | 0.3 s | 15 | 23 | 59 | 65 | 76 | **82 ms** | 24/24 |
| 1,000,346 | 0.7 s | 19 | 26 | 61 | 61 | 79 | **79 ms** | 24/24 |
| 10,000,346 | 5.7 s | 34 | 49 | 77 | **100** | **160** | **188 ms** | 24/24 |

(each stage's ms is a full run of the chain to that stage; p24 is the report)

Two findings, and one near-miss:

- **The pipeline is flat to a million lines and correct at ten.** The scan
  collapses to cells at pipe 7 — accounts × months, a few hundred rows —
  and every pipe after that is cells work. Line volume stops mattering the
  moment it is aggregated, which is the property that makes an
  ordering-dependent chain cheap to run as one statement.
- **What bends at 10M is the nested reports, not the pipeline.** p14 and p15
  each re-scan `transaction_lines` — `CurrentYearEarnings` from the FY start,
  `RetainedEarnings` over the farm's whole history for every season's net
  profit — and from 10M lines they are where the time goes (+40 ms and +60 ms
  over p13). The base scan doubles too (p02: 19 → 34 ms). The three
  sub-reports Figured nests are three reads of the same table in one
  statement, and that is the cost that scales. The fix, if one is ever
  wanted, is to scan once and derive the three from it — which Figured's
  structure never allowed and this one does.
- **A hollow pass.** The first sweep reported 24/24 at 10K and 1M on farms
  that had **zero lines in the lake**: the seeder had failed on an unsigned
  overflow (`hash()` returns UBIGINT), the output filter hid the error, and
  both sides agreed on zeros. That is absence, not parity. `DataPipelineCheck`
  and the command now fail the run outright when the scan is empty.

To run the oracle at volume without a million-element PHP array,
`DataPipelineOracle` reads lines pre-bucketed by (account, month, type, tag).
Every predicate the pipes apply falls on a month boundary in this PoC, so the
bucket carries what a line would and pipe 7's `SUM` is the only thing that has
happened to it; the transliteration stays a transliteration.

#### Stress test 2 of 3 — concurrency, and the catalog it took to run it

The lake profile could not be swept at all with its file catalog: the second
worker fails to `ATTACH` with `Could not set lock on file catalog.s3.sqlite`,
the finding the harness first made. So this is the first live run of the
**Postgres DuckLake catalog** — `DUCKLAKE_CATALOG_DRIVER=postgres`, pointed at
a `ducklake_catalog` database on the AlloyDB instance, env-overridden per
invocation. It works: schema, a 1M-line seed in 1.4 s, 24/24 stages. It also
costs — p24 is 120 ms on it against 79 ms on the file catalog, the catalog
round-trips per statement.

`bench:concurrency --engine=lake --report=pipeline` (every gate on) against
the Phase 3 one-pipe statement, same 1M farm, same Postgres catalog, 25 runs
per worker, taken **after** the catalog host had settled (AlloyDB at ~1% CPU
throughout — the first pass was taken while it repopulated its column store
at ~300% and ran a third slower; a 5-run pass before that under-measured by
half again. Neither is shown):

| conc | pipeline thru | p50 | p95 | · | one-pipe thru | p50 | p95 |
|---|---|---|---|---|---|---|---|
| 1 | 8.7 req/s | 90 ms | 95 ms | | 22.0 req/s | 24 ms | 28 ms |
| 4 | 18.7 | 167 | 182 | | 53.4 | 39 | 45 |
| 8 | **22.8** | 295 | 351 | | 77.6 | 52 | 70 |
| 16 | 20.5 | 631 | 927 | | **82.0** | 99 | 197 |

- **The pipeline saturates at ~23 req/s by eight workers**; past that,
  latency grows with queue depth — p95 0.9 s at sixteen. The one-pipe
  statement is still climbing at sixteen, at ~82.
- **The ratio is ~3.6× on throughput and ~3.7× on single-request latency**,
  and it is not the CPU: sixteen cores, `app-minio` at ~230% during a
  four-worker sweep, MinIO at 3%. Each DuckLake scan opens a snapshot with
  several sequential round-trips to the Postgres catalog; the pipeline does
  three scans per statement — the outer scan and the two nested sub-reports
  — where the one-pipe statement does one. Three scans' worth of catalog
  latency per request is the ceiling, and the ratio says so.
- The per-process bootstrap — attach the Postgres catalog, attach MySQL — is
  outside the timing, as for every engine here; PHP-FPM pays it once per
  worker.

What would move the line: derive the three nested reports from one scan
instead of three (the volume finding above), and put the catalog on a
Postgres that is not also doing something else. Neither is done.

#### Stress test 3 of 3 — shape

Volume was never the production risk; shape is. Three shapes, in order of
how much they exercise:

**84 months instead of 12** — `--from=2018-07-01 --to=2025-06-30` on the 1M
farm, every gate on: **24/24**, p24 171 ms against 79 for twelve months —
2.2× the time for 7× the period. The recursion is 84 deep and the YTD
windows 84 wide and it is sub-linear, because the cells table is still
accounts × months and the scans are the same scans.

**214 accounts instead of 14** — `--scale=1000000 --accounts=200`, which
spreads the expense lines across two hundred extra Xero accounts: **24/24**,
p24 121 ms against 79 — 1.5× the time for 15× the width. The cells-stage
pipes are the ones that grow (p16 YTD 119 ms, p15 RE 105), as they should:
they are windows over accounts × months. The PHP transliteration, which loops
over cells, went from 33 ms to 395.

**Multi-entity** — the reporting-group "pickle": pipes 3, 11 and 20 are
pass-throughs, and this is the shape most likely to break the "composes as
SQL" claim, because a parent farm's report is the sum of its children's and
each child's offsets are a nested `AccountBalances` run against *another*
farm. Not a stress test; a build. It is the next tranche.

## Concurrency — the axis every other number here omits

Every other measurement in this README is one query on an idle machine. That is
the case that flatters a columnar engine most: DuckDB takes all sixteen cores
for a single scan, so a lone query looks superb and says nothing about what ten
simultaneous users would get.

`php artisan bench:concurrency --engine= --report= --concurrency= --runs=`
spawns N worker processes and reports latency percentiles and throughput.

**It measures queries, not HTTP.** `artisan serve` wraps `php -S`, which is
single-process unless `PHP_CLI_SERVER_WORKERS` is set — it is not. Firing
concurrent requests at the report pages would queue them at the dev server and
measure that queue. Production runs PHP-FPM with a worker pool, so separate OS
processes are the faithful model.

**Workers are processes, not threads**, and not only because ext-pcntl is
absent. One process sharing a DuckDB handle across "concurrent" queries would
measure a mutex. Separate processes also model the real asymmetry: **DuckDB is
embedded, so N concurrent reports mean N instances each wanting every core**,
while AlloyDB and MongoDB are shared servers scheduling N queries in one
process. That difference is invisible at concurrency 1.

### The lake cannot currently be tested this way

At `--concurrency=2` the second worker dies:

```
Failed to attach DuckLake MetaData "__ducklake_metadata_lake" …
Could not set lock on file "…/catalog.s3.sqlite"
```

**With the default file catalog, exactly one DuckDB process can attach the
lake.** The catalog-backend section above already recorded this lock as
blocking compaction while the web container runs; it is broader than that — it
blocks a second READER process outright.

Two things stop that being damning. It is a local configuration choice, and
DuckLake's own recommendation for single-writer PoCs; Postgres and MySQL
catalogs are both wired up. And production would run the catalog in MySQL,
which is a server and has no file lock.

But it is not a quick switch: the catalog is the only record of what lives in
the bucket, so re-pointing it orphans every Parquet file until they are
re-registered, and the Postgres/MySQL `ATTACH` strings here have never been
executed against a real server.

### AlloyDB, gross margin, `gm-dairy-farm-1m`

| concurrency | throughput | p50 | p95 |
|---|---|---|---|
| 1 | 1.13 req/s | 465 ms | 592 ms |
| 2 | 2.86 req/s | 545 ms | 559 ms |
| 4 | 5.44 req/s | 551 ms | 591 ms |
| 8 | 8.60 req/s | 708 ms | 756 ms |
| 16 | 11.06 req/s | 1,087 ms | 1,164 ms |

**9.8x the throughput for 16x the concurrency, and latency degrades only
2.3x.** A shared server scheduling work properly.

Two caveats before quoting these:

- **The column store was empty for this run.** It was deregistered during the
  chaff experiment to break a DDL lock and never repopulated, so these are
  row-store scans — which is why 1M lines reads 465 ms here against the 62 ms
  recorded earlier. The SCALING is still valid, because the confound is
  identical at every concurrency level, but the absolute latencies are not
  comparable to anything else in this README.
- **Throughput is understated at low concurrency.** Wall clock includes each
  worker's bootstrap and one untimed warm-up query, amortised over only five
  timed runs. The latency percentiles exclude both and are clean.
