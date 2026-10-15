<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * 从 OCR 文字里找出收据上确实印着的值，预填进表单（还没保存）并高亮给用户核对。
 * 用户按保存之前不会写入任何东西；看不清楚的就不给 hint，不靠猜。
 */
class ReceiptTextHints
{
    // 收据上印的州 / 联邦直辖区写法
    private const STATES = 'W\.?\s*P\.?\s*KUALA\s+LUMPUR|WILAYAH\s+PERSEKUTUAN[A-Z ]*|KUALA\s+LUMPUR|PUTRAJAYA|LABUAN|NEGERI\s+SEMBILAN|PULAU\s+PINANG|PENANG|SELANGOR(?:\s+DARUL\s+EHSAN)?|JOHOR(?:\s+DARUL\s+TA.ZIM)?|KEDAH(?:\s+DARUL\s+AMAN)?|KELANTAN|MELAKA|MALACCA|PAHANG|PERAK|PERLIS|SABAH|SARAWAK|TERENGGANU';

    /** @return array{merchants: array<int, string>, locations: array<int, string>, totals: array<int, string>, dates: array<int, array{raw: string, iso: string}>, times: array<int, string>, items: array<int, array>, items_match_total: bool} */
    public function find(?string $text): array
    {
        $hints = ['merchants' => [], 'locations' => [], 'totals' => [], 'dates' => [], 'times' => [], 'items' => [], 'items_match_total' => false];
        if ($text === null || trim($text) === '') {
            return $hints;
        }

        // OCR 常把金额在小数点前后断开："RM67 .52"、"26 .30"、"4. 17"，先拼回去
        $text = preg_replace('/(\d)\s+\.\s*(\d{2})(?!\d)|(\d)\.\s+(\d{2})(?!\d)/', '$1$3.$2$4', $text);

        $merchant = $this->merchant($text);
        if ($merchant !== null) {
            $hints['merchants'][] = $merchant;
        }

        $location = $this->location($text);
        if ($location !== null) {
            $hints['locations'][] = $location;
        }

        foreach (preg_split('/\n/', $text) as $line) {
            // 只认明写 TOTAL / JUMLAH / AMOUNT DUE 的行，SUBTOTAL 不算；同一行有几个金额时取最后一个
            if (preg_match('/\b(grand\s*total|total|tot|jumlah|amount\s*due|nett?\s*total)\b/i', $line)
                && ! preg_match('/\bsub\s*-?\s*total\b/i', $line)
                && preg_match_all('/(\d{1,3}(?:,\d{3})*|\d+)\.(\d{2})\b/', $line, $m, PREG_SET_ORDER)) {
                $last = end($m);
                $hints['totals'][] = str_replace(',', '', $last[1]).'.'.$last[2];
            }

            // 05/10/2026、05-10-26、2026-10-05；大马习惯日在前，不是美式的月在前
            if (preg_match_all('/\b(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})\b|\b(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})\b/', $line, $dm, PREG_SET_ORDER)) {
                foreach ($dm as $d) {
                    [$y, $mo, $day] = ! empty($d[1]) ? [$d[1], $d[2], $d[3]] : [$d[6], $d[5], $d[4]];
                    if (strlen($y) === 2) {
                        $y = '20'.$y;
                    }
                    if (checkdate((int) $mo, (int) $day, (int) $y)) {
                        $hints['dates'][] = ['raw' => $d[0], 'iso' => Carbon::createFromDate((int) $y, (int) $mo, (int) $day)->format('Y-m-d')];
                    }
                }
            }

            // 英文月份的写法："25 Nov 16"、"25-Nov-2016"、"25 November 2016"
            if (preg_match_all('/\b(\d{1,2})[\s\-.\/]*(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*[\s\-.\/,]*(\d{4}|\d{2})\b/i', $line, $nm, PREG_SET_ORDER)) {
                foreach ($nm as $d) {
                    $mo = (int) date('n', strtotime('1 '.$d[2].' 2000'));
                    $y = strlen($d[3]) === 2 ? '20'.$d[3] : $d[3];
                    if (checkdate($mo, (int) $d[1], (int) $y)) {
                        $hints['dates'][] = ['raw' => $d[0], 'iso' => Carbon::createFromDate((int) $y, $mo, (int) $d[1])->format('Y-m-d')];
                    }
                }
            }

            // 日期后面的时间被 OCR 吃掉冒号："08/10/2026 12 47"、"08/10/2026 12.47"。
            // 只在整行找不到正常时间时才补，免得把别的数字当成时间
            if (! str_contains($line, ':') || ! preg_match('/\d:\d/', $line)) {
                $line = preg_replace('/(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}\s+)([01]?\d|2[0-3])[.\s]([0-5]\d)\b/', '$1$2:$3', $line);
            }

            if (preg_match_all('/\b([01]?\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?\s*(am|pm)?\b/i', $line, $tm, PREG_SET_ORDER)) {
                foreach ($tm as $t) {
                    $h = (int) $t[1];
                    $ampm = strtolower($t[3] ?? '');
                    if ($ampm === 'pm' && $h < 12) {
                        $h += 12;
                    } elseif ($ampm === 'am' && $h === 12) {
                        $h = 0;
                    }
                    $hints['times'][] = sprintf('%02d:%s', $h, $t[2]);
                }
            }
        }

        $hints['merchants'] = array_values(array_unique($hints['merchants']));
        $hints['totals'] = array_values(array_unique($hints['totals']));
        $hints['dates'] = array_values(array_unique($hints['dates'], SORT_REGULAR));
        $hints['times'] = array_values(array_unique($hints['times']));

        [$hints['items'], $hints['items_match_total']] = $this->items($text, end($hints['totals']) ?: null);

        return $hints;
    }

