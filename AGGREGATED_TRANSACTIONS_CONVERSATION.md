# Aggregated transactions, and what the PoC seed data actually models

Verbatim conversation export. Starts with the difference between a raw
transaction and an aggregated one, and ends with a correction about what the
DuckDB/AlloyDB PoC seed data does and does not represent.

---

## Bobby

what's the diff between a transaction and an aggregated transaction? What optimization does aggregated transactions give?

---

## Claude

Two different *shapes*, not just two copies of the same thing.

### Raw `transactions`

One document per real-world **event** — an invoice, a journal, a payment — with a `lines[]` **array** inside it:

```jsonc
{
  farm_id: 240, type: "actuals", budget_id: 0,
  date, cash_date, accrual_date, tags: [...],
  source_id, source_type, transaction_number, group_id,   // provenance
  _valid_from, _valid_to,                                  // history/versioning
  lines: [
    { id, account_id, account_name, basis: "cash",
      date: ISODate("2024-03-31..."),                      // the real date
      net_amount, gross_amount, tax_amount, tracking: [] },
    { ... }                                                 // ~3.67 of these
  ]
}
```

### `aggregated_transactions`

One document per unique **combination of reporting dimensions**, with the amounts already summed. `lines` is a single object, not an array:

```jsonc
{
  farm_id: 123, snapshot_id: 0, budget_id: 0,
  type: "actuals", actuals_basis: "cash",
  group_hash: "actuals|cash|2024|7|1100|tag-a,tag-b|opt-1,opt-2",
  lines: {
    account_id: "1100", basis: "cash",
    date: ISODate("2024-07-15"),        // normalised to the 15th
    net_amount: 12345600,               // already summed
    tracking: [...]
  }
}
```

The key is `group_hash`: `{type}|{basis}|{year}|{month}|{account_id}|{tags}|{trackings}`.

### The optimisation, precisely

It is **the report's own `$group` stage, computed in advance**.

The live pipeline does `$match → $unwind lines → $match → $group by (account, interval, trackings) → sum`. Aggregated transactions does the `$unwind` and `$group` ahead of time, so the report collapses to a `$match` and a read of pre-summed rows.

The win is entirely **documents not scanned**, and the multiple is the average number of raw lines collapsing into one (month × account × basis × type × tags × tracking) bucket. On a farm with a 50-account chart over 24 months, a million journal lines might collapse to tens of thousands of rows — hence the README's "orders of magnitude."

### What it costs

- **Month granularity only.** Every date becomes the 15th. A period that doesn't align to month boundaries can't use it — which is why the read gate takes the period as an argument: `useAggregatedTransactions($structure->getPeriod())`.
- **No provenance.** No `source_id`, no invoice, no line ids. You cannot drill down.
- **It has to be maintained.** A second write path — app-side integration plus an Atlas stream processor — with a daily healthcheck and documented failure modes. Reports only (`budget_id: 0`).

### Why this supports your position rather than threatening it

You called it an optimization, and the shape confirms it exactly: it's a **materialized view of the group-by**, hand-built and separately maintained.

Every engine in the comparison can have the same thing. DuckLake can hold pre-summed Parquet. AlloyDB's columnar engine already gets much of this effect *automatically*, with no second collection, no stream processor and no staleness window — it's the same rows, just stored column-wise and aggregated inside the scan.

So the honest framing is: Mongo needs a bolted-on, asynchronously-maintained cache to do what the other engines do to the base data. Turning it on multiplies every column in your table by roughly the same factor and leaves the ranking intact — which is why measuring raw was the right call.

---

## Bobby

so, it groups by the account, interval, and trackings and sums all the net amounts in the lines array? I do not understand how this reduces the documents. Can u give me a small worked example

---

## Claude

Sure — the reduction doesn't happen *within* a document, it happens **across** them. That's the bit that's easy to miss.

### The raw side — July 2024, one farm

Four feed invoices and one milk payment. Five documents, ten lines:

