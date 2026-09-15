# Benchmarking Figured's Gross Margin report

Verbatim conversation export. Starts at the decision to benchmark the real
Figured stack, and ends with the measured V2 wall-clock crossovers.

Work landed on the `bl-poc-gm-benchmark` branch of `figured-webapp`.

---

## Bobby

ok - let's now benchmark on Figured. What is the equilavent report of our GM V2 report in the poc?

---

## Claude

Let me find the real equivalent rather than assume it's the obvious one:

*[launched a search over `figured-webapp` for the gross margin report family, its routes, its pipeline, and how to run it headlessly]*

Two things worth flagging before the answer, both of which affect the benchmark:

**The containers are gone.** Everything I was measuring against has been removed — `docker ps -a` is empty. The volumes survived (`duckpoc-mongo-data`, `duckpoc-alloydb-data`, and the rest, 278.9 GB total), so the 36M Mongo documents and 1.5B AlloyDB rows are intact and a `docker compose --profile … up -d` restores them. Likely a Docker Desktop or machine restart with the day rollover. Nothing lost, but my "all three stacks serving" from last night is no longer true.

**Figured's Mongo is not shaped like my PoC's.** From its compose file:

```yaml
mongo:
  image: mongodb/mongodb-atlas-local:7.0.14
  environment: { MONGO_REPLICA_SET_NAME: rs0 }
  command: mongod --replSet rs0 --bind_ip_all
  volumes: ['./docker/volumes/mongo/data:/data/db']
```

A **replica set**, not the standalone `mongo:7.0` I benchmarked. Same major version, so the same aggregation stages are available, but there's oplog write overhead and different read-concern defaults. For read-only report timing the gap should be small — worth stating rather than discovering later.

Also relevant: the Figured stack is currently stopped, and starting it alongside the duckpoc stacks would have them contending for the same Docker VM. That's precisely what corrupted my covering-index measurement yesterday, so I'd stop the duckpoc profiles before timing anything on Figured.

I have a search running to pin down exactly which report class is the equivalent — specifically whether a mixed milk+livestock farm renders one report or several, and whether gross margin runs through the V1 `Builder` or the V2 `ReportRunnerService`, since that changes what you're measuring. I'll report as soon as it lands.

---

## Claude *(after the search returned)*

**Dairy Gross Margin** (`dairy_gross_margin`) — not Livestock GM. It's the only variant carrying both milk *and* livestock tracker groups on one farm, which is exactly your mixed-enterprise farm.

Confirmed: it's the only GM variant declaring both `TrackerGroups::MILK` and `TrackerGroups::LIVESTOCK`.

### Where it lives

| Layer | Path |
|---|---|
| Scope (which trackers) | `app/Figured/Packages/Reports/DefaultReportTypes/DairyGrossMargin.php` |
| V1 renderer | `app/Figured/Report/Types/DairyGrossMargin.php` → `BaseGrossMargin.php` |
| Service | `app/Services/Reports/DairyGrossMarginReportService.php` → `BaseGrossMarginReport.php` |
| Payload route | `GET /{farm}/reports/data/dairy_gross_margin` |
| API | `GET /api/v2/farms/{f}/reports/dairy_gross_margin` |

The section IDs map onto your PoC almost directly: `income_total`, `costs_total`, and `dairy_gross_margin_total` is your Gross Margin line.

**It runs the V1 pipeline.** `DairyGrossMargin::getNewReport()` → `new Builder($profitLoss, $period)->get()`. `ReportRunnerService` isn't in the path at all. That confirms what I suspected two days ago — the report you rebuilt is on the *legacy* side, the one that loops per options group. A V2 gross margin exists but only as Reporting Studio widgets, and those have no quantity rows or per-unit margins, so they aren't the equivalent.

### Four differences that affect whether the benchmark is fair

**1. There's a fourth section you don't have.** `valuation_movement_total` — Livestock valuation movement — sits between Direct Costs and the total, and is *part of* the total. The V2 calculation is literally `(income) + tracker_valuations - tracker_costs`. That's the "Livestock valuation — not built yet" item on your PoC's own index page. **Figured's report does strictly more work than yours.**

**2. Basis is hardcoded to accrual.** `$basis = Basis::ACCRUAL;` in `getReportOptions()`, not a user option. Your PoC data is cash-only, so that parameter simply won't correspond.