    // 有金额但不是购买项目的行。
    // 税的字眼只锚定词头，因为 OCR 常在后面粘杂讯："SSTs4("、"GST@6%"
    private const NOT_ITEM = '/total|\btot\b|jumlah|\bsst|\bgst|\btax(?!i)|cukai|service\s*charge|rounding|bundar|\bcash\b|tunai|change|baki|\bpaid\b|payment|\bvisa\b|master|\bcard\b|debit|credit|tender|balance|\bdue\b|discount|diskaun|deposit|amount|\bqty\b|count|sold|points?\b|saving'
        // e-wallet / 付款行，还有带数字的表头行（单号、桌号、收银员等）
        .'|wallet|\btng|touch\s*n\s*go|grab\s*pay|\bboost\b|duitnow|shopee\s*pay|\bqr\b|invoice|order\s*no|cashier|\btable\b|\bcover\b|terminal|\bprn\b/i';

    // 总额行（不含 SUBTOTAL），之后都是付款和税务汇总，不再有项目。
    // 单独的 tot 是为了 "Tot Amovat"，即 OCR 读坏的 "Total Amount"
    private const TOTAL_LINE = '/\b(grand\s*total|net\s*total|total|tot|jumlah|amount\s*due)\b/i';

    /**
     * 解析购买项目，行尾的金额当作该行小计。常见写法：
     *   "1 PEPSI (L) 3.90"、"2 CHEEZY WEDGES TT @5.50 11.00 S"、"Mineral Water 2 x 2.00 4.00"，
     *   还有数量写在名字后面的 "SPANISH LATTE (1) 1 15.90"。
     *
     * 项目下面的 add-on（"- Oat Milk Upgrade +2.50"、"- Less Sugar"）：有价钱的独立成一行
     * "+ Oat Milk Upgrade (add-on: SPANISH LATTE)"，金额才对得上；没价钱的就写进项目名里。
     * 只有紧贴在项目下面的行才当 add-on。
     *
     * 项目和 TOTAL 之间的 service charge、SST / GST / service tax、rounding，只有在能让加总刚好等于
     * TOTAL 时才加进去。像 "GST @6% Paid" 这种已含在总额里的就不会被重复计算。
     * 读到 TOTAL 行就停，后面是付款（e-wallet、卡、找零）和税务汇总。
     *
     * @return array{0: array<int, array>, 1: bool} [项目, 是否刚好加到总额]
     */
    private function items(string $text, ?string $total): array
    {
        $money = '(\d{1,5})[.,](\d{2})\d?'; // 多一位是 OCR 把尾巴的税码读成数字，例："2.290"、"11.008"
        $extras = []; // service charge / 税 / rounding 的候选
        $parent = null; // add-on 挂在哪个项目下（index）

        // 有印单价 "(13.00/ea)" 的 POS 收据，项目直接从单价算，比右边的金额可靠
        $items = $this->perEachItems($text);
        $itemsLocked = $items !== [];

        foreach (preg_split('/\n/', $text) as $raw) {
            if (trim($raw) === '') {
                continue;
            }

            // 汇总行的小数点被 OCR 弄丢："SERVICE CHARGE (5%) 395"、"Subtotal 7900,"、"Bill rounding 005"
            if (preg_match('/subtotal|sub\s*total|service|charge|tax|rounding|total|jumlah/i', $raw) && ! preg_match('/\d[.,]\d{2}\b/', $raw)) {
                $raw = preg_replace('/(?<![\d.])(\d{1,4})\s?(\d{2})[,.]?\s*$/', '$1.$2', rtrim($raw));
            }

            if ($itemsLocked) {
                // 项目已经有了，这里只找附加费和 TOTAL 行
                if (! preg_match(self::NOT_ITEM, $raw)) {
                    continue;
                }
            }

            $isModifier = (bool) preg_match('/^\s*[-+*•>]\s*\p{L}/u', $raw);
            // 发票项目下的说明子行："a) 1 no PHILIPS 100w led flood light"、"b) | no PVC-LINK …"
            $isDetail = (bool) preg_match('/^\s*[|»)\]]*\s*[a-e\d]?\)\s*\S/u', $raw);
            $line = trim(preg_replace('/^[^a-z0-9(]+/i', '', $raw));

            // 手写 cash bill："MINYAK GAMAT | 2 | 25/= | 50/="
            if ($row = $this->cashBillRow($raw)) {
                $items[] = $row + ['notes' => []];
                $parent = array_key_last($items);

                continue;
            }

            // 取行尾的金额，可带正负号 "+2.50" / "-0.02"，或 OCR 读成 "~0.02"；
            // 后面只允许跟短税码或杂讯，不能再有数字
            if (! preg_match('/^(.*?)\s([+\-~−]?)\s*'.$money.'\s*([^\d\s]{0,3})\s*[^\d]{0,3}$/iu', $line, $m)) {
                // 没价钱的 add-on（"- Less Sugar (30%)"）或发票说明行（"a) 1 no PHILIPS …"），备注到上面的项目
                if (($isModifier || $isDetail) && $parent !== null && ! preg_match(self::NOT_ITEM, $line) && preg_match_all('/\p{L}/u', $line) >= 3) {
                    $items[$parent]['notes'][] = $this->cleanName(preg_replace('/^\s*[|»)\]]*\s*[a-e\d]?\)\s*/u', '', $raw));
                } else {
                    $parent = null;
                }

                continue;
            }
            [$all, $before, $sign, $whole, $cents] = $m;
            $amount = ((int) $whole) * 100 + (int) $cents;
            $isAddon = $isModifier || $sign === '+';

            if (preg_match(self::NOT_ITEM, $before)) {
                $parent = null;

                if (preg_match(self::TOTAL_LINE, $before) && ! preg_match('/sub\s*-?\s*total/i', $before)) {
                    break; // 后面是付款、找零和税务汇总
                }

                $percent = preg_match('/(\d{1,2})\s*%/', $before, $pm) ? " ({$pm[1]}%)" : '';
                if (preg_match('/service\s*charge|svc\s*chg/i', $before)) {
                    $extras[] = ['kind' => 'charge', 'label' => 'Service Charge'.$percent, 'cents' => $amount];
                } elseif (preg_match('/rounding|round\s*adj|bundar/i', $before)) {
                    // "~0.02" 是 OCR 读不出正负号，两个方向都放进候选，让总额来决定
                    foreach ($sign === '~' ? [1, -1] : [in_array($sign, ['-', '−'], true) ? -1 : 1] as $dir) {
                        $extras[] = ['kind' => 'rounding', 'label' => 'Rounding Adj', 'cents' => $dir * $amount];
                    }
                } elseif (preg_match('/discount|diskaun|\bdisc\b/i', $before)) {
                    $extras[] = ['kind' => 'discount', 'label' => 'Discount'.$percent, 'cents' => -$amount];
                } elseif (preg_match('/(service\s*tax|\bsst|\bgst|\btax(?!i)|cukai)/i', $before, $tw) && ! preg_match('/total/i', $before)) {
                    $extras[] = ['kind' => 'tax', 'label' => strtoupper(preg_replace('/\s+/', ' ', $tw[1])).$percent, 'cents' => $amount];
                }

                continue;
            }

            // 发票行首的物品编号 "A0032-1"，OCR 常读成 "Aoos2-1" / "A00i22"（至少要有一个真数字）。
            // 前面的行号（"2 | A00i22 …"、"1 |. A0032-1 …"）一起去掉。
            // 一定要在下面的参考编号检查之前做，不然 "A0032-1" 这种行会被当成编号丢掉
            $before = preg_replace('/^(?:\d{1,3}\s*[|)\].]{0,2}\s+)?(?=\S*\d)[A-Za-z]{1,3}[0-9oOiIlsS]{2,5}(?:-\d+)?\s+(?=\S*\p{L}{2})/u', '', ltrim($before));

            // 参考编号（"TPW001-C1/286377"）、电话号码之类不是项目
            if (preg_match('/\S*[\/\-]\S*\d{4,}|\d{4,}\S*[\/\-]/', $before)) {
                $parent = null;

                continue;
            }

            $qty = 1;
            $alt = null; // 印出的金额和 qty × 单价对不上时（某一位读错），先把算出来的值存着
            if (preg_match('/^(.*?)\s(\d{1,5})([.,]0{1,3})?\s+(\d{1,6})[.,](\d{2})$/', trim($before), $q) && (int) $q[2] > 0
                && (($calc = (int) $q[2] * ((int) $q[4] * 100 + (int) $q[5])) === $amount || $q[3] !== '')) {
                // 发票格式 "描述 qty 单价 金额"，例："To supply & install with 3.00 280.00 840.00"。
                // qty × 单价 ≠ 金额时，只有 qty 印成发票式的 "3.00" 才当发票行；
                // 收银机收据上的 "WEDGES T 1 6.50 11.00" 不算
                $qty = (int) $q[2];
                $before = $q[1];
                if ($calc !== $amount) {
                    $alt = $calc; // 例："3.00 280,00 240.00"，840 被读成 240，下面由总额决定用哪个
                }
            } elseif (preg_match('/^(\d{1,3})\s+(?=[a-z(])/i', $before, $q)) {          // "2 CHEEZY WEDGES"
                $qty = max(1, (int) $q[1]);
                $before = substr($before, strlen($q[0]));
            } elseif (preg_match('/\s(\d{1,3})\s*[x×]\s*\d+[.,]\d{2}\s*$/iu', $before, $q)) { // "Mineral Water 2 x 2.00"
                $qty = max(1, (int) $q[1]);
            } elseif (preg_match('/^(.*\D)\s(\d{1,2})$/', trim($before), $q) && (int) $q[2] >= 1 && (int) $q[2] <= 50) { // "SPANISH LATTE (1) 1"
                $qty = (int) $q[2];
                $before = $q[1];
            }

            $name = $this->cleanName($before);
            if (preg_match_all('/[a-z]/i', $name) < 3 || $amount === 0) {
                continue;
            }

            if ($isAddon && $parent !== null) {
                $name = '+ '.$name.' (add-on: '.$items[$parent]['name'].')';
                $items[] = ['name' => $name, 'qty' => $qty, 'cents' => $amount, 'notes' => []];
                // $parent 不清掉，同一个项目下面可能还有别的 add-on
            } else {
                $items[] = ['name' => ($isAddon ? '+ ' : '').$name, 'qty' => $qty, 'cents' => $amount, 'alt' => $alt, 'notes' => []];
                $parent = array_key_last($items);
            }
        }

        $toItem = function (array $i) {
            $exact = $i['qty'] > 1 && $i['cents'] % $i['qty'] === 0;
            $name = $i['notes'] ? $i['name'].' ('.implode(', ', $i['notes']).')' : $i['name'];

            return [
                'item_name' => mb_substr($name, 0, 255),
                'quantity' => (string) $i['qty'],
                'unit_price' => $exact ? number_format($i['cents'] / $i['qty'] / 100, 2, '.', '') : ($i['qty'] === 1 ? number_format($i['cents'] / 100, 2, '.', '') : null),
                'amount' => $exact || $i['qty'] === 1 ? '' : number_format($i['cents'] / 100, 2, '.', ''),
                'tax_amount' => '',
            ];
        };

        $result = array_map($toItem, $items);
        if ($items === [] || $total === null) {
            return [$result, false];
        }

        $totalCents = (int) round(((float) $total) * 100);
        $extras = array_slice(array_values(array_unique($extras, SORT_REGULAR)), 0, 10);

        // 穷举附加费组合，找能让 项目 + 附加费 = TOTAL 的最大组合。
        // 候选最多 10 个，2^10 次还算便宜；同一个 rounding 的正负两个版本不能同时选
        $bestFor = function (int $sum) use ($extras, $totalCents): ?array {
            $best = null;
            for ($mask = 0; $mask < (1 << count($extras)); $mask++) {
                $pick = array_values(array_filter($extras, fn ($e, $i) => $mask & (1 << $i), ARRAY_FILTER_USE_BOTH));
                if (count(array_filter($pick, fn ($e) => $e['kind'] === 'rounding')) > 1) {
                    continue;
                }
                if ($sum + array_sum(array_column($pick, 'cents')) === $totalCents && ($best === null || count($pick) > count($best))) {
                    $best = $pick;
                }
            }

            return $best;
        };

        $best = $bestFor(array_sum(array_column($items, 'cents')));

        // 印出的金额加不到总额，但用 qty × 单价就对得上，说明金额栏有一位读错了
        $corrected = array_map(fn ($i) => ($i['alt'] ?? null) !== null ? ['cents' => $i['alt']] + $i : $i, $items);
        if ($best === null && $corrected !== $items && ($best = $bestFor(array_sum(array_column($corrected, 'cents')))) !== null) {
            $result = array_map($toItem, $corrected);
        }

        if ($best === null) {
            return [$result, false];
        }

        $fmt = fn (int $cents) => number_format(abs($cents) / 100, 2, '.', '');
        foreach ($best as $e) {
            $result[] = match ($e['kind']) {
                'charge' => ['item_name' => $e['label'], 'quantity' => '1', 'unit_price' => $fmt($e['cents']), 'amount' => '', 'tax_amount' => ''],
                'tax' => ['item_name' => $e['label'], 'quantity' => '1', 'unit_price' => null, 'amount' => '0.00', 'tax_amount' => $fmt($e['cents'])],
                // 负的 rounding / discount 做成金额 0 的一行，再填 discount_amount
                'rounding', 'discount' => $e['cents'] >= 0
                    ? ['item_name' => $e['label'], 'quantity' => '1', 'unit_price' => $fmt($e['cents']), 'amount' => '', 'tax_amount' => '']
                    : ['item_name' => $e['label'], 'quantity' => '1', 'unit_price' => null, 'amount' => '0.00', 'discount_amount' => $fmt($e['cents']), 'tax_amount' => ''],
            };
        }

        return [$result, true];
    }

