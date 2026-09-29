<?php

declare(strict_types=1);

namespace App\Services\Insights;

/**
 * What a practice's farms look like: the type mix, the long tail of sizes,
 * and the chart of accounts each type carries.
 *
 * These are the PoC's starting assumptions, written down in one place so they
 * can be replaced by production distributions without touching the seeder.
 * Sizes are in economic transactions per season, not lines: every transaction
 * fans out into ~8 lines once its GST, bank and payable legs are written on
 * both bases, which is roughly the 27.7% report-line ratio measured on real
 * Figured data.
 */
final class PracticeShape
{
    public const string TYPE_DAIRY = 'dairy';
    public const string TYPE_DAIRY_LARGE = 'dairy_large';
    public const string TYPE_SHEEP_BEEF = 'sheep_beef';
    public const string TYPE_ARABLE = 'arable';

    /**
     * [type, share out of 100, farm type name, financial year end month].
     * NZ dairy balances in May, everyone else in June.
     */
    public const array TYPE_MIX = [
        [self::TYPE_DAIRY, 65, 'Dairy', 5],
        [self::TYPE_DAIRY_LARGE, 5, 'Dairy', 5],
        [self::TYPE_SHEEP_BEEF, 20, 'Sheep & Beef', 6],
        [self::TYPE_ARABLE, 10, 'Arable', 6],
    ];

    /**
     * [band, share out of 100, transactions per season, scale against a
     * medium farm]. The largest real farm is ~800K lines over its lifetime;
     * the very-large band is on course for that.
     */
    public const array SIZE_BANDS = [
        ['small', 40, 1_500, 0.3],
        ['medium', 40, 5_000, 1.0],
        ['large', 15, 20_000, 4.0],
        ['very_large', 5, 75_000, 15.0],
    ];

    /** kgMS a medium dairy farm produces in a season (~400 cows). */
    public const int MEDIUM_FARM_KGMS = 150_000;

    /** $/kgMS, fixed point x10000. */
    public const int MILK_PRICE = 95_000;

    /** Share of a season's milk by calendar month, January first. */
    public const array MILK_CURVE = [9.5, 8.5, 8.0, 6.0, 2.5, 0.3, 1.5, 7.5, 11.0, 13.0, 12.5, 11.0];

    public const array REGIONS = [
        'Northland', 'Auckland', 'Waikato', 'Bay of Plenty', 'Taranaki', 'Manawatu-Whanganui',
        "Hawke's Bay", 'Wellington', 'Tasman', 'Canterbury', 'West Coast', 'Otago', 'Southland',
    ];

    public const array MILK_COMPANIES = ['fonterra', 'fonterra', 'fonterra', 'opencountry', 'westland', 'synlait', 'miraka'];