```jsonc
// Doc 1 — invoice 1001
{ date: 2024-07-03, type: "actuals", lines: [
    { account_id: "1100 Feed",    basis: "cash", date: 2024-07-03, net_amount: 120000, tracking: ["MA Cows"] },
    { account_id: "2000 Payable", basis: "cash", date: 2024-07-03, net_amount: -120000, tracking: [] } ]}

// Doc 2 — invoice 1002
{ date: 2024-07-11, ..., lines: [ { "1100 Feed", ..., net_amount:  95000, tracking: ["MA Cows"] }, { "2000 Payable", ... } ]}

// Doc 3 — invoice 1003
{ date: 2024-07-19, ..., lines: [ { "1100 Feed", ..., net_amount: 143000, tracking: ["MA Cows"] }, { "2000 Payable", ... } ]}

// Doc 4 — invoice 1004
{ date: 2024-07-26, ..., lines: [ { "1100 Feed", ..., net_amount:  88000, tracking: ["MA Cows"] }, { "2000 Payable", ... } ]}

// Doc 5 — milk payment
{ date: 2024-07-20, ..., lines: [ { "4000 Milk Income", ..., net_amount: -850000, tracking: ["Fonterra Supply"] }, ... ]}
```

To answer *"what did MA Cows feed cost in July?"* the report must fetch all five documents, `$unwind` ten lines, keep the four that match account 1100, and sum them: **446,000**.

### The aggregated side

Those four feed lines share one `group_hash` — same type, basis, year, month, account, tracking — so they become **one** document:

```jsonc
{ group_hash: "actuals|cash|2024|7|1100||MA Cows",
  lines: { account_id: "1100 Feed", basis: "cash",
           date: 2024-07-15,          // normalised
           net_amount: 446000,        // 120000+95000+143000+88000, already summed
           tracking: ["MA Cows"] } }
```

Five raw documents become three aggregated ones — feed, payable, milk income. To answer the same question the report now fetches **one** document and reads `net_amount`.

### Why it scales

At five documents that's barely a win. The point is what happens next:

| feed invoices in July | raw docs | raw lines unwound | aggregated docs |
|---|---|---|---|
| 4 | 5 | 10 | 3 |
| 100 | 101 | 202 | **3** |
| 10,000 | 10,001 | 20,002 | **3** |

**Raw grows with transaction count. Aggregated grows with distinct dimension combinations** — accounts × months × basis × type × tags × trackings — which is bounded by the shape of the chart of accounts and the reporting period, not by how busy the farm is.

A fifth feed invoice adds nothing. A *different account*, a *different month*, or a *different tracker* adds one bucket each.

### On our actual farm

Farm 240 at 25M lines is 6.8M documents. Its dimension space is roughly 14 accounts × 48 months × 5 trackers ≈ **3,400 buckets**. So the report would read ~3,400 documents instead of 6.8M — about **2,000×** fewer.

That's where "orders of magnitude" comes from, and why it collapses a 10-second scan to something near-instant.

Two honest qualifications: my amplified data is artificially low-diversity because the clones repeat the same dimensions, so real farms bucket less tightly — more tags, more tracking combinations. And it only works when the report's intervals align to months, since every date is flattened to the 15th; that's what the period argument on the read gate is checking.

---

## Bobby

elaborate on the 15th? why it was chosen and the period argument need to match?

---

## Claude

Good question, and it corrects something I said. The gate checks the **horizon specifically**, not the period generally:

```php
// Period horizon must be on a month boundary
if ($horizon = $period->getHorizon()) {
    // getHorizon() hands back the period's own mutable Carbon, so the boundary check works on a copy.
    if (!$horizon->copy()->endOfMonth()->endOfDay()->eq($horizon)) {
        return false;
    }
}
```

The period's start and end aren't checked at all. Only the horizon, and only that it lands on the last moment of a month.

### Why the horizon is the thing that matters

The horizon is the actuals/forecast split. The report's scope predicate is effectively *"actuals up to the horizon, forecast after it"* — so every row has to fall unambiguously on one side.

An aggregated row **is a whole month**. If the horizon were 15 July, July would need splitting: actuals for the 1st–15th, forecast for the 16th–31st. You can't split a pre-summed monthly bucket without re-reading the raw lines, which defeats the point. So the gate refuses.

A month-boundary horizon is the only case where every monthly bucket lands cleanly on one side.

### Why the 15th

Now the two pieces connect: since every aggregated row is dated the 15th, and every permitted horizon is a month boundary, **no comparison can ever land on a stored date**. The 15th is the point furthest from either edge of the month.

