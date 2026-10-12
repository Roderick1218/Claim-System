<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ClaimNumberService
{
    /**
     * Issues the next number, e.g. CLM-202610-00001.
     * Must be called inside a DB transaction: the sequence row is locked (SELECT ... FOR UPDATE)
     * so two concurrent requests can never receive the same number.
     */
    public function next(?\DateTimeInterface $at = null): string
    {
        $period = ($at ? Carbon::instance($at) : now())->format('Ym');

        DB::table('claim_sequences')->insertOrIgnore(['period' => $period, 'last_number' => 0]);

        $row = DB::table('claim_sequences')->where('period', $period)->lockForUpdate()->first();
        $number = $row->last_number + 1;

        DB::table('claim_sequences')->where('period', $period)->update(['last_number' => $number]);

        return sprintf('CLM-%s-%05d', $period, $number);
    }
}
