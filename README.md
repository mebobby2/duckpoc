# duckpoc

A standalone Laravel 11 / PHP 8.3 app — same core stack as [Figured](https://github.com/figured/figured-webapp)
— set up to prove out a DuckDB + DuckLake + GCS architecture as a proof of
concept, ahead of a possible rewrite of Figured's reporting engine.

This is infrastructure/plumbing only: DuckDB connectivity, DuckLake catalog
attach, GCS auth, MySQL for ordinary Laravel app data. It does not yet
contain any report logic (Cash Flow, Overdraft interest, etc.) — that's the
next layer to build on top of this.

## Stack

| Piece | What | Why |
|---|---|---|
| PHP 8.3, Laravel 11 | Standard Laravel app | Matches Figured's stack |
| MySQL 8.0 | Laravel's own app DB (`DB_CONNECTION`) | Matches how Figured uses MySQL — app/config data, not report data |
| DuckDB | Queried via [`satur.io/duckdb`](https://github.com/satur-io/duckdb-php) (FFI binding to the official C API) | No first-party PHP client exists; this is the most-adopted community one (148k+ installs) |
| DuckLake | A DuckDB extension, attached at runtime | Catalog/table format over Parquet in GCS |
| GCS | Data files (`gs://…`), authenticated via HMAC keys | See gotcha below — this is **not** service-account JSON auth |

## First-time setup

```bash
docker compose build
docker compose up -d mysql
docker compose run --rm app composer install   # already run once during scaffolding; re-run after pulling changes
cp .env.example .env   # already done — edit as needed
docker compose run --rm app php artisan migrate
```

Then smoke-test the DuckDB/DuckLake chain:

```bash
docker compose run --rm app php artisan duckdb:test
```

With `GCS_BUCKET` left empty in `.env`, this runs entirely locally (SQLite
catalog under `storage/ducklake/`, Parquet data under
`storage/ducklake/data/`) — **zero cloud setup required** to confirm the
mechanics work. This has been verified to pass end-to-end, including a
second run reusing the same catalog (row count correctly persists and
increments across separate container invocations — i.e. DuckLake's
transactional write path is genuinely durable across process restarts, not
just within one).

To point it at real GCS, set in `.env`:

```
GCS_BUCKET=your-bucket-name
GCS_DATA_PATH_PREFIX=duckpoc/
GCS_KEY_ID=...
GCS_SECRET=...
```

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
   Interoperability in the GCP console. Some org policies disable HMAC key
   creation by default — if `GCS_KEY_ID`/`GCS_SECRET` auth fails outright,
   that's the first thing to check.

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

**Verified by actually running it, in this environment:**
- FFI extension loads, native DuckDB library installs and runs a query (`SELECT version()`)
- `ducklake` and `httpfs` extensions install and load
- SQLite-catalog DuckLake attach, `CREATE TABLE` / `INSERT` / `SELECT count(*)` round trip
- Catalog persistence and correct row accumulation across two separate `docker compose run` invocations (proves durable, transactional small-write behavior, not just in-memory)
- MySQL connectivity and `php artisan migrate`

**Not yet verified (needs real credentials/infra to test):**
- GCS auth and a real `gs://` `DATA_PATH` (no bucket/HMAC keys available in this environment)
- Postgres or MySQL as the DuckLake catalog backend
- Anything at actual data volume — this only proves the plumbing works, not that it performs

## Next steps

1. Wire up real GCS credentials and confirm the same smoke test passes against `gs://`.
2. Build the synthetic data generator (farm/accounts schema, `farm_id=X/year=Y` Hive partitioning) discussed in the architecture conversation this PoC came out of.
3. Port the Cash Flow report's aggregation + running-balance logic to DuckDB SQL.
4. Only then: Overdraft interest, built on top of the Cash Flow output.
