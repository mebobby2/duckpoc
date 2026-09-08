#!/usr/bin/env bash
#
# Measures raw GCS object-fetch latency with curl, entirely outside DuckDB.
#
# Why: DuckDB's httpfs reads a Parquet file in several sequential requests
# (footer, then metadata, then row groups), so a slow per-file figure could be
# either a slow network or several fast round trips. Timing single GETs with
# curl separates the two, and does it without DuckDB in the picture at all.
#
# Phase 1 opens a fresh connection per request (pays DNS + TCP + TLS).
# Phase 2 reuses one connection (tls=0 on reused requests), so the remainder
# is the true per-object server cost.
#
# Usage:  ./bin/gcs-latency-probe.sh [iterations]
#
set -euo pipefail

BUCKET="${GCS_PROBE_BUCKET:-bobby-poc}"
PREFIX="${GCS_PROBE_PREFIX:-duckpoc-oracle/}"
ITERATIONS="${1:-5}"

echo "Discovering objects under gs://${BUCKET}/${PREFIX} ..."
mapfile -t OBJECTS < <(
    gcloud storage ls -r "gs://${BUCKET}/${PREFIX}**.parquet" 2>/dev/null \
        | sed "s|gs://${BUCKET}/||"
)

if [[ ${#OBJECTS[@]} -eq 0 ]]; then
    echo "No .parquet objects found. Has duckdb:cashflow:flush been run?" >&2
    exit 1
fi

LOCATION="$(gcloud storage buckets describe "gs://${BUCKET}" --format='value(location)' 2>/dev/null || echo unknown)"
TOKEN="$(gcloud auth print-access-token)"

echo "Bucket location : ${LOCATION}"
echo "Objects found   : ${#OBJECTS[@]}"
echo

# --------------------------------------------------------------------------
# Phase 1 — fresh connection per request.
# --------------------------------------------------------------------------
echo "=== Phase 1: fresh connection per request ==="
echo "    dns / tcp / tls are cumulative; total is the whole fetch."
echo

for obj in "${OBJECTS[@]}"; do
    printf "  %s\n" "$(basename "${obj}")"
    for _ in $(seq 1 "${ITERATIONS}"); do
        curl -s -o /dev/null \
             -H "Authorization: Bearer ${TOKEN}" \
             -w "      dns:%{time_namelookup}s  tcp:%{time_connect}s  tls:%{time_appconnect}s  ttfb:%{time_starttransfer}s  total:%{time_total}s  (%{size_download} bytes)\n" \
             "https://storage.googleapis.com/${BUCKET}/${obj}"
    done
    echo
done

# --------------------------------------------------------------------------
# Phase 2 — one reused connection.
# curl needs its own -o for every URL, otherwise it warns and the write-out
# format desynchronises from the requests.
# --------------------------------------------------------------------------
echo "=== Phase 2: single reused connection ==="
echo "    tls:0 means the connection was reused, so total is the marginal"
echo "    per-object cost with handshake already paid."
echo

ARGS=()
for _ in $(seq 1 "${ITERATIONS}"); do
    for obj in "${OBJECTS[@]}"; do
        ARGS+=(-o /dev/null "https://storage.googleapis.com/${BUCKET}/${obj}")
    done
done

curl -s -H "Authorization: Bearer ${TOKEN}" \
     -w "      tls:%{time_appconnect}s  ttfb:%{time_starttransfer}s  total:%{time_total}s\n" \
     "${ARGS[@]}"

cat <<'EOF'

Interpretation
  Phase 1 slow, Phase 2 fast     -> TLS/connection setup dominates; fix with
                                    connection pooling / keep-alive.
  Phase 1 ~= Phase 2 (both slow) -> per-object server cost dominates; a
                                    region-local bucket is the lever, not pooling.
  Both fast (< ~100ms)           -> GCS is fine, and the per-file cost seen
                                    inside DuckDB is its round-trip count.
EOF
