# Realistic journal data, nesting, and making the chaff free

Verbatim conversation export. Starts with seeding a farm that carries the
bookkeeping lines a real chart of accounts has, and ends with the layout
decision that makes those lines cost nothing.

---

## Bobby

ok, seed a new farm 'gm-dairy-farm-500M-non-aggregated' with 500M transactions and adding balancing + GST lines to the PoC seeder

---

## Claude

*[checked disk — 494 GB free on the host, 287.8 GB already in Docker volumes — then asked two questions: whether "500M transactions" meant 500M report lines plus chaff on top (~1.75B rows) or 500M total lines, and which engines should get the farm. Bobby chose 500M report lines, DuckDB/DuckLake only.]*

*[read the lake seeder, added the farm constants, three bookkeeping legs per report line, and a `--raw` flag; tested at 20k scale first]*

Seeded and the fidelity gap is now measured rather than caveated.

### The farm

**1,749,999,212 rows, seeded in 790 s.** `--rows` counts report lines, so it holds the same report-relevant volume as `gm-dairy-farm-500m`:

| | rows | report lines | ratio |
|---|---|---|---|
| `gm-dairy-farm-500m` | 499,999,724 | 499,999,724 | 100% |
| `gm-dairy-farm-500M-non-aggregated` | 1,749,999,212 | ~500M | **28.6%** |

28.6% against Figured's measured 27.7%. Whole lake is 20 GB.

### The result

| farm | median | output |
|---|---|---|
| `gm-dairy-farm-500m` | 5,290 ms | 996 cells |
| `gm-dairy-farm-500M-non-aggregated` | **9,846 ms** | 996 cells, **identical** |

**Chaff costs 1.86× for 3.5× the rows.** I predicted "much closer to 1×" and was wrong — third bad prediction of this exercise, and the third time measuring was worth it.

The identical output is what makes it a clean A/B: the bookkeeping accounts carry no `report_group`, so `account_scope` excludes them. Verified first against a throwaway control farm at 20k, then again at 1.75B.

### Why it isn't free

The scan still reads `account_id`, `date`, `basis`, `type` and `farm_id` for all 1.75B rows to decide what to discard — only `amount` is spared. And row-group skipping can't help because the generator emits each report line with its legs together, so **every row group holds a mix**. Sorting the partition by `account_id` would make row groups homogeneous and should collapse most of the 1.86×.

### What it changes for Dave

Figured V2's volumes are total lines including chaff, so the PoC's had to be too:

| basis | Figured V2 | DuckDB + DuckLake | ratio |
|---|---|---|---|
| total lines scanned | 25.0M in 10,182 ms | 1.75B in 9,846 ms | **~70×** |
| report-relevant lines | ~6.9M | ~500M | **~72×** |

**~70×, down from the ~90× I quoted yesterday.** More importantly, both framings now agree — the old number compared chaff-free PoC data against Figured's chaff-laden data, so it couldn't survive the question you asked. This one can.

---

## Bobby

can u do the same on alloydb

---

## Claude

Here's the blocker, with the arithmetic:

**AlloyDB's column store is table-wide, not per-farm**, and it's already at capacity.

- ~6.1 bytes/row resident (8,736 MB held 1.5B rows at 100% coverage)
- Budget 9,216 MB → **~1.58B rows maximum**
- Current table: **1.501B rows** — essentially exactly full

Adding a 1.75B-row farm means 3.25B rows needing ~19.8 GB. The Docker VM has **16.8 GiB total**, with 2 GB already in `shared_buffers`. The budget can't be raised far enough — not even close.

Two consequences:

1. The chaff measurement would be **confounded** — you'd be measuring column-store overflow, not chaff.
2. It would **break the existing 1B result**. Coverage would fall to ~46%, so the 3,324 ms figure in the README stops reproducing.

*[asked how to proceed; Bobby chose dropping the 1B and 500M farms and running a clean A/B at 250M report lines]*