    /**
     * The chart every farm of a type gets, beyond its milk income accounts.
     *
     * [code, name, class, type, system account, category group, system
     * category name, farm types, share of transactions, monthly dollars on a
     * medium farm, carries GST]. Group and category name are what the
     * portfolio statement classifies on, exactly as FIP classifies on the
     * report's categorisation.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: ?string, 5: string, 6: string, 7: list<string>, 8: float, 9: int, 10: bool}>
     */
    public const array ACCOUNTS = [
        ['210', 'Livestock Sales', 'REVENUE', 'REVENUE', null, 'income', 'Livestock Sales', ['dairy', 'dairy_large'], 0.02, 15_000, true],
        // Livestock is a sheep and beef farm's main income, not a sideline.
        ['210', 'Livestock Sales', 'REVENUE', 'REVENUE', null, 'income', 'Livestock Sales', ['sheep_beef'], 0.04, 95_000, true],
        ['215', 'Wool Sales', 'REVENUE', 'REVENUE', null, 'income', 'Wool', ['sheep_beef'], 0.01, 20_000, true],
        ['220', 'Crop Sales', 'REVENUE', 'REVENUE', null, 'income', 'Crop Income', ['arable'], 0.03, 135_000, true],
        ['260', 'Other Income', 'REVENUE', 'OTHERINCOME', null, 'income', 'Other Income', ['*'], 0.02, 3_000, true],
        ['400', 'Wages', 'EXPENSE', 'EXPENSE', null, 'operating_expenses', 'Wages', ['*'], 0.06, 30_000, false],
        ['405', 'Animal Health', 'EXPENSE', 'DIRECTCOSTS', null, 'operating_expenses', 'Animal Health', ['dairy', 'dairy_large', 'sheep_beef'], 0.10, 8_000, true],
        ['410', 'Breeding', 'EXPENSE', 'DIRECTCOSTS', null, 'operating_expenses', 'Breeding', ['dairy', 'dairy_large'], 0.04, 4_000, true],
        ['415', 'Shed Expenses', 'EXPENSE', 'DIRECTCOSTS', null, 'operating_expenses', 'Shed Expenses', ['dairy', 'dairy_large'], 0.06, 3_000, true],
        ['420', 'Fertiliser', 'EXPENSE', 'DIRECTCOSTS', null, 'operating_expenses', 'Fertiliser', ['*'], 0.06, 15_000, true],
        ['425', 'Supplementary Feed', 'EXPENSE', 'DIRECTCOSTS', null, 'operating_expenses', 'Supplements', ['dairy', 'dairy_large', 'sheep_beef'], 0.08, 20_000, true],
        ['430', 'Seeds & Sprays', 'EXPENSE', 'DIRECTCOSTS', null, 'operating_expenses', 'Cropping', ['arable'], 0.08, 18_000, true],
        ['435', 'Repairs & Maintenance', 'EXPENSE', 'EXPENSE', null, 'operating_expenses', 'Repairs', ['*'], 0.12, 9_000, true],
        ['440', 'Electricity', 'EXPENSE', 'OVERHEADS', null, 'operating_expenses', 'Electricity', ['*'], 0.03, 5_000, true],
        ['445', 'Freight', 'EXPENSE', 'EXPENSE', null, 'operating_expenses', 'Freight', ['*'], 0.06, 3_000, true],
        ['450', 'Vehicle Expenses', 'EXPENSE', 'EXPENSE', null, 'operating_expenses', 'Vehicle Expenses', ['*'], 0.10, 4_000, true],
        ['455', 'Administration', 'EXPENSE', 'OVERHEADS', null, 'operating_expenses', 'Administration', ['*'], 0.08, 2_000, true],
        ['460', 'Insurance', 'EXPENSE', 'OVERHEADS', null, 'operating_expenses', 'Insurance', ['*'], 0.02, 2_500, true],
        ['465', 'Rates', 'EXPENSE', 'OVERHEADS', null, 'operating_expenses', 'Rates', ['*'], 0.01, 2_000, true],
        ['470', 'Interest Paid', 'EXPENSE', 'EXPENSE', null, 'non_operating_expenses', 'Interest', ['*'], 0.01, 10_000, false],
        ['480', 'Interest Received', 'REVENUE', 'OTHERINCOME', null, 'non_operating_income', 'Interest Received', ['*'], 0.01, 300, false],
        ['710', 'Plant & Machinery', 'ASSET', 'FIXED', null, 'non_operating_movements', 'Capital Purchases', ['*'], 0.01, 6_000, true],
        ['800', 'Term Loan', 'LIABILITY', 'TERMLIAB', null, 'non_operating_movements', 'Loan Repayments', ['*'], 0.01, 8_000, false],
        ['970', 'Drawings', 'EQUITY', 'EQUITY', null, 'equity_movements', 'Drawings', ['*'], 0.02, 6_000, false],
    ];

    /**
     * The accounts only the double entry writes to. [code, name, class, type,
     * system account, category group].
     */
    public const array SYSTEM_ACCOUNTS = [
        'bank' => ['090', 'Farm Cheque Account', 'ASSET', 'BANK', null, 'bank'],
        'gst' => ['820', 'GST', 'LIABILITY', 'CURRLIAB', 'GST', 'gst'],
        'gst_payments' => ['821', 'GST Payments / Refunds', 'LIABILITY', 'CURRLIAB', 'GSTPAYMENTS', 'gst'],
        'payables' => ['810', 'Accounts Payable', 'LIABILITY', 'CURRLIAB', 'CREDITORS', 'liabilities'],
        'opening' => ['980', 'Opening Balances', 'EQUITY', 'EQUITY', null, 'equity_movements'],
    ];

    /** Milk supplies by size band: one for a small farm, more as it grows. */
    public const array MILK_TRACKERS_BY_BAND = ['small' => 1, 'medium' => 2, 'large' => 3, 'very_large' => 5];

    /** The corporate dairy type runs many supplies whatever its size. */
    public const int MILK_TRACKERS_LARGE_HERD = 8;
}
