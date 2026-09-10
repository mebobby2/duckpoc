<?php
$farm = \Illuminate\Support\Facades\DB::table('farms')->where('farm_id', 'gm-dairy-farm-500m')->first();
$db = app(\Saturio\DuckDB\DuckDB::class);
$n = (int) getenv('PROBE_THREADS');
$db->query("SET threads={$n}");
$q = new \App\Services\CashFlow\GrossMarginV2Query($db, config('duckdb.attached_alias'));
$t = microtime(true);
$q->run($farm->farm_id, $farm->farm_type, $farm->region, '2024-01-01', '2027-12-31', '2026-08-31', 'cash');
printf("threads=%-3d full-span = %6.0f ms\n", $n, (microtime(true) - $t) * 1000);