**3. Per-unit margins work completely differently, and this is the big one.** In your PoC, `per_unit` is a projection in the same query — free. In Figured, `report_b` ($/kg MS) and `report_e` ($/cow) each append an **entire additional report table**, a full re-render divided by a divisor. Turning them on roughly multiplies the work. Your single query gets for free what Figured pays for two or three times over.

**4. Quantity rows are suppressed.** `show_qtys_with_trackers` is hardcoded `false` on this route, so quantities arrive as divisors rather than rows.

Net: every difference points the same way — **the real report does more than your PoC's**. That's fine for a baseline, as long as it's stated rather than discovered later.

### How to run it headlessly

There's already a harness: `.claude/skills/compare-reports/scripts/compare-gross-margin.php`. It bootstraps the framework standalone, sets the active farm, authenticates, builds a `DairyGrossMarginReportDto`, and calls `DairyGrossMarginReportService::getView($dto, false)`. It captures no timings — adding them is the smallest possible change, and `.claude/skills/report-baselines` explicitly lists gross margin as *not* covered, so this is the only existing path.

What I'd measure, in order:

1. **`report_b`/`report_e` off** — closest to your PoC, one report table.
2. **Then on** — quantifies what per-unit margins cost in the current architecture. I'd expect this to be the most quotable number of the exercise.
3. **With `ReportDiagnosticsHelper`** for the Mongo/PHP split, since it disables the report cache and gives cold numbers.

Two practical constraints before you start: the duckpoc stacks and the Figured stack would contend for the same Docker VM, so I'd stop the duckpoc profiles first — that contention is exactly what corrupted the covering-index measurement. And `RealisticFarms` has no scale knob (fixed 2 historical years, hand-declared trackers), so volume on the Figured side is limited to whatever farms the dev dump already has.

Want me to add timing instrumentation to `compare-gross-margin.php` and run it?

---

## Bobby

is the V2 reports more performant?

---

## Claude

Yes — by design, and it's documented. But the wins are on axes that mostly aren't the one we've been measuring, and there's a bigger lever sitting next to it.

### What V2 actually fixes

From `readme.md`, "Why V2 replaced V1" — two of the six reasons are performance:

> **Performance with many trackers.** V1 runs a pipeline per section, and every tracker introduces 2–3 new sections, so tracker-heavy farms multiply query count. V2 runs 1–3 MongoDB queries for the whole report regardless of how many trackers or sections there are.

> **Memory.** V1 is memory-hungry — large farms sometimes crashed scripts — partly because it materialises every cell including zeros. V2 stores only non-zero groupings.