*[mirrored the seeder change to AlloyDB — the small raw test produced 68,588 rows, byte-identical to the lake's 68,588]*

**The lock fight, which is worth recording.** `DROP TABLE` sat blocked for **10 minutes** on `AccessShareLock` held by three columnar engine workers. Restarting didn't help — `enable_configuration_persistence=on` restored the column registration and the workers came straight back.

What worked was deregistering and dropping **in the same session**:

```sql
SET lock_timeout = '90s';
SELECT google_columnar_engine_drop('transaction_lines');
DROP TABLE IF EXISTS transaction_lines;
```

This is the second time in this PoC that the columnar engine's background rebuild has blocked DDL.

---

## Bobby

stop the alloydb seeding. I want to reseed gm-dairy-farm-500M-non-aggregated to use the nested structure

---

## Claude

*[stopped it. AlloyDB left with the 250M control complete and the treatment 2% done]*

### What "nested" now means in the PoC

A second lake table, `transactions`, mirroring Figured's document shape:

```sql
CREATE TABLE transactions (
    farm_id, farm_type, region, transaction_id, type, basis, date,
    lines STRUCT(line_id VARCHAR, account_id VARCHAR,
                 amount BIGINT, tracker_id VARCHAR)[] NOT NULL
)
PARTITIONED BY (farm_id, basis, year(date))
```

One row per **transaction**, carrying its legs in a list — an invoice is one row holding its expense line, GST line and payable line, exactly as Figured stores it. Same partition key as the flat table, so any timing difference is the nesting rather than the pruning.

The design point: `account_id` is the report's filtering column and it lives **inside** the list, so nothing can be skipped until `UNNEST` has expanded it. That's the relational `$unwind`, and it's precisely the thing you flagged as untested.

Validated at small scale: **68,588 lines across 19,628 transactions at 3.494 lines each — byte-identical to the flat farm's 68,588.**

### The result

| layout | rows scanned | Parquet | fact aggregation |
|---|---|---|---|
| flat `transaction_lines` | 1,749,999,212 rows | 17,311 MB | **11,277 ms** |
| nested `transactions` | 499,999,724 txns × 3.5 | **9,671 MB** | **22,495 ms** |

Aggregates identical — 812 groups either way.

**Nesting halves the storage and doubles the query.** 44% smaller on disk, 1.99× slower to read.

One cause. Packing 3.5 lines into a row stores the transaction's farm, basis, date and type **once** instead of 3.5 times. But the same packing puts `account_id` behind a list indirection, so the scan must materialise 1.75B struct values out of 500M list values *before* it can discard anything.

I was wrong to wave this away as "inapplicable rather than skipped." It's applicable, it's measurable, and it's 2×.

But it doesn't mean the PoC was cheating. It means **normalise on the way in.** Nothing forces a lake to copy Mongo's shape — `lines[]` exists because Mongo is a document store, not because journal data is naturally nested.

---

## Bobby

Figured's aggregated transactions unrolls the lines, adds the key (group_hash) and adds up all the net amounts. The data we seeded doesn't do that right?

---

## Claude

Correct, and deliberately so — that's exactly what you asked for when you said you wanted raw numbers.

Both layouts hold **raw journal lines**, nothing pre-summed. Neither has a `group_hash`, neither sums anything at write time. Every report run does the full scan and aggregation from scratch.

### What `aggregated_transactions` actually is

It's a **materialized copy of the report's own first CTE**:

| | grouping key |
|---|---|
| Figured's `group_hash` | `type｜basis｜year｜month｜account_id｜tags｜trackings` |
| PoC's `monthly_by_account` CTE | `month_start, account_id` (type/basis/farm already filtered in the WHERE) |

### How big the win would be

Aggregating the 500M farm produced **812 groups** from 1,749,999,212 lines — a reduction of roughly **two million to one**. A pre-aggregated table would be ~812 rows. Reading it is sub-millisecond on any engine.

**Which is precisely why measuring raw was the right call.** Pre-aggregation doesn't compare engines; it compares caches. Turn it on everywhere and all four stacks collapse to roughly the same number, and you learn nothing about which one to pick.

---

## Bobby

And the PoC was already doing the monthly_by_account before the data format change? So we had the same functionality as Figured's group_hash net summing aggregated transactions?

---

## Claude

Half right, and the half that isn't is the important one.

### Yes — the CTE predates all of this

`monthly_by_account` has been in the GM V2 SQL since long before the chaff and nesting work. And yes, the **grouping logic** is essentially what `group_hash` encodes.

### No — it's the same computation at a completely different time

| | when it runs | cost per report |
|---|---|---|
| PoC `monthly_by_account` | **every single query** | scans 1.75B rows → 812 groups → ~11 s |
| Figured `aggregated_transactions` | **once, at write time**, asynchronously | reads ~812 pre-summed rows → milliseconds |

Same arithmetic, opposite side of the read/write divide — and that's the entire optimisation. The PoC has the logic; it doesn't have the materialisation.

### The word `MATERIALIZED` is a false friend here

`AS MATERIALIZED` in DuckDB and Postgres is a **within-query** hint: compute this CTE once and reuse it across references in *this execution*. It lives for the duration of one query and is then discarded. It has nothing to do with a materialized *view*.

I put it there for a specific reason — without it, the 500M farm re-scanned and spilled 25,596 local writes to disk. It's a spill fix, not a cache.

---

## Bobby

Ok - then Ive lead us astray. There is not point in PoC-ing with this new data shape because:
1. the PoC was already doing the maths on the old data format
2. it's a caching strategy to speed up the Figured reports that the PoC does not need as we've proved DuckDB and AlloyDB is fast calculating it each time
3. as you say, we can always normalise on the way in if we really wanted to.
4. we need the data format to be optimized for columnar storage - not tied to how Figured currently has it

---

## Claude

Agreed on the conclusion, but one of the four reasons conflates two different things.

**1, 3 and 4 are correct.**

**Reason 2 applies to `aggregated_transactions`, not to the nesting.** They're different things:

| | what it is | did we PoC it? |
|---|---|---|
| `aggregated_transactions` | a pre-summed **cache** | No — correctly, on your instruction |
| nested `lines[]` | Figured's raw **storage shape** | Yes, just now |

### So the nesting work wasn't the detour

It's the evidence for your own reason 3. Before it, "we can normalise on the way in" was an assumption. Now it's **2× faster and 79% more Parquet**, measured on identical data.

### One distinction worth protecting

Don't let reason 4 sweep away the **chaff** work along with the nesting:

- **Chaff (GST, payable, bank lines) is real data.** Double-entry produces those lines in any accounting system on any engine.
- **Nesting is a storage shape.** That's the part tied to Mongo, and the part to drop.

---

## Bobby

I think we should stick with transaction_lines right? It's the one more that's more optimized for columnar storage and the DB engines queries are faster?

---

## Claude

Yes — but sharpen the reasoning slightly, because "more optimized for columnar storage" is the one part that doesn't hold.

**Nested is actually the more storage-efficient layout**: 9,671 MB against flat's 17,311 MB.

Both are columnar. The difference is what the scan has to do:

| | flat | nested |
|---|---|---|
| `account_id` access | one value per row, direct | list-encoded, must reconstruct 1.75B values from 500M lists |
| **storage** | 17.3 GB | **9.7 GB** |
| **query** | **11,277 ms** | 22,495 ms |

So the accurate statement is: **flat is optimised for reading, nested for storing.** Flat costs **7.6 GB extra** per 1.75B lines to save **11 seconds on every single report**.

That's the version to give Richard, because "it's more columnar" invites a correction and "it trades 7.6 GB for 2× on every read" doesn't.

---

## Bobby

so the 1.75B rows with realistic chaff does not change the report query time?

---

## Claude

No — it nearly doubles it. What's unchanged is the **output**, not the time.

| farm | rows | report | output |
|---|---|---|---|
| `gm-dairy-farm-500m` | 500M | 5,290 ms | 996 cells |
| `gm-dairy-farm-500M-non-aggregated` | 1.75B | **9,846 ms** | 996 cells, identical |

**Chaff costs 1.86×.** The identical output is what makes it a valid A/B: same answer, same 500M useful lines, only the chaff differs.

### One clarification on the numbers you've seen

Two different measurements have been flying around, and they're not the same query:

- **9,846 ms** — the full Gross Margin V2 report
- **11,277 ms** — the hand-written fact aggregation I used for the flat-vs-nested test

The second is *slower* despite doing less, because I grouped by `date_trunc('month', date)` directly — the same expression-grouping trap that cost 40,687 ms vs 2,740 ms on AlloyDB.

---

## Bobby

ok - so it's faster to use the nested model (in terms of query time)?

---

## Claude

No — the other way round. **Flat is 2× faster.**

```
flat     median    11277 ms  (min 11247, max 11720)  groups=812
nested   median    22495 ms  (min 22411, max 22817)  groups=812
nesting cost: 1.99x
```

**Nested wins on disk. Flat wins on speed.** Nothing wins both.

---

## Bobby

ok - let's go with transaction_lines. but 11277 is two slow to query the balancing accounts. Can we get it faster?

---

## Claude

*[tested pushing the account filter into the scan: 8,332 ms → 6,316 ms. Then added a `--sorted` flag and seeded an account-clustered farm. The first attempt used `ORDER BY` and was OOM-killed at 500M scale — silently, because piping through `tail` returned tail's exit code. Switched to emitting each leg as its own INSERT, which produces the same clustering with no sort.]*

### Two levers, and neither works alone

Fact aggregation over 1.75B rows:

| | filter after scan | filter inside scan |
|---|---|---|
| **interleaved** | 7,792 ms | 6,460 ms |
| **clustered** | 7,699 ms | **2,935 ms** |

Clustering *alone* is **worthless** — 7,699 vs 7,792 is noise. Sorting gives Parquet's row-group statistics something meaningful to describe, but if the query never mentions `account_id` in the scan there's no predicate to test them against.

The filter alone buys 17%. Together they buy 2.65×.

### Correction I owe you

I said the report filtered after aggregation and that fixing it would buy 24%. **The lake already had it** — `factScopePredicate()` has always carried the semi-join. It's the *AlloyDB* builder that moves it out, because there it blocks columnar pushdown. So the lake needed **no query change at all**; this was purely a layout win.

### The full report

Three layouts, identical output (996 cells):

| farm | rows held | report |
|---|---|---|
| no chaff | 500M | 4,106 ms |
| chaff, interleaved | 1.75B | 7,390 ms |
| **chaff, clustered** | **1.75B** | **4,101 ms** |

**1.80×, landing exactly on the no-chaff baseline.** The 1.25 billion bookkeeping rows are in the data and cost nothing.

Clustering costs **834 s vs 790 s** to write — a 5.5% one-time penalty.

---

## Bobby

ok, so summarise for me the findings and changes that made the report faster

---

## Claude

### DuckDB + DuckLake

| # | change | measured |
|---|---|---|
| 1 | **Row group size** 122,880 → 1,000,000 | **5.9×** (and writes 1.55× faster) |
| 2 | **Aggregate before joining dimensions** | **18×** on a 3-year window (30,274 → 1,685 ms) |
| 3 | **Cluster the fact table by account** | **1.80×** (7,390 → 4,101 ms) |
| 4 | **`temp_directory` off the Docker bind mount** | removed a spill path |

**1 — Row groups.** DuckDB issues one HTTP range request per *(row group × column)*. Request count, not bytes, is what a query over object storage costs.

**2 — Ordering.** The old SQL joined `accounts` onto every journal line first — 125M rows each carrying four repeated VARCHARs, ~12 GB against a 13.4 GiB limit — then aggregated. Reversing it took local filesystem operations from **38,242 to zero**, and retired an earlier wrong conclusion: the 3-year window wasn't compute-bound, it was spilling.

**3 — Clustering.** Needs both halves: a scan-level `account_id` filter (already shipped) *and* account-contiguous rows.

### AlloyDB — a different set of rules

| change | measured |
|---|---|
| **`GROUP BY` a raw column, never an expression** | **40,687 → 2,740 ms (14.8×)** |
| Move the account filter *out* of the scan | required for pushdown |

### Mongo baseline — for contrast

| change | measured |
|---|---|
| **Covering index** (`PROJECTION_COVERED`, `docsExamined: 0`) | 27,546 → 15,364 ms |
| Expression tuning (`$dateToString` → `$year`/`$month`) | 7% |

### The pattern across all three

**Every significant win was about not reading data, and none were engine-agnostic.**

- The lake's fix is *fewer, larger requests* and *skippable row groups*
- AlloyDB's fix is *keeping the aggregate inside the columnar scan*
- Mongo's fix is *never fetching the document*

And two are direct opposites: the account filter belongs **inside** the scan on DuckDB (2.28×) and **outside** it on AlloyDB (14.8×). Identical SQL across engines is right for *measuring* and wrong for *shipping*.

---

## Bobby

so what are the final timings of the reports on duckdb and alloydb for the flatten transaction lines layout?

---

## Claude

I can give you solid DuckDB numbers but not honest AlloyDB ones.

### DuckDB + DuckLake — measured today, same session, same load

| farm | rows held | report |
|---|---|---|
| `gm-dairy-farm-500m` (no chaff) | 500M | **4,106 ms** |
| `...-non-aggregated` (interleaved) | 1.75B | **7,390 ms** |
| `...-non-aggregated-sorted` (clustered) | 1.75B | **4,101 ms** |

### AlloyDB — I can't give you a current number

The container is stopped, and the comparable farms no longer exist — getting the chaff A/B onto AlloyDB meant dropping `gm-dairy-farm-1b` and `gm-dairy-farm-500m`, and the rebuild was stopped part-way. The columnar store was also deregistered to break the DDL lock.

What's on record from earlier sessions:

| | lake / MinIO | AlloyDB |
|---|---|---|
| 500M farm, full span | ~2.9 s | 2.6 s |
| 1B farm | 3,964 ms | 3,324 ms |

**Treat those as historical.** The lake's own 500M number today reads 4,106 ms rather than ~2.9 s — which tells you the cross-session variance is larger than the AlloyDB-vs-lake gap those numbers claim.

---

## Bobby

no, testing it on DuckDB is enough for now. So, the winner is gm-dairy-farm-500M-non-aggregated-sorted? Which means keeping the transaction_lines flattened ut sorting them by account_id?

---

## Claude

Yes on the substance, with two refinements.

### It's clustering, not sorting

There's no `ORDER BY` — that approach **failed**, OOM-killed at 500M scale. What ships instead: **each account's rows are written by their own INSERT**, so they land contiguously with no sort at all.

Practically: *"write the fact table account-contiguous within each partition"*, not *"add an ORDER BY account_id"*.

### It ties the no-chaff farm, it doesn't beat it

| farm | rows | report |
|---|---|---|
| `gm-dairy-farm-500m` (no chaff) | 500M | 4,106 ms |
| `...-non-aggregated-sorted` | 1.75B | 4,101 ms |

The win isn't that clustering made the report faster than it could otherwise be — it's that **you can hold realistic, complete journal data and pay nothing for the 72% the report doesn't want.**

So the winner isn't a farm. It's a layout decision for `transaction_lines`:

- **flat**, one row per journal line — 2× faster than nested
- **partitioned** by `(farm_id, basis, year(date))` — unchanged
- **clustered by account** within each partition — new
- **row groups at 1,000,000** — the existing biggest win

### One caveat on why it works

Clustering by account only pays because the query filters on `account_id` *inside the scan*. Without it, clustering is worth literally nothing (7,699 vs 7,792 ms).

Likely better than fine: Gross Margin reads the P&L accounts, Balance Sheet reads exactly the ones GM excludes, Cash Flow reads its own subset. Every account-scoped report reads a contiguous run and skips the rest. But that's an inference from how these reports are shaped, not something measured on anything except Gross Margin.

---

## Bobby

can you explain clustering again in more details (particular in parquet context)?

---

## Claude

*[pulled the actual Parquet row-group statistics out of both farms with `parquet_metadata()`, and counted files with `ducklake_list_files()`]*

### Parquet's physical structure

A Parquet file is split into **row groups** (horizontal slices, ours hold ~2.6M rows). Within each row group, every column is stored as its own **column chunk**. And critically, each column chunk carries **statistics in the file footer**: min, max, null count.

Those statistics are the whole mechanism. When DuckDB scans with a predicate like `account_id IN ('gm-feed-supplement', 'gm-animal-health', …)`, it:

1. Reads the footer (cheap, one request)
2. For each row group, asks: *does `[min, max]` for `account_id` overlap anything I want?*
3. If not — **skips the entire row group without fetching a single byte of it**

### What our two farms actually look like

**Interleaved** — largest file, 177 MB:

```
rg 0   2,625,536 rows   min=gm-accounts-payable   max=gm-stock-purchases
rg 1   2,624,512 rows   min=gm-accounts-payable   max=gm-stock-purchases
rg 2   2,625,536 rows   min=gm-accounts-payable   max=gm-stock-purchases
...  14 row groups total, 0 hold exactly one account (0%)
```

Every row group spans the **entire alphabetical range** of accounts. The seeder emitted `feed → gst → payable → bank → feed → …`, so all 18 accounts appear within the first few dozen rows. Statistics exist, are perfectly accurate, and are **completely useless** — every range overlaps, so nothing can be skipped.

**Clustered** — largest file, 50 MB:

```
rg 0   2,621,440 rows   min=gm-accounts-payable   max=gm-accounts-payable
rg 1   2,621,440 rows   min=gm-accounts-payable   max=gm-accounts-payable
rg 2   2,621,440 rows   min=gm-accounts-payable   max=gm-accounts-payable
rg 3   2,552,340 rows   min=gm-accounts-payable   max=gm-accounts-payable
...  4 row groups total, 4 hold exactly one account (100%)
```

`min == max`. Every row group is one account. `gm-accounts-payable` isn't a report account, so all four are skipped on the footer alone — **~10 M rows never read**.

### Clustered is not sorted

- **Sorted** — all rows globally ordered by `account_id`
- **Clustered** — each account's rows are *contiguous*; the accounts themselves can appear in any order

Pruning only needs the second. That's why per-leg `INSERT`s work: each statement writes one account's rows in one run, no sort required. The `ORDER BY` attempt was both unnecessary and fatal.

### Three levels of skipping, and they compose

| level | mechanism | granularity |
|---|---|---|
| **partition** | `(farm_id, basis, year(date))` → directory path | whole files |
| **row group** | min/max statistics in the footer | ~2.6M rows |
| **column** | only referenced columns are fetched | per column chunk |

### The tension with row group size

Row groups were raised 122,880 → 1,000,000 because **DuckDB issues one HTTP request per (row group × column)** and requests dominate over object storage.

But bigger row groups mean *coarser* pruning. Our per-account runs are ~10M rows, so at 2.6M per group each run spans ~4 groups cleanly. Push row groups to 20M and each would straddle several accounts — statistics would widen and pruning would collapse back toward zero. The two settings have to be chosen together.

### The fragmentation question, now measured

| | files | total | avg file |
|---|---|---|---|
| interleaved | **56** | 8,466 MB | 151.2 MB |
| clustered | **208** | 8,201 MB | 39.4 MB |

Clustering produced **3.7× more files** — each `INSERT` writes its own. Since request count drives lake cost, that is a real trade, and on MinIO (localhost) it is invisible in a way it would not be on GCS with an ~8 ms round trip. Worth re-measuring on the `gcs` profile before trusting the 1.80× in production.

The compression inference also firms up: 8,201 MB against 8,466 MB for identical data, so clustering is ~3% smaller — real, but far less than the earlier delta suggested.

### The maintenance catch

Clustering **decays**. New journals arrive mixed across accounts, so appended files revert to interleaved. Keeping the benefit means periodic re-clustering — `ducklake_rewrite_data_files` territory, alongside the compaction the README already covers.

---

## Open items

1. **AlloyDB has no comparable farm.** The 1B and 500M were dropped; the 250M control is complete but never columnarized, and the 250M treatment is 2% seeded. A side-by-side needs ~1 hour of reseeding.
2. **The ~70x vs Figured used the pre-clustering 9,846 ms.** The lake is now 1.80x faster on that farm, so the true ratio is higher — but both sides were measured in different sessions under different load, so re-run them together before quoting a new figure.
3. **Clustering decays with appends.** New journals arrive mixed across accounts, so the layout degrades as data lands. A re-clustering strategy (`ducklake_rewrite_data_files`) is needed to hold the benefit, and has not been designed or measured.
4. **The 1.80x was measured on MinIO.** Clustering produced 3.7x more files (56 -> 208), and each extra file is an ~8 ms round trip on GCS rather than a localhost call. Re-run on the `gcs` profile before trusting the figure in production.
5. **The Mongo baseline still has its flat one-line-per-document shape** and the stale docblock claiming that shape is conservative.

Closed during this conversation:

- ~~Partition fragmentation is unmeasured.~~ Measured: 56 files interleaved against 208 clustered, 3.7x. The cost of that on real object storage is item 4 above.
- ~~The compression gain from clustering is inferred.~~ Measured: 8,201 MB against 8,466 MB for identical data, ~3% smaller.