That matters because of how these dates actually look. A raw transaction date in the dev data:

```
ISODate('2024-03-31T10:59:59.000Z')
```

That's 31 March 23:59:59 **NZDT** stored in UTC. Figured keeps farm-local end-of-day, so a month boundary is exactly where timezone conversion bites — 2024-03-31T11:00:00Z is already April in Auckland. The README names both hazards:

> normalised to the **15th of the month** in the farm's timezone. This makes month-keyed lookups straightforward and avoids DST/end-of-month edge cases.

- **End-of-month.** Months are 28/29/30/31 days. Normalising to "the last day" means month-length arithmetic at every comparison site; normalising to the 1st puts you one hour from the previous month. The 15th exists in every month and needs no arithmetic.
- **DST.** New Zealand shifts an hour in late September and early April. A date pinned to midnight on the 1st can slip backwards into the previous month under conversion. Pinned to the 15th, it can slip an hour in either direction and still be indisputably in the same month.

So the 15th isn't arbitrary or cosmetic — it's a **12-day safety margin on each side**, chosen so that no boundary comparison, timezone conversion or DST shift can misclassify a row.

### One thing the gate doesn't check

It validates the horizon but not the report's **interval granularity**. Since every aggregated row is dated the 15th, a report with sub-month intervals — weekly, or a period not starting on the 1st — would have all of a month's value land in whichever interval contains the 15th.

I haven't traced `MongoIntervalBuilder` to see whether such intervals are reachable for reports that pass this gate, so I'm flagging it as a question rather than a defect. It may simply be that every report using this path is monthly-or-longer by construction. Worth confirming before anyone enables the beta flag on a farm that uses unusual period settings.

---

## Bobby

does this invalidate our duckdb and alloyDB poc? The seed data used in the pocs did not use aggregated transactions, instead they all used transactions with lines of length 1. So the unwind and groups are not tested? Is this true?

---

## Claude

Sharp question. Partly yes, partly no — and the two halves point in opposite directions.

### No for DuckDB and AlloyDB

`$unwind` is a **MongoDB-specific step**. It exists only because Mongo stores a transaction as a document with a nested `lines[]` array. DuckDB and AlloyDB store `transaction_lines` — one row per journal line — which is the natural relational and columnar shape. There's no array to unwind, so nothing is untested; the step is inapplicable rather than skipped.

And the `GROUP BY` absolutely is tested. The PoC's SQL aggregates 25M (and 1B) rows down to ~600 output rows through `GROUPING SETS`. That's the same unit of work Figured's `$group` does. Both sides process **25M journal lines** — Figured just reaches them via 6.8M documents.

### Yes for the PoC's Mongo baseline

That one is genuinely unrepresentative, and it's the correction I flagged after the 1M run: flat one-line-per-document makes Mongo decode 25M documents where Figured decodes 6.8M. Measured, that's **23,772 ms vs 9,648 ms — 2.5× too slow**. The duckpoc docblock claiming the flat shape "spares Mongo a stage" has it backwards and should be fixed.

### What's genuinely missing from the PoC fact table

The PoC row is narrower than a Figured line:

| Figured line/doc | PoC `transaction_lines` |
|---|---|
| `account_id`, `basis`, `date`, `net_amount` | equivalents present |
| `gross_amount`, `tax_amount` | absent |
| `tags[]`, `budget_id`, `snapshot_id` | absent |
| `_valid_to` history filter | absent |
| `tracking[]` — **an array** | `tracker_id` — **a scalar** |

The first three groups bias in the PoC's favour, but **barely** — and this is the key point. Columnar stores read only the columns a query references. Adding `gross_amount`, `tax_amount` and `tags` to the PoC schema would change AlloyDB's and DuckDB's scan time by roughly nothing, because the Gross Margin query never touches them. For Mongo, every extra field is decoded whether the query wants it or not.

So the asymmetry **reinforces** the conclusion rather than undermining it. Mongo pays for width; columnar engines don't.

### The one thing that is genuinely untested

**Multi-value tracking.** Figured's lines carry `tracking[]` — a set of tracking options — and the report groups by that set. The PoC has a single `tracker_id`.

That's not a free simplification. Doing it properly in DuckDB or AlloyDB means either a `LIST` column with `unnest`, or a bridge table with a join — and grouping by a *set* rather than a scalar is real work that would cost something. Nobody has measured it.