So V2 fixes the **tracker axis** (Richard's "50 trackers means 50× queries") and **memory**. The other four reasons — type safety, correctness, buildability, output flexibility — aren't performance at all.

### What V2 doesn't fix

**Journal volume** — the axis the entire PoC has measured. Both pipelines issue Mongo aggregations against the same collection. V2 reduces the *number* of queries; it makes no individual scan faster. A farm with 25M journal lines still has 25M documents to aggregate, still single-threaded, still decoding whole BSON documents.

That matters for your conclusions: moving gross margin to V2 would not close the 21× gap against AlloyDB, because the gap is in the scan, not the orchestration.

And for this benchmark it's moot anyway — **gross margin is V1-only** on the legacy route.

### The bigger lever, which I'd missed

`aggregated_transactions` — a Mongo cache that pre-groups transaction lines so reports read pre-summed totals:

> Large farms have millions of transaction lines, which causes slow reports and DB load. Aggregated transactions trade some storage and an asynchronous maintenance pipeline for **orders-of-magnitude faster queries on the read path**.

That *does* attack volume, and it's the one thing in the current stack that does. I assumed it was V2-only — it isn't. `Pipes/DataPipeline/QueryMongo.php:115` has the same gate, so the **V1 path can read it too**.

This is a real risk to your benchmark. It's opt-in per farm behind `beta_?aggregated_transactions`, plus a stream-processor flag and a global kill switch `config('reports.use_aggregated_transactions')`. So depending on the farm you pick, you could measure either a raw multi-million-line scan or a pre-summed lookup — differing by orders of magnitude, with nothing in the output saying which.

**Check that flag per farm before recording any number**, and record which path ran. It's also the honest counter-argument to the whole PoC: "we don't need a new engine, we need pre-aggregation."

### No measured numbers exist

The V2 claims are design rationale, not benchmarks. `CompareReportsOutputJob` does log `time` and `memoryPeak` per version, but it's a parity tool and its report list covers only P&L, cash flow and balance sheet — **no gross margin**. Nothing in the repo records a measured V1 vs V2 comparison.

---

## Bobby

Reporting Studio is a function that allows users to create their own reports by combining report UI widgets. For the benchmark, can we just build a report in RS that looks similar to our GM v2 report in the poc?

---

## Claude

Yes — and better than I told you. I need to correct something I said.

### Correction

I relayed that the V2 gross margin has no per-unit margins. That's wrong at the widget level. `GrossMarginWidgetOptionDto` takes:

```php
/** @var $columns ReportColumnInterface[] */
public array $columns = [],
```

and `DivideByMetricColumnDto` implements `ReportColumnInterface` — a column that divides by a metric, with formula support. So RS **can** do $/kg MS and $/head.

What was true: the V2 *structure builder* doesn't emit quantity **rows**. But RS delivers per-unit as **columns** — which is closer to your PoC's `per_unit` projection than V1's approach of appending whole extra report tables.

### RS is actually the better match

| | Your PoC | Legacy V1 route | RS widget |
|---|---|---|---|
| Per-unit | column in the same query | whole extra report tables | column (`DivideByMetricColumnDto`) |
| Basis | parameter | hardcoded `ACCRUAL` | `public string $basis` — configurable |
| Mongo queries | 1 | one pipeline per section | 1–3 regardless of trackers |
| Tracker scaling | GROUP BY cardinality | multiplies queries | independent |

### But they answer different questions

- **Legacy V1 Dairy GM** = what users actually experience today. That's the baseline Dave asked for.
- **RS widget** = the current stack's *best case*. That's the fair comparison against DuckDB and AlloyDB.

Both are worth having, and RS is the one that's harder to argue with — nobody can say you benchmarked a legacy path on its way out.

### You don't need to build it in the UI

`compare-gross-margin.php` already does both sides programmatically. Its `widgetTotals()` constructs `GrossMarginWidgetOptionDto` + `FarmReportColumnDto` + `TrackerOptionsDto` + `PeriodConfigDto` and runs it through `ReportDataBatchProcessor` → `GrossMarginWidgetDataGeneratorService`; its `legacyTotals()` runs the V1 service. Same farm, same period, both paths, one script — it just records no timings.

So: add timing to both sides of that script and you get **V1 vs V2 vs your PoC** in a single run, with no UI work and no new report to maintain.

Three things to control for:

1. **One widget only.** A multi-widget report measures `ReportDataBatchProcessor` batching and streaming, not the report.
2. **Check `beta_?aggregated_transactions` per farm** and record it.
3. **RS carries its own overhead** — `report_builder_initial_data_load_duration_seconds` and `streaming_time_to_first_widget_seconds` exist as separate Prometheus metrics precisely because they're separate costs. Time the generator, not the page.

Want me to add the instrumentation to `compare-gross-margin.php` and run V1 vs V2 on a dev farm?

---

## Bobby

yes please - but do it on a new branch

---

## Claude

*[created branch `bl-poc-gm-benchmark`, read the existing parity script, then wrote a sibling benchmark script rather than modifying the parity tool — that script backs the `compare-reports` skill, and benchmarking needs repeats and percentiles that would clutter its diff output]*

*[started the Figured stack — `./figured start` needs a TTY and fails non-interactively, so brought it up with `docker compose up -d nginx php mysql mongo redis`]*

Hit a blocker worth stopping on. The dev Mongo has **607 transaction lines total** — the entire `figured` database is 21.5 MB:

```
DB figured  21.5 MB
   transactions      607
   computed_benchmarks  2404
   multiline_invoices    284
```

76 farms exist in MySQL, but there's essentially no journal data behind them, and no `rf_*` RealisticFarms seeded. So the script is ready but there's nothing meaningful to point it at — a benchmark here would measure fixed overhead and nothing else.

*[asked whether to seed RealisticFarms, build a scale knob first, or point at real data — Bobby chose seeding RealisticFarms]*

*[the RealisticFarms seeder failed: the generated history-model map in `figured-storage` was stale, resolving table `farms` to `Figured\Packages\Throwaway\ChartMogul\Models\Farm`, a class that no longer exists. Rebuilt it with the `.gitlab-ci.yml` arguments — the bare command fails with a Finder error because `--scanDir` has no default]*

Stack is healthy. Farm 240, `rf_dairy_nz` — 8,442 journal lines, 2 milk + 1 livestock tracker. Small, but a genuine mixed-enterprise dairy farm through the real pipeline.

Done, on `bl-poc-gm-benchmark`. And the result inverts what the PoC suggested.

### The numbers

`rf_dairy_nz` (farm 240) — 8,442 journal lines, 2 milk + 1 livestock tracker, **raw `transactions` collection, `aggregated_transactions` beta flag off**, five cold runs each:

| | legacy (V1) | widget (V2) | ratio |
|---|---|---|---|
| actuals / 2025 | 395.7 ms | 65.0 ms | **6.08×** |
| actualsForecast / 2025 | 417.4 ms | 68.1 ms | **6.13×** |
| MongoDB phase | 9.4 ms | 23.3 ms | |
| peak memory | 87 MB | 89 MB | |

### The finding

**Mongo is 9.4 ms of the legacy pipeline's 395.7 ms — 2.4%.** Ninety-eight percent of that report happens outside the database.

And V2 isn't faster at *querying* — it reports **more** Mongo time, not less. It's faster because it does roughly a tenth of the PHP work (380 ms → 40 ms).

That's the hypothesis I formed on day one, that the PoC's Mongo baseline appeared to refute. Both are right, because they're measuring different regimes: the PoC showed Mongo scaling linearly with volume while PHP stayed flat at ~2.5 ms. At 8,442 lines, fixed PHP overhead dominates; at 25M lines, the scan does. The crossover is what matters, and neither measurement alone finds it.

Caveat on `mongo_ms`: it's read best-effort from `ReportMetricsContext`, a singleton whose lifecycle belongs to the pipeline. Treat it as indicative, not exact.

### Where this leaves the comparison

8,442 lines is three orders of magnitude below the PoC's farms, so this establishes the V1/V2 ratio and the fixed overhead — not a volume curve. To put a Figured number next to the PoC's 975 ms at 1M lines you'd need the scale knob in `RealisticFarms`, which is real work in a `Development/` package and carries the shared-trading-accounts trap the README warns about.

Want me to build that knob, or is the ratio enough for Dave?

---

## Bobby

no, we need to build the knob. We need to see the performance on a 1M farm

---

## Claude

*[profiled a real report run to capture the exact aggregation pipeline, rather than reading 300 lines of query builder. Key findings: documents hold a `lines[]` array that the report `$unwind`s; `_valid_to` is a temporal tombstone; `budget_id: 0`; only an `_id` index exists on `transactions`]*

Knob built, and we have the 1M number.

### The results

`rf_dairy_nz` (farm 240), raw `transactions` collection, `aggregated_transactions` **off**, five cold runs each:

| | 22,699 lines | 1,021,455 lines |
|---|---|---|
| **legacy (V1)** | 395.7 ms | **3,397.5 ms** |
| ↳ Mongo phase | 9.4 ms (2.4%) | 255.2 ms (7.5%) |
| **widget (V2)** | 65.0 ms | **457.4 ms** |
| ↳ Mongo phase | 23.3 ms | 395.7 ms (86.5%) |
| ratio | 6.08× | **7.43×** |

Forecast case at 1M: legacy 3,700 ms, widget 448 ms, **8.26×**.

**The two pipelines are bound by different things.** V1 is still PHP-bound at a million lines — 92.5% outside the database. V2 has pushed nearly all the work into Mongo and is now Mongo-bound at 86.5%.

### The knob

`realistic-farms:amplify --farm=240 --lines=1000000` — 272,272 documents in 7.9 s. It scales journal volume behind the same farm, accounts, trackers and months, leaving dimensions exactly as the blueprint built them. That also sidesteps the README's shared-trading-accounts trap entirely, since no tracker is cloned.

Clones are faithful — amounts are *not* divided among them. Dividing would preserve totals, but integer fixed-point amounts don't divide evenly and the remainder unbalances whichever journal it lands in. Cloning exactly makes the arithmetic exact instead: at factor N every figure is N× the baseline. Verified at both 5× and 45× — income came out at `52,244,293.95 = 1,160,984.31 × 45` — with V1 and V2 agreeing at each scale.

### Something that changes a PoC conclusion

Figured's V2 does 1M lines in **396 ms of Mongo time**. My PoC's Mongo baseline needed **971 ms** for the same line count with the covering index.

The reason is document density: Figured stores ~3.67 lines per document, so 1M lines is 278,460 documents. My PoC stores one line per document — 1M documents to decode. Since the baseline is CPU-bound on BSON decode, that's a 3.67× disadvantage I built in.

I had it backwards in the PoC docblock, where I wrote that skipping `$unwind` "spares Mongo a stage" and made the baseline conservative. It's the opposite: **the flat shape is worse for Mongo**, so the PoC's Mongo numbers are pessimistic, not generous.

### Two bugs I hit

**The amplifier ate its own output.** I read the source set with a live cursor on the collection I was inserting into; clones matched the same filter, so the cursor served them back. A 272,272-document job reached **24.7 million** before I killed it. Fixed by pinning source ids before any write, plus an expected-count guard. Cleanup was exact because every inserted document carries a marker — the one part of the design that earned its keep.

**The benchmark's `mongo_ms` was contaminated.** `ReportMetricsContext` is an accumulating singleton, so the widget inherited every legacy run and reported 648 ms of Mongo time inside a 450 ms report. Now reset before each run. It was also counting documents where it meant unwound lines.

---

## Bobby

ok, then let's increase the farm size to 25M

---

## Claude

*[reset to baseline, amplified at factor 1102 — 6,812,988 documents in 183 s at 37.5k docs/sec, 10.3 GB, WiredTiger at 6.6 GB of a 7.9 GB cache]*

Three commits on `bl-poc-gm-benchmark`, and the 25M number is verified exact.

### The curve

| journal lines | legacy (V1) | per million | Mongo | widget (V2) | per million | Mongo |
|---|---|---|---|---|---|---|
| 22,699 | 395.7 ms | 17.4 s | 2.4% | 65.0 ms | 2.86 s | 35.8% |
| 1,021,455 | 3,397.5 ms | 3.33 s | 7.5% | 457.4 ms | 0.45 s | 86.5% |
| **25,014,298** | **82,532.4 ms** | **3.30 s** | 8.1% | **10,182.2 ms** | **0.41 s** | 94.8% |

Correctness held at the benchmarked scale: income came back **1,279,404,709.62** against a predicted `1,160,984.31 × 1102` — exact to the cent, as were costs and gross margin, with V1 and V2 still agreeing.

### Three findings

**The pipelines are bound by different resources.** V1 is PHP-bound at every scale — Mongo is 8% of its 82 seconds even at 25M. V2 has pushed nearly everything into the database at 95% Mongo.

**No cliff, again.** Per-million cost is flat from 1M to 25M on both pipelines, with WiredTiger at 6.6 GB of a 7.9 GB cache against 10.3 GB of data. Constant cache misses aren't a cliff while the collection still fits host RAM — a miss is a decompress, not a seek. Same shape the PoC found, now measured twice in two codebases.

**Document density is worth 2.5×.** Figured carries 3.67 lines per document, so 25M lines is 6.8M documents; the PoC's flat collection decodes 25M. Mongo time: 9,648 ms vs 23,772 ms, tracking the density ratio almost exactly.

That last one corrects a PoC conclusion: **the PoC's Mongo baseline understates Mongo by ~2.5×**. Worth fixing before the 21×-vs-AlloyDB figure goes to Dave, because the honest figure is closer to 8×.

### Where the comparison stands at 1M

| | 1M lines |
|---|---|
| AlloyDB | 62 ms |
| Figured V2 (Reporting Studio) | 457 ms |
| PoC Mongo baseline | 975 ms |
| **Figured V1 — what gross margin actually runs today** | **3,397 ms** |

---

## Bobby

did report use the aggregated transactions stuff?

---

## Claude

**No.** It read raw `transactions` throughout. Confirmed four ways:

1. **The collection doesn't exist.** `aggregated_transactions` isn't among the 29 collections in the dev `figured` database.
2. **The per-farm beta flag is off** — `perm('beta_?aggregated_transactions')` is `false` for farm 240.
3. **The benchmark said so on every run** — that's the `collection` line I put above the timings precisely so this couldn't be missed: `transactions (raw) (beta flag off, config kill switch on)`.
4. **The profiler agrees.** A single widget run touched exactly two namespaces:

```
figured.transactions       ops=1  ms=10206  docsExamined=6,822,037
figured.farm_preferences   ops=1  ms=    0  docsExamined=1
```

One aggregation, 6.82M documents examined, 10,206 ms — matching the 10,182 ms median from the benchmark. The global config kill switch is `true`, so reads *would* be allowed; it's the per-farm flag that gates it off.

### Why this matters for the numbers

Every figure I've given you is the **worst case for Figured** — a full scan with no pre-aggregation. That's the right baseline for two reasons:

- It's directly comparable to the PoC, which also scans raw journal lines with no pre-summed cache.
- It's what farms without the beta flag get in production today.

But it also means there's a real counter-argument we haven't measured: *"we don't need a new engine, we need to turn on aggregated transactions."* Its own README claims **orders of magnitude** on the read path, and V1 can read it too, so it isn't a V2-only lever.

Worth noting the honest limit on testing it here: the collection would have to be populated, and its write path is an Atlas stream processor plus app-side integration, neither of which runs locally. A local approximation would mean hand-building the pre-summed documents — which measures a cache we fabricated, not the one production maintains.

---

## Bobby

I do not want to measure with aggregated transactions. I want raw numbers. This is because aggregated transactions is optimization.

---

## Claude

Agreed, and that's exactly what every number so far is — the profiler confirmed a single raw scan over 6.82M documents with no pre-aggregation anywhere in the path.

Your reasoning also makes the comparison **symmetric**, which is the stronger point. The PoC's DuckDB and AlloyDB numbers are raw scans too — no pre-summed Parquet, no materialized views. Raw-to-raw is the only apples-to-apples framing available.

So when someone says *"we don't need a new engine, just turn on aggregated transactions"*, the answer isn't that the measurement is wrong. It's that **pre-aggregation is orthogonal to engine choice** — DuckLake can hold pre-summed Parquet, AlloyDB can hold a materialized view, and both would get the same multiple. Turning it on changes every column by roughly the same factor and leaves the ranking intact. It's an optimization you can apply after picking an engine, not an argument for keeping this one.

That framing protects the whole exercise, and it only works because you insisted on raw.

---

## Bobby

AlloyDB and DuckDb did a billion in 1-2 seconds right? I want to know, how many transactions the Figured benchmark can do at the 1-2 second mark

---

## Claude

First, a correction on the premise: **AlloyDB and DuckDB did a billion in 3.3–4.0 seconds**, not 1–2. AlloyDB 3,324 ms, the lake 3,964 ms. Worth keeping straight since the whole comparison rests on it.

Let me measure the crossover rather than extrapolate to it.

*[predictions put on record before the sweep ran: V1 ~295k at 1 s and ~598k at 2 s; V2 ~2.36M and ~4.83M]*

Measured, and the extrapolation was wrong where it mattered most — good thing we checked.

### The sweep

| journal lines | legacy (V1) | Mongo share | widget (V2) | Mongo share |
|---|---|---|---|---|
| 317,786 | 1,459.7 ms | 6.3% | 193.7 ms | 70.8% |
| 612,873 | 2,117.5 ms | 7.1% | 289.9 ms | 81.1% |
| 2,406,094 | 6,713.1 ms | 7.9% | 901.6 ms | 94.0% |
| 4,812,188 | 12,980.7 ms | 8.0% | 1,728.5 ms | 95.3% |
| 25,014,298 | 82,532.4 ms | 8.1% | 10,182.2 ms | 94.8% |

### The answer

| | 1 second | 2 seconds |
|---|---|---|
| **Figured V1** — gross margin today | **~190,000 lines** | **~560,000 lines** |
| **Figured V2** — Reporting Studio | **~2,700,000 lines** | **~5,500,000 lines** |
| AlloyDB | ~288,000,000 lines | ~594,000,000 lines |

My V1 one-second prediction of 295k was **55% too high** — there's real curvature below ~600k lines that the two-point fit missed. The 2-second marks came in within 12%.

### What that means

At the one-second mark, **AlloyDB carries ~107× the volume of Figured's best pipeline and ~1,500× the volume of the pipeline actually serving gross margin today.**

Two honest caveats:

- **AlloyDB's crossover is interpolated**, between its measured 62 ms at 1M and 3,324 ms at 1B. Nobody has measured it near one second.
- **V1's Mongo share is 8% at every volume.** Even at 25M it's PHP-bound. So the 190k figure isn't a statement about MongoDB — it's a statement about the legacy assembly pipeline, and moving gross margin to V2 would buy ~14× before touching the database at all.

---

## Bobby

forget about v1. I only care about v2 reports. Interpolate how many transactions the v2 report can do at 3 and 4 seconds

---

## Claude

Interpolating between the two measured points that bracket it (4.81M → 1,728.5 ms and 25.01M → 10,182.2 ms, a slope of 418.5 ms/million):

- **3 seconds → ~7.85M lines**
- **4 seconds → ~10.24M lines**

But that's a 5× span between anchors, and my last interpolation missed by 55%. Let me verify it — and since you only care about V2 now, let me stop paying for legacy runs.

*[added an `only` argument to the benchmark so a run can skip legacy, which takes ~33× the widget's time on the same data]*

Measured, and the interpolation was ~7% conservative:

| target | predicted | measured lines | measured time |
|---|---|---|---|
| 3 s | 7.85M | 7,853,854 | **2,793.9 ms** |
| 4 s | 10.24M | 10,259,948 | **3,715.6 ms** |

### Answer

| | V2 lines |
|---|---|
| 1 second | ~2.7M |
| 2 seconds | ~5.5M |
| **3 seconds** | **~8.4M** |
| **4 seconds** | **~10.9M** |

The measured V2 curve, all cold runs on raw `transactions`:

| journal lines | median | per million | Mongo share |
|---|---|---|---|
| 317,786 | 193.7 ms | 0.61 s | 70.8% |
| 612,873 | 289.9 ms | 0.47 s | 81.1% |
| 2,406,094 | 901.6 ms | 0.37 s | 94.0% |
| 4,812,188 | 1,728.5 ms | 0.36 s | 95.3% |
| 7,853,854 | 2,793.9 ms | 0.36 s | 96.8% |
| 10,259,948 | 3,715.6 ms | 0.36 s | 96.4% |
| 25,014,298 | 10,182.2 ms | 0.41 s | 94.8% |

### The comparison at four seconds

**Figured V2: ~10.9M lines. DuckDB and AlloyDB: a billion** (3,964 ms and 3,324 ms).

Same wall-clock budget, roughly **90× the volume**.

### Two things worth knowing about that number

**It's a MongoDB measurement, not a Figured one.** V2's Mongo share climbs from 71% at 318k to ~96% by 2.4M and stays there. Above a few million lines this benchmark is the Mongo scan and almost nothing else — which is exactly what makes it fair to hold against a replacement engine.

**Per-million cost bottoms out at 0.36 s and then rises.** It's flat from 4.8M to 10.3M, then climbs to 0.41 s at 25M — WiredTiger cache pressure appearing, 10.3 GB of data against a 7.9 GB cache. Still not a cliff, consistent with the PoC finding, but treat anything extrapolated much past 25M as optimistic for Figured.

---

## Appendix — commits on `bl-poc-gm-benchmark`

```
90908eef59f  Measure the V2 gross margin curve and its wall-clock crossovers
0fb7cbd5346  Record the gross margin scaling curve to 25M journal lines
9ff0f68f989  Add a journal volume knob to RealisticFarms, and benchmark at 1M lines
f22bef5fd8d  Add a gross margin V1-vs-V2 benchmark script
```

Files added:

- `.claude/skills/compare-reports/scripts/benchmark-gross-margin.php`
- `src/Figured/Packages/Development/RealisticFarms/Services/JournalVolumeAmplifier.php`
- `src/Figured/Packages/Development/RealisticFarms/Console/Commands/AmplifyJournalVolumeCommand.php`

Repo issue found along the way, unrelated to this work: the generated history-model
map in `figured-storage` was stale, resolving table `farms` to a deleted
`Throwaway\ChartMogul` class and blocking every RealisticFarms seeder. Rebuilt with
`php artisan laravel-history-model:build-map --scanDir src/ --scanDir app/ --scanDir tests/Feature/Repositories --exclude Figured/XeroConnection/`.
The bare command fails with a Symfony Finder error because `--scanDir` has no default.
