<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ClaimNumberService
{
    // 发下一个单号，例如 CLM-202610-00001，每个月从 1 重新开始。
    // 必须在 DB transaction 里调用：这里用 SELECT ... FOR UPDATE 锁住当月的序号行，两个请求同时进来也不会拿到同一个号
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