If you want one more experiment before this goes to Dave, that's the one I'd run: add a tracking array to the PoC fact table and re-measure GM V2. It's the only structural difference that could plausibly move the numbers.

### Also still absent, and unmeasured on every stack

Virtual journals, the valuation movement section, and history/snapshot filtering. Figured's real report pays for all three; no PoC stack does. Those push the other way — they make Figured's measured numbers *pessimistic* relative to a like-for-like.

**Net:** the DuckDB/AlloyDB numbers stand, the Mongo baseline needs its 2.5× correction, and the honest caveat list is "narrower rows, scalar tracking, no virtual journals" — with the first two favouring the PoC and the third favouring Figured.

---

## Bobby

Elaborate on "It exists only because Mongo stores a transaction as a document with a nested lines[] array. DuckDB and AlloyDB store transaction_lines — one row per journal line — which is the natural relational and columnar shape. There's no array to unwind, so nothing is untested; the step is inapplicable rather than skipped." - you mean the poc seed data alreay uses journals with multiple lines but its just stored in parquet as one line per journal due to columnar storage?

---

## Claude

No — and I need to correct that, because the PoC is a bigger simplification than "same journals, stored flat."

### The PoC has no journal entity at all

Look at how it generates data: `line_id` is `gm-feed-supplement-202401-0`, `-1`, `-2`… Each row is an independent line, one per (account, month, fan-out index). There is **no transaction grouping them, no double-entry, no balancing counterpart**. It's a flat stream of P&L lines that were never nested in the first place.

So it isn't "journals with 3.67 lines, normalised into rows." It's "3.67× fewer things, each of which is a report-relevant line."

### And here's what those extra Figured lines actually are

I just counted them on the seeded farm — 22,699 lines across 6,188 documents:

| lines | account |
|---|---|
| 6,990 | Accounts Payable |
| 6,004 | Farm Current Account |
| 2,986 | GST |
| 504 | Dairy Shed Expenses |
| 428 | Weed & Pest Control |
| 428 | Accounts Receivable |
| 406 | Animal Health |
| … | … |

**Those top three alone are 70% of all lines** — and they are balance-sheet control accounts. The Gross Margin report `$unwind`s them and then throws them away at the next `$match`.

So a Figured invoice is: one expense line the report wants, plus a payable line and a GST line it doesn't. The PoC generates only the first kind.

### This revises what I told you

My earlier claim — "the PoC's Mongo baseline understates Mongo by 2.5×" — was measured on a *raw line* basis and is only half the story. Two effects run in opposite directions:

| effect | direction | size |
|---|---|---|
| Document density (3.67 lines/doc amortises BSON overhead) | helps Figured | ~2.3× |
| Wasted unwinding (70% of lines discarded after unwind) | hurts Figured | ~3.4× |

Per **report-relevant** line, Figured's nested Mongo is actually ~1.45× *slower* than the PoC's flat Mongo — the opposite of what I said. The density advantage is more than cancelled by scanning lines the report doesn't want.

So: the PoC's Mongo baseline isn't uniformly pessimistic. It's pessimistic per raw line and optimistic per useful line.

### What this means for DuckDB and AlloyDB

My original statement still holds — there's no array to unwind in a columnar store, so that step isn't "missing." But the fidelity gap is real and it's **not** the unwind. It's this:

**A faithful model of a farm with 25M Figured journal lines would give DuckDB and AlloyDB ~25M rows too — of which only ~7.4M are report accounts.** The PoC gave them 25M rows that are *all* report accounts.

Framed by total journal lines in the system (probably what Dave means by "25M transactions"), the comparison stands as measured. Framed by useful work done, the PoC engines were handed ~3.4× more relevant data per row than Figured has.

Either framing is defensible — but it should be stated, not left implicit. The cheap fix is to add balancing and GST lines to the PoC seeder so the ratios match; in a columnar store the extra rows are filtered by `account_id` on the scan, so I'd expect it to cost DuckDB and AlloyDB far less than it costs Mongo. That expectation is exactly the kind of thing this session has taught me to measure rather than assert.

---

## Bobby

