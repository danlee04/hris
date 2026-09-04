<?php

namespace Database\Seeders;

use App\Models\LeaveType;
use Illuminate\Database\Seeder;

/**
 * The thirteen types printed on DTRC's CS Form No. 6 (Revised 2020), in the
 * order they appear on it, plus Wellness Leave.
 *
 * Wellness has no box on the form; it prints on the "Others:" line. It is the
 * hospital's own grant to job order and contract of service staff, who earn no
 * statutory credits at all.
 */
class LeaveTypeSeeder extends Seeder
{
    /** @var list<string> */
    private const REGULAR = ['permanent', 'coterminous'];

    /** @var list<string> */
    private const CASUAL = ['job_order', 'contract_of_service'];

    public function run(): void
    {
        $types = [
            ['VL', 'Vacation Leave', 'Sec. 51, Rule XVI, Omnibus Rules Implementing E.O. No. 292', 'vacation', '1.25', null],
            ['FL', 'Mandatory/Forced Leave', 'Sec. 25, Rule XVI, Omnibus Rules Implementing E.O. No. 292', 'vacation', null, null],
            ['SL', 'Sick Leave', 'Sec. 43, Rule XVI, Omnibus Rules Implementing E.O. No. 292', 'sick', '1.25', null],
            ['ML', 'Maternity Leave', 'R.A. No. 11210 / IRR issued by CSC, DOLE and SSS', null, null, null],
            ['PL', 'Paternity Leave', 'R.A. No. 8187 / CSC MC No. 71, s. 1998, as amended', null, null, null],
            ['SPL', 'Special Privilege Leave', 'Sec. 21, Rule XVI, Omnibus Rules Implementing E.O. No. 292', 'spl', null, 3],
            ['SOLO', 'Solo Parent Leave', 'RA No. 8972 / CSC MC No. 8, s. 2004', 'solo_parent', null, 7],
            ['STUDY', 'Study Leave', 'Sec. 68, Rule XVI, Omnibus Rules Implementing E.O. No. 292', null, null, null],
            ['VAWC', '10-Day VAWC Leave', 'RA No. 9262 / CSC MC No. 15, s. 2005', null, null, null],
            ['REHAB', 'Rehabilitation Privilege', 'Sec. 55, Rule XVI, Omnibus Rules Implementing E.O. No. 292', null, null, null],
            ['SLBW', 'Special Leave Benefits for Women', 'RA No. 9710 / CSC MC No. 25, s. 2010', null, null, null],
            ['CALAMITY', 'Special Emergency (Calamity) Leave', 'CSC MC No. 2, s. 2012, as amended', null, null, null],
            ['ADOPTION', 'Adoption Leave', 'R.A. No. 8552', null, null, null],
        ];

        foreach ($types as $order => [$code, $name, $basis, $ledger, $accrual, $grant]) {
            LeaveType::updateOrCreate(['code' => $code], [
                'name' => $name,
                'legal_basis' => $basis,
                'ledger' => $ledger,
                'accrual_days_per_month' => $accrual,
                'grant_days_per_year' => $grant,
                // SPL and Solo Parent are a fixed number of days a year,
                // forfeited if unused. Nothing carries into the next year.
                'grant_carries_over' => false,
                'applies_to' => self::REGULAR,
                'sort_order' => $order + 1,
            ]);
        }

        LeaveType::updateOrCreate(['code' => 'WELLNESS'], [
            'name' => 'Wellness Leave',
            'legal_basis' => 'DTRC policy',
            'ledger' => 'wellness',
            'grant_days_per_year' => 5,
            // The hospital's own leave, so the hospital decides. Set to
            // forfeit like the CSC grants until HR says otherwise; the Leave
            // types screen changes it without a deployment.
            'grant_carries_over' => false,
            'notice_days' => 5,
            'max_consecutive_days' => 3,
            'applies_to' => self::CASUAL,
            'sort_order' => 90,
        ]);
    }
}