    /**
     * FeedMe 这类 POS 收据，每个项目印成一块：
     *   1  P04 白胡椒... White Pepper              13.00
     *      Spicy Pork Noodle (13.00/ea)
     *      • 猪肉丸 Meat Ball (3pcs) + 1.80
     * 照片小的话右边金额的小数点常常丢掉（"1760"、"17 80"），但 "(13.00/ea)" 单价和 "+ 1.80" 的 add-on
     * 通常读得出来，所以改用 qty × 单价 重建每一行，有价钱的 add-on 各占一行。加总对不对由调用方拿 TOTAL 去验。
     *
     * @return array<int, array{name: string, qty: int, cents: int, notes: array}>
     */
    private function perEachItems(string $text): array
    {
        $each = '/\(\s*(\d{1,4})\s?[.,]?\s?([0-9OoGUD]{2})\s*\/\s*[a-z]{2}\s*\)/i'; // "(13.00/ea)", "(13 00/ea)", "(11.50/aa)"
        if (preg_match_all($each, $text) < 2) {
            return [];
        }

        $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), fn ($l) => $l !== ''));
        $blocks = [];
        $open = false;
        foreach ($lines as $line) {
            $line = ltrim($line, '|');
            $isSummary = preg_match('/subtotal|sub\s*total|service|total|\bqty\b|^\d+\s+q\w{0,3}$/i', $line);
            if (! $isSummary && preg_match('/^(\d{1,2})\s+(?=\S*\p{L})(.+)$/u', $line, $m)) {
                $blocks[] = ['qty' => (int) $m[1], 'lines' => [trim($m[2])]];
                $open = true;
            } elseif ($isSummary) {
                $open = false;
            } elseif ($open) {
                $blocks[array_key_last($blocks)]['lines'][] = $line;
            }
        }

        $items = [];
        foreach ($blocks as $block) {
            $body = implode("\n", $block['lines']);
            if (! preg_match($each, $body, $em)) {
                continue; // 不是项目块，或单价读不出来，留给用户自己填
            }
            $unit = (int) $em[1] * 100 + (int) strtr($em[2], 'OoGUD', '00000');

            $nameParts = [];
            $addons = [];
            foreach ($block['lines'] as $i => $l) {
                // add-on 行："• 猪肉丸 Meat Ball (3pcs) + 1.80"、"+ ice +050"、"+h? Miced Meat + 3.00"
                if ($i > 0 && preg_match('/^(.*?)\+\s*(\d{1,3})[.,\s]?(\d{2})\s*$/u', $l, $am) && preg_match('/^[\s+*•·\-]|\+\s*\d/u', $l)) {
                    $label = $this->cleanName($this->dropOcrJunk(ltrim($am[1], ' +*•·-')));
                    $addons[] = ['name' => $label !== '' ? $label : 'Add-on', 'cents' => (int) $am[2] * 100 + (int) $am[3]];

                    continue;
                }
                $l = preg_replace($each, '', $l);
                if ($i === 0) {
                    $l = preg_replace('/\s+[\d\s.,|]{2,}\S{0,2}$/u', '', $l); // 去掉读坏的右边金额 "1760" / "17 80" / "11 二"
                    $l = $this->dropOcrJunk($l);
                }
                $nameParts[] = trim($l);
            }

            $name = $this->cleanName(implode(' ', array_filter($nameParts)));
            if (preg_match_all('/[a-z]/i', $name) < 3) {
                continue;
            }

            $items[] = ['name' => $name, 'qty' => $block['qty'], 'cents' => $unit * $block['qty'], 'notes' => []];
            foreach ($addons as $a) {
                $items[] = ['name' => '+ '.$a['name'].' (add-on: '.$name.')', 'qty' => 1, 'cents' => $a['cents'], 'notes' => []];
            }
        }

        return $items;
    }

    // 英文名前面的中文字和编号，OCR 会读成一堆乱码，从最后一个乱码 token 之后截取。
    // 例："P15 瓦煲老鼠粉 Clay Pot" 读成 "PIS Fits #10) Clay Pot"，结果是 "Clay Pot"
    private function dropOcrJunk(string $text): string
    {
        $tokens = preg_split('/\s+/', trim($text));
        $last = -1;
        foreach ($tokens as $i => $t) {
            $balanced = preg_match('/^\([\p{L}\d]+\)$/u', $t);
            if (! $balanced && (preg_match('/[#%:;!?©@$£¥\[\]{}|\\\\]|[^\x00-\x7F]/u', $t) || substr_count($t, '(') !== substr_count($t, ')'))) {
                $last = $i;
            }
        }
        $kept = array_slice($tokens, $last + 1);

        // 剩下的开头若是编号 "P04"、"D11"、"p08" 也拿掉
        if ($kept && preg_match('/^[A-Za-z]{1,2}\d{1,3}$/', $kept[0])) {
            array_shift($kept);
        }

        return implode(' ', $kept);
    }

    /**
     * 手写 cash bill 的一行，金额是整数令吉，写成 "25/="（或 "25/-"）：
     *   "LL MInyAK GamAT | 2 | 25/-| So/="  →  MINYAK GAMAT，qty 2，单价 RM 25.00，小计 RM 50.00
     * 手写字 OCR 常把数字和字母搞混（S→5、o→0、I→1），所以只有 qty × 单价 = 金额 成立
     * （或缺的那个能从另外两个整除算出）才接受。
     *
     * @return array{name: string, qty: int, cents: int}|null
     */
    private function cashBillRow(string $raw): ?array
    {
        if (! preg_match('/\/\s*[=\-–>e]/u', $raw)) {
            return null; // 这行没有 "/=" 金额
        }

        $tokens = substr_count($raw, '|') >= 2
            ? array_map('trim', explode('|', $raw))
            : preg_split('/\s+/', trim($raw));

        // 从行尾往前收集数字，碰到品名就停
        $numbers = [];
        $nameIndex = null;
        for ($i = count($tokens) - 1; $i >= 0; $i--) {
            $token = $tokens[$i];
            if (preg_match('/^([0-9SOoIlZB]{1,5})\s*\/\s*[=\-–>e]?$/u', $token, $m)) {      // "So/=", "IS/e", "25/-"
                $numbers[] = (int) strtr($m[1], 'SOoIlZB', '5001128');
            } elseif (preg_match('/^\d{1,3}$/', $token)) {                                   // 单纯的 qty "2"
                $numbers[] = (int) $token;
            } elseif (preg_match_all('/\p{L}/u', $token) >= 3) {
                $nameIndex = $i;
                break;
            } // 其他（"|_|"、"_"、OCR 斑点）直接跳过
        }

        if ($nameIndex === null || $numbers === []) {
            return null;
        }

        // 倒着收集的，所以顺序是 [金额, 单价, qty]
        [$amount, $price, $qty] = array_pad($numbers, 3, null);
        if ($qty !== null && $price !== null && $qty * $price !== $amount) {
            return null; // 乘不出来就是读错了，让用户自己输入
        }
        if ($qty === null && $price !== null) {
            if ($price === 0 || $amount % $price !== 0) {
                return null;
            }
            $qty = intdiv($amount, $price);   // qty 读不出来时反推：35 / 35 = 1
        }
        $qty ??= 1;

        $name = preg_replace('/^\S{1,2}[\]\)\.|]?\s+(?=\p{L}{3})/u', '', trim($tokens[$nameIndex])); // 去掉行号 "LL"、"3]"
        $name = $this->cleanName($name);
        if (preg_match('/\p{Ll}\p{Lu}/u', $name)) {
            $name = mb_strtoupper($name); // 手写 OCR 大小写乱跳，例："MInyAK GamAT"
        }

        if (preg_match(self::NOT_ITEM, $name) || preg_match_all('/\p{L}/u', $name) < 3 || $amount <= 0) {
            return null;
        }

        return ['name' => $name, 'qty' => $qty, 'cents' => $amount * 100];
    }

    private function cleanName(string $name, bool $keepPercent = false): string
    {
        $name = preg_replace('/\s\d{1,3}\s*[x×]\s*\d+[.,]\d{2}\s*$/iu', '', $name); // "2 x 2.00"
        $name = preg_replace('/\s*[@©]\s*\S*/u', '', $name);                         // "@5.50" / "©.50"
        $name = preg_replace('/\s\d+[.,]\d{2}\b/', '', $name);                        // 夹在名字里的单价
        $name = preg_replace('/(\s+(?:[A-Z]{1,2}|\d))+$/', '', trim($name));          // 尾巴的税码 "T T"、"TT"、"1"
        if (! $keepPercent) {
            $name = preg_replace('/[^\p{L}\p{N}\s()&\/\-.,\']/u', '', $name);
            $name = preg_replace('/(?<=\p{L})\.(?=\s)/u', '', $name); // OCR 斑点读成句号："CHEEZY. WEDGES"
        }

        return trim(preg_replace('/\s{2,}/', ' ', $name), ' .,-|=~');
    }

    /**
     * 店家完整地址，例：
     *   "LOT LG-23, AOEN BIG SUBANG JAYA, NO 3 JALAN SS16/1, 47500 SUBANG JAYA, SELANGOR"
     * 以「5 位 postcode + 地名」那行为锚点，往上最多加 3 行地址（LOT / NO / JALAN / LEVEL / TOWER…），
     * 碰到公司名那行就停；下一行如果只有州名也接上。带金额的行不算，夹在长数字里或横杠后面的
     * 5 位数（电话、税号、单号）也不当 postcode。
     */
    private function location(string $text): ?string
    {
        $lines = $this->cleanLines($text);

        // 只看卖家的表头。发票上 INVOICE 标题下面是买家地址（Bill to、客户资料），不能当成消费地点
        foreach ($lines as $i => $line) {
            // 从第 3 行起才截，店名上面若有 "CUSTOMER COPY" 横幅，不至于把整个表头都切掉
            if ($i >= 2 && preg_match('/\binvoice\b|bill\s*to|sold\s*to|deliver(y)?\s*to|\bcustomer\b|\bm\/s\b|\battn\b/i', $line)) {
                $lines = array_slice($lines, 0, $i);
                break;
            }
        }

        $anchor = $this->postcodeLine($lines);
        if ($anchor === null) {
            return $this->addressWithoutPostcode($lines);
        }

        $parts = [$lines[$anchor]];

        for ($i = $anchor - 1; $i >= max(0, $anchor - 3); $i--) {
            if (! $this->isAddressLine($lines[$i])) {
                break;
            }
            array_unshift($parts, $lines[$i]);
        }

        // 下一行以州名开头就接上，后半段 OCR 读坏也没关系："Selangor Daru! Ehsan"
        if (isset($lines[$anchor + 1]) && preg_match('/^('.self::STATES.')\b/i', $lines[$anchor + 1])
            && ! preg_match('/\d|tel|fax|gst|sst|www/i', $lines[$anchor + 1]) && mb_strlen($lines[$anchor + 1]) <= 40) {
            $parts[] = $lines[$anchor + 1];
        }

        $address = implode(', ', array_map(fn ($p) => trim($p, ' ,.;:'), $parts));

        return mb_substr(preg_replace('/\s*,\s*(,\s*)*/', ', ', $address), 0, 255);
    }

    // postcode 读不出来时的后备（例："43300" 读成 "45.00"）：在表头找 JALAN / TAMAN / LORONG 等街道行，
    // 往下最多接 3 行，到出现州名或 MALAYSIA 为止；遇到电话、e-mail、网址就断
    private function addressWithoutPostcode(array $lines): ?string
    {
        $notAddress = '/www|http|@|\bte[lk]\b|phone|fax|invoice|receipt|total|\d{2,3}-\d{6,}|\+?6?0\d{8,}/i';

        foreach (array_slice($lines, 0, 12) as $i => $line) {
            if (! preg_match('/\b(jalan|jln|lorong|lrg|persiaran|lebuh)\b|\b(taman|tmn)\b.*\d|\d.*\b(taman|tmn)\b/i', $line)
                || preg_match($notAddress, $line)) {
                continue;
            }

            $parts = [$line];
            $hasState = (bool) preg_match('/\b('.self::STATES.'|malaysia)\b/i', $line);
            for ($j = $i + 1; ! $hasState && $j <= $i + 3 && isset($lines[$j]); $j++) {
                if (preg_match($notAddress, $lines[$j]) || preg_match_all('/\p{L}/u', $lines[$j]) < 3) {
                    break;
                }
                $parts[] = $lines[$j];
                $hasState = (bool) preg_match('/\b('.self::STATES.'|malaysia)\b/i', $lines[$j]);
            }
            if (! $hasState) {
                $parts = [$line]; // 找不到州名就只留街道那行，不再往下猜
            }

            return mb_substr(preg_replace('/\s*,\s*(,\s*)*/', ', ', implode(', ', array_map(fn ($p) => trim($p, ' ,.;:'), $parts))), 0, 255);
        }

        return null;
    }

    // postcode 上面的街道 / 大楼行；公司名、电话、税号、网址那些行不算
    private function isAddressLine(string $line): bool
    {
        if (preg_match('/\d+[.,]\d{2}\b/', $line)
            || preg_match('/\b(sdn|bhd|berhad|plt|enterprise|trading|restaurants?)\b|\(\d{3,}-[a-z]\)|\bco\.?\s*no\b|company\s*no|\broc\b|tel\b|fax\b|phone|gst|sst|reg\b|www|http|\.com|@\s*(gmail|yahoo|hotmail|outlook)|\S+@\S+\.(com|my|net)|invoice|receipt|order|cashier/i', $line)
            || preg_match_all('/[a-z]/i', $line) < 3) {
            return false;
        }

        return (bool) preg_match('/\b(lot|no|jalan|jln|lorong|lrg|persiaran|lebuh|taman|tmn|blok|block|level|lvl|aras|tingkat|unit|tower|menara|plaza|mall|kompleks|complex|bangunan|wisma|pusat|centre|center|square|seksyen|section|batu|kampung|kg|ss\d+)\b|\d/i', $line);
    }

    // 去掉行首行尾的 OCR 杂讯（| ~ = _ — 之类），空行不要
    private function cleanLines(string $text): array
    {
        return array_values(array_filter(
            array_map(fn ($l) => preg_replace('/^[\s|~=_\-—]+|[\s|~=_\-—]+$/u', '', $l), explode("\n", $text)),
            fn ($l) => $l !== '',
        ));
    }

    // 第一行「真 postcode + 地名」的 index。大马 postcode 范围约 01000 到 98859
    private function postcodeLine(array $lines): ?int
    {
        foreach ($lines as $i => $line) {
            if (preg_match('/\d+[.,]\d{2}\b/', $line)
                || preg_match('/invoice|receipt|order|bill\s*no|inv\b|cashier|table|@\s*(gmail|yahoo|hotmail|outlook)|\S+@\S+\.(com|my|net)/i', $line) // 防 "Invoice no: 32754 ORDER" 被当成 postcode
                || ! preg_match('/(?<![\d\-\/])(\d{5})(?![\d\-\/])[\s,]+([A-Za-z].*)$/', $line, $m)
                || (int) $m[1] < 1000 || (int) $m[1] > 98859
                || preg_match('/\b(tel|fax|phone|gst|sst|reg|no)\b/i', $m[2])
                || preg_match_all('/[a-z]/i', $m[2]) < 3) {
                continue;
            }

            return $i;
        }

        return null;
    }

    // 店名一般是收据顶部第一行像样的文字。网址、电话、税号、发票行和杂讯跳过，只看前几行
    private function merchant(string $text): ?string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), fn ($l) => $l !== ''));

        // 优先取顶部的注册公司名："ZUS COFFEE (M) SDN BHD"，而不是 logo 上的 "ZUS"。
        // OCR 常把 "Sdn Bhd" 读成 "Son Bhd" / "Sda Bid" / "Sdn 8hd"
        $company = '/\b(sdn|son|sda|bhd|8hd|berhad|plt|enterprise|trading)\b|\b(sdn|son|sda)\.?\s*bid\b/i';
        foreach (array_slice($lines, 0, 8) as $i => $line) {
            if (! preg_match($company, $line) || preg_match('/www|http|\btel\b|\bgst\b|\bsst\b|invoice|receipt|total/i', $line)) {
                continue;
            }

            // 改回 Sdn / Bhd 时跟收据原本的大小写："SON" → "SDN"，"Sda" → "Sdn"
            $fix = fn (string $from, string $to) => fn ($m) => ctype_upper(str_replace('.', '', $m[0])) ? strtoupper($to) : $to;
            $clean = preg_replace(['/\(\s*(reg[^)]*|\d{3,}[^)]*)\)/i', '/[\[\]{}|]/'], '', $line);
            $clean = preg_replace_callback('/\b(son|sda)\b(?=\.?\s*(bhd|bid|8hd)\b)/i', $fix('son', 'Sdn'), $clean);
            $clean = preg_replace_callback('/(?<=sdn)(\.?\s*)\K(bid|8hd)\b/i', $fix('bid', 'Bhd'), $clean);
            $clean = trim($clean, " \t-~_=|.,:;'\"`*#@");
            // "SDN. BHD. (26388-T)" 自己占一行时，公司名在上一行
            $core = preg_replace([$company, '/[^a-z ]/i'], '', $clean);
            if (! preg_match('/[a-z]{2,}/i', $core) && $i > 0) {
                $clean = trim($lines[$i - 1], " \t-~_=|.,:;'\"`*#@").' '.$clean;
            }

            if (preg_match('/[a-z]{3,}/i', $clean)) {
                return mb_substr(trim(preg_replace('/\s{2,}/', ' ', $clean)), 0, 100);
            }
        }

        foreach (array_slice($lines, 0, 6) as $line) {
            $line = trim($line, " \t-~_=|.,:;'\"`*#@");
            $letters = preg_match_all('/[a-z]/i', $line);

            if (mb_strlen($line) < 3
                || ! preg_match('/[a-z]{3,}/i', $line)
                || $letters < mb_strlen($line) * 0.5
                || preg_match('/www|http|\.com|\.my\b|@|\btel\b|\bfax\b|\bgst\b|\bsst\b|invoice|receipt|\bno[:.]|\bdate\b|total|jumlah|\bcash\b|change|amount|\bqty\b|thank/i', $line)) {
                continue;
            }

            return mb_substr($line, 0, 100);
        }

        return null;
    }
}