I still do not full understand "A faithful model of a farm with 25M Figured journal lines would give DuckDB and AlloyDB ~25M rows too — of which only ~7.4M are report accounts. The PoC gave them 25M rows that are all report accounts." what is a report account? Why is it 7.4M smaller?

---

## Claude

Fair — I skipped the bookkeeping, and that's where it comes from.

### What a "report account" is

Every journal line is posted to an **account** from the farm's chart of accounts. Those split into two kinds:

- **P&L accounts** — Feed - Supplements, Animal Health, Milk Income, Livestock Sales. These are income and expenses.
- **Balance sheet accounts** — Accounts Payable, Farm Current Account (the bank), GST, Accounts Receivable. These track what you owe, own, and hold.

The Gross Margin report shows Income and Direct Costs for tracker enterprises. It wants **only the P&L accounts mapped to trackers**. It has no use for the bank account or the GST liability.

In the PoC's SQL that concept is literally one line:

```sql
account_scope AS (SELECT ... FROM accounts WHERE report_group IS NOT NULL)
```

An account with a `report_group` is a report account. The other kind isn't.

### Why real journals are mostly the other kind

Double-entry. Every transaction must balance. The farmer buys $1,200 of feed on credit:

| line | account | amount | does GM want it? |
|---|---|---|---|
| 1 | Feed - Supplements | DR 1,200 | **yes** |
| 2 | GST | DR 180 | no |
| 3 | Accounts Payable | CR 1,380 | no |

One useful line out of three. Then the farmer pays that bill a month later:

| line | account | amount | does GM want it? |
|---|---|---|---|
| 1 | Accounts Payable | DR 1,380 | no |
| 2 | Farm Current Account | CR 1,380 | no |

**Zero** useful lines — but it's still a document the report opens, unwinds, and discards.

That's why the line counts on farm 240 look the way they do: Accounts Payable 6,990, Farm Current Account 6,004, GST 2,986. The bookkeeping plumbing vastly outnumbers the lines anyone reports on.

### The 7.4M

Measured on the seeded farm: the three control accounts alone are 15,980 of 22,699 lines — **70.4%**. So roughly **29.6%** are report accounts.

Apply that to the benchmarked volume:

```
25,014,298 total lines  x  0.296  =  ~7.4M report-account lines
```

Figured's Mongo opens 6.8M documents, unwinds 25M lines, and keeps about 7.4M of them.

### What the PoC does instead

The PoC seeder generates **only** the useful kind. It creates 14 accounts, every one of which has a `report_group`, and fans out lines directly against them. No payables, no GST, no bank, no balancing counterpart — there's no double-entry at all.

So its 25M rows are 25M report-account lines.

### The mismatch, stated plainly

| | lines stored | lines the report can use |
|---|---|---|
| Figured at 25M | 25.0M | ~7.4M |
| PoC at 25M | 25.0M | 25.0M |

Same headline number, different meaning. Either the PoC's farm is **~3.4x busier** than Figured's for the same stated volume, or Figured's engine is being asked to wade through 3.4x more chaff to reach the same answer.

Both statements are true; which one to lead with depends on whether "25M transactions" means *lines in the database* (then the comparison stands as measured) or *business activity* (then the PoC farm is bigger).

The fix is cheap and I'd do it before this goes out: have the PoC seeder emit the payable, GST and bank lines alongside each P&L line, so the 30% ratio matches. In a columnar store those extra rows get filtered on the scan by `account_id` and should cost very little — but as this session keeps demonstrating, that's a prediction to measure rather than assert.

---

## Open items this raised

1. **Fix the duckpoc docblock** in `MongoJournalQuery` — it claims the flat one-line-per-document shape "spares Mongo a stage" and makes the baseline conservative. Both halves are wrong; the effects run in opposite directions and roughly cancel, landing ~1.45× optimistic per useful line.
2. **Add balancing + GST lines to the PoC seeder** so the report-relevant fraction matches Figured's ~30%, then re-measure. Expected to cost columnar engines little and Mongo a lot — unmeasured.
3. **Multi-value tracking** — Figured groups by a *set* of tracking options; the PoC uses a scalar `tracker_id`. Needs a LIST column with `unnest` or a bridge table. Unmeasured.
4. **Interval granularity vs the 15th** — the aggregated-transactions read gate checks the horizon but not interval granularity. Worth confirming no sub-month interval can reach that path.
