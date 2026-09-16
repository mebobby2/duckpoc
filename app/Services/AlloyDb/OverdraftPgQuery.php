<?php

declare(strict_types=1);

namespace App\Services\AlloyDb;

use Illuminate\Database\ConnectionInterface;

/**
 * Runs the overdraft statement against AlloyDB, and seeds the oracle it is
 * checked against.
 *
 * The oracle is Figured's own test scenario — one $1,000 expense and nothing
 * afterwards, so the cash position is flat and any growth in the charge can
 * only be interest compounding on itself. The same numbers the lake is checked
 * against, so all three implementations answer to one source of truth.
 */
final class OverdraftPgQuery
{
    public const string ORACLE_FARM_ID = 'overdraft-oracle-farm';
    public const string ORACLE_REGION = 'od-oracle';
    public const string ORACLE_ACCOUNT_ID = 'od-expense';
    public const int ORACLE_RATE = 50000;

    public function __construct(
        private readonly ConnectionInterface $db,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function run(string $farmId, string $periodFrom, string $periodTo, string $horizon): array
    {
        return array_map(
            static fn (object $row): array => (array) $row,
            $this->db->select((new OverdraftPgSqlBuilder())->build(), [
                'farm_id' => $farmId,
                'farm_id2' => $farmId,
                'farm_id3' => $farmId,
                'period_from' => $periodFrom,
                'period_from2' => $periodFrom,
                'period_to' => $periodTo,
                'period_to2' => $periodTo,
                'horizon' => $horizon,
                'horizon2' => $horizon,
            ])
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function sourceRows(string $farmId, string $periodFrom, string $periodTo, string $horizon, int $limit): array
    {
        return array_map(
            static fn (object $row): array => (array) $row,
            $this->db->select((new OverdraftPgSqlBuilder())->buildSourceRowsSql(), [
                'farm_id' => $farmId, 'period_from' => $periodFrom, 'period_to' => $periodTo,
                'horizon' => $horizon, 'horizon2' => $horizon, 'row_limit' => $limit,
            ])
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function sourceSummary(string $farmId, string $periodFrom, string $periodTo, string $horizon): array
    {
        $row = $this->db->selectOne((new OverdraftPgSqlBuilder())->buildSourceSummarySql(), [
            'farm_id' => $farmId, 'period_from' => $periodFrom, 'period_to' => $periodTo,
            'horizon' => $horizon, 'horizon2' => $horizon,
        ]);

        return $row === null ? [] : (array) $row;
    }

    public function sql(): string
    {
        return (new OverdraftPgSqlBuilder())->build();
    }

    /**
     * Figured's scenario, seeded into AlloyDB. Identical values to the lake's
     * oracle, so a divergence between the two engines is visible immediately.
     */
    public function seedOracle(string $paymentTerm = 'interest_only_monthly'): void
    {
        $this->db->statement('DELETE FROM transaction_lines WHERE farm_id = ?', [self::ORACLE_FARM_ID]);
        $this->db->table('overdrafts')->where('farm_id', self::ORACLE_FARM_ID)->delete();

        $this->db->table('farms')->upsert([[
            'farm_id' => self::ORACLE_FARM_ID, 'farm_type' => 'dairy', 'region' => self::ORACLE_REGION,
        ]], ['farm_id']);

        $this->db->table('accounts')->upsert([[
            'account_id' => self::ORACLE_ACCOUNT_ID,
            'account_name' => 'Overdraft Oracle Expense',
            'account_class' => 'EXPENSE',
            'account_category' => 'operating_expenses',
            'report_group' => null,
            'report_group_label' => null,
            'report_group_order' => 0,
            'line_order' => 0,
        ]], ['account_id']);

        $this->db->table('overdrafts')->insert([
            'farm_id' => self::ORACLE_FARM_ID, 'rate' => self::ORACLE_RATE, 'overdraft_limit' => 0,
            'start_date' => '2024-01-01', 'payment_term' => $paymentTerm,
        ]);

        $this->db->statement(
            "INSERT INTO transaction_lines
                (farm_id, farm_type, region, line_id, account_id, type, basis, date, amount, tracker_id)
             VALUES (?, 'dairy', ?, 'od-oracle-1', ?, 'actuals', 'cash', DATE '2024-01-15', ?, NULL)",
            [self::ORACLE_FARM_ID, self::ORACLE_REGION, self::ORACLE_ACCOUNT_ID, 1000 * 10000]
        );
    }

    /** @return list<int> Figured's pinned accrual, in x10,000 units. */
    public static function expectedMonthly(): array
    {
        return [41666, 41840, 42014, 42189, 42365, 42541, 42719, 42897, 43075, 43255, 43435, 43616];
    }
}
