<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Finds values that literally appear in OCR text. They are pre-filled into the (unsaved) form and highlighted
 * for the user to check; nothing is stored until the user saves. If a pattern is not clearly present, no hint is given.
 */
class ReceiptTextHints
{
    /** Malaysian states / federal territories, as printed on receipts. */
    private const STATES = 'W\.?\s*P\.?\s*KUALA\s+LUMPUR|WILAYAH\s+PERSEKUTUAN[A-Z ]*|KUALA\s+LUMPUR|PUTRAJAYA|LABUAN|NEGERI\s+SEMBILAN|PULAU\s+PINANG|PENANG|SELANGOR(?:\s+DARUL\s+EHSAN)?|JOHOR(?:\s+DARUL\s+TA.ZIM)?|KEDAH(?:\s+DARUL\s+AMAN)?|KELANTAN|MELAKA|MALACCA|PAHANG|PERAK|PERLIS|SABAH|SARAWAK|TERENGGANU';

    /** @return array{merchants: array<int, string>, locations: array<int, string>, totals: array<int, string>, dates: array<int, array{raw: string, iso: string}>, times: array<int, string>} */
    public function find(?string $text): array
    {
        $hints = ['merchants' => [], 'locations' => [], 'totals' => [], 'dates' => [], 'times' => [], 'items' => [], 'items_match_total' => false];
        if ($text === null || trim($text) === '') {
            return $hints;
        }

        // OCR often splits an amount around the decimal point: "RM67 .52", "26 .30", "4. 17".
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
            // Amounts on lines that explicitly say TOTAL / JUMLAH / AMOUNT DUE (but not SUBTOTAL).
            if (preg_match('/\b(grand\s*total|total|tot|jumlah|amount\s*due|nett?\s*total)\b/i', $line)
                && ! preg_match('/\bsub\s*-?\s*total\b/i', $line)
                && preg_match_all('/(\d{1,3}(?:,\d{3})*|\d+)\.(\d{2})\b/', $line, $m, PREG_SET_ORDER)) {
                $last = end($m);
                $hints['totals'][] = str_replace(',', '', $last[1]).'.'.$last[2];
            }

            // Dates such as 05/10/2026, 05-10-26, 2026-10-05 (day-first, Malaysian format).
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

            // Dates with an English month name: "25 Nov 16", "25-Nov-2016", "25 November 2016".
            if (preg_match_all('/\b(\d{1,2})[\s\-.\/]*(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*[\s\-.\/,]*(\d{4}|\d{2})\b/i', $line, $nm, PREG_SET_ORDER)) {
                foreach ($nm as $d) {
                    $mo = (int) date('n', strtotime('1 '.$d[2].' 2000'));
                    $y = strlen($d[3]) === 2 ? '20'.$d[3] : $d[3];
                    if (checkdate($mo, (int) $d[1], (int) $y)) {
                        $hints['dates'][] = ['raw' => $d[0], 'iso' => Carbon::createFromDate((int) $y, $mo, (int) $d[1])->format('Y-m-d')];
                    }
                }
            }

            // Time right after a date whose colon OCR lost: "08/10/2026 12 47", "08/10/2026 12.47".
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

    /** Lines that carry an amount but are not a purchased item. */
    // Tax words only need a word start: OCR often glues noise on ("SSTs4(", "GST@6%").
    private const NOT_ITEM = '/total|\btot\b|jumlah|\bsst|\bgst|\btax(?!i)|cukai|service\s*charge|rounding|bundar|\bcash\b|tunai|change|baki|\bpaid\b|payment|\bvisa\b|master|\bcard\b|debit|credit|tender|balance|\bdue\b|discount|diskaun|deposit|amount|\bqty\b|count|sold|points?\b|saving'
        // e-wallets / payment lines and receipt header lines that can carry a number
        .'|wallet|\btng|touch\s*n\s*go|grab\s*pay|\bboost\b|duitnow|shopee\s*pay|\bqr\b|invoice|order\s*no|cashier|\btable\b|\bcover\b|terminal|\bprn\b/i';

    /** Grand-total line (not SUBTOTAL): everything after it is payment / tax summary, not items. */
    private const TOTAL_LINE = '/\b(grand\s*total|net\s*total|total|tot|jumlah|amount\s*due)\b/i'; // "Tot Amovat" = OCR'd "Total Amount"

    /**
     * Purchased items: lines like "1 PEPSI (L) 3.90", "2 CHEEZY WEDGES TT @5.50 11.00 S", "Mineral Water 2 x 2.00 4.00",
     * "SPANISH LATTE (1) 1 15.90" (qty after the name). The amount at the end of a line is the line total.
     *
     * Add-ons / modifiers printed under an item ("- Oat Milk Upgrade +2.50", "- Less Sugar"):
     * with a price → their own line "+ Oat Milk Upgrade (add-on: SPANISH LATTE)" so the amounts still add up;
     * without a price → noted in the item's name. Only lines directly under an item count as modifiers.
     *
     * Charges between the items and the TOTAL — service charge, SST / GST / service tax, rounding — are only
     * added when they make the items add up exactly to the receipt total (a "GST @6% Paid" line that is already
     * included in the total is therefore not counted twice). Parsing stops at the TOTAL line: what follows is
     * payment (e-wallet, card, change) and tax summaries.
     *
     * @return array{0: array<int, array>, 1: bool} [items, whether they add up to the receipt total]
     */
    private function items(string $text, ?string $total): array
    {
        $money = '(\d{1,5})[.,](\d{2})\d?'; // "2.290" / "11.008" → OCR'd a trailing tax code as a digit
        $extras = []; // service charge / tax / rounding candidates
        $parent = null; // index of the item that modifier lines below belong to

        // POS receipts that print a unit price per item ("(13.00/ea)") get their items from that instead.
        $items = $this->perEachItems($text);
        $itemsLocked = $items !== [];

        foreach (preg_split('/\n/', $text) as $raw) {
            if (trim($raw) === '') {
                continue;
            }

            // Summary lines whose decimal point OCR lost: "SERVICE CHARGE (5%) 395", "Subtotal 7900,", "Bill rounding 005".
            if (preg_match('/subtotal|sub\s*total|service|charge|tax|rounding|total|jumlah/i', $raw) && ! preg_match('/\d[.,]\d{2}\b/', $raw)) {
                $raw = preg_replace('/(?<![\d.])(\d{1,4})\s?(\d{2})[,.]?\s*$/', '$1.$2', rtrim($raw));
            }

            if ($itemsLocked) {
                // Items already known: only look for charges and the TOTAL line.
                if (! preg_match(self::NOT_ITEM, $raw)) {
                    continue;
                }
            }

            $isModifier = (bool) preg_match('/^\s*[-+*•>]\s*\p{L}/u', $raw);
            // Invoice description sub-lines under an item: "a) 1 no PHILIPS 100w led flood light", "b) | no PVC-LINK …"
            $isDetail = (bool) preg_match('/^\s*[|»)\]]*\s*[a-e\d]?\)\s*\S/u', $raw);
            $line = trim(preg_replace('/^[^a-z0-9(]+/i', '', $raw));

            // Handwritten cash-bill row: "MINYAK GAMAT | 2 | 25/= | 50/=".
            if ($row = $this->cashBillRow($raw)) {
                $items[] = $row + ['notes' => []];
                $parent = array_key_last($items);

                continue;
            }

            // Last amount on the line (optionally signed "+2.50" / "-0.02" / OCR'd "~0.02"),
            // followed only by a short tax code / OCR noise (no digits).
            if (! preg_match('/^(.*?)\s([+\-~−]?)\s*'.$money.'\s*([^\d\s]{0,3})\s*[^\d]{0,3}$/iu', $line, $m)) {
                // Priceless modifier ("- Less Sugar (30%)") or invoice detail line ("a) 1 no PHILIPS …") → note it on the item above.
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
                    break; // payment, change and tax summary follow — no more items or charges
                }

                $percent = preg_match('/(\d{1,2})\s*%/', $before, $pm) ? " ({$pm[1]}%)" : '';
                if (preg_match('/service\s*charge|svc\s*chg/i', $before)) {
                    $extras[] = ['kind' => 'charge', 'label' => 'Service Charge'.$percent, 'cents' => $amount];
                } elseif (preg_match('/rounding|round\s*adj|bundar/i', $before)) {
                    // "~0.02": OCR lost the sign — try both directions when matching the total.
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

            // Leading item code on invoices: "A0032-1", OCR'd "Aoos2-1" / "A00i22" (must contain a real digit).
            // An optional row number in front of the code ("2 | A00i22 …", "1 |. A0032-1 …") goes with it.
            // Done before the reference-code check below, which would otherwise drop "A0032-1" rows.
            $before = preg_replace('/^(?:\d{1,3}\s*[|)\].]{0,2}\s+)?(?=\S*\d)[A-Za-z]{1,3}[0-9oOiIlsS]{2,5}(?:-\d+)?\s+(?=\S*\p{L}{2})/u', '', ltrim($before));

            // Reference codes ("TPW001-C1/286377"), phone numbers etc. are not items.
            if (preg_match('/\S*[\/\-]\S*\d{4,}|\d{4,}\S*[\/\-]/', $before)) {
                $parent = null;

                continue;
            }

            $qty = 1;
            $alt = null; // qty × unit price, when the printed amount disagrees with it (one digit misread)
            if (preg_match('/^(.*?)\s(\d{1,5})([.,]0{1,3})?\s+(\d{1,6})[.,](\d{2})$/', trim($before), $q) && (int) $q[2] > 0
                && (($calc = (int) $q[2] * ((int) $q[4] * 100 + (int) $q[5])) === $amount || $q[3] !== '')) {
                // Invoice row "description  qty  unit price  amount", e.g. "To supply & install with 3.00 280.00 840.00".
                // If qty × unit ≠ amount, it is only treated as an invoice row when the qty is printed invoice-style
                // ("3.00") — "WEDGES T 1 6.50 11.00" on a till receipt is not.
                $qty = (int) $q[2];
                $before = $q[1];
                if ($calc !== $amount) {
                    $alt = $calc; // e.g. "3.00 280,00 240.00": 840 OCR'd as 240 — the receipt total decides below
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
                // Keep $parent: more modifiers may follow for the same item.
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

        // Largest set of charges that makes items + charges = TOTAL (never both signs of one rounding).
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

        // Printed amounts don't add up, but qty × unit price does: a digit of the amount column was misread.
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
                // A negative rounding / discount is a discount on a zero-amount line.
                'rounding', 'discount' => $e['cents'] >= 0
                    ? ['item_name' => $e['label'], 'quantity' => '1', 'unit_price' => $fmt($e['cents']), 'amount' => '', 'tax_amount' => '']
                    : ['item_name' => $e['label'], 'quantity' => '1', 'unit_price' => null, 'amount' => '0.00', 'discount_amount' => $fmt($e['cents']), 'tax_amount' => ''],
            };
        }

        return [$result, true];
    }

    /**
     * POS receipts (e.g. FeedMe) that print each item as a block:
     *   1  P04 白胡椒... White Pepper              13.00
     *      Spicy Pork Noodle (13.00/ea)
     *      • 猪肉丸 Meat Ball (3pcs) + 1.80
     * On small photos OCR often loses the decimal point of the right-hand amount ("1760", "17 80"), but the
     * "(13.00/ea)" unit price and "+ 1.80" add-ons usually survive — so each line is rebuilt as
     * qty × unit price, plus one line per priced add-on. The caller then checks the sum against the TOTAL.
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
                continue; // not an item block (or unit price unreadable) — leave it to the user
            }
            $unit = (int) $em[1] * 100 + (int) strtr($em[2], 'OoGUD', '00000');

            $nameParts = [];
            $addons = [];
            foreach ($block['lines'] as $i => $l) {
                // Add-on: "• 猪肉丸 Meat Ball (3pcs) + 1.80", "+ ice +050", "+h? Miced Meat + 3.00"
                if ($i > 0 && preg_match('/^(.*?)\+\s*(\d{1,3})[.,\s]?(\d{2})\s*$/u', $l, $am) && preg_match('/^[\s+*•·\-]|\+\s*\d/u', $l)) {
                    $label = $this->cleanName($this->dropOcrJunk(ltrim($am[1], ' +*•·-')));
                    $addons[] = ['name' => $label !== '' ? $label : 'Add-on', 'cents' => (int) $am[2] * 100 + (int) $am[3]];

                    continue;
                }
                $l = preg_replace($each, '', $l);
                if ($i === 0) {
                    $l = preg_replace('/\s+[\d\s.,|]{2,}\S{0,2}$/u', '', $l); // unreadable right-hand amount "1760" / "17 80" / "11 二"
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

    /**
     * Drops what OCR made of Chinese characters / item codes in front of the English name:
     * "P15 瓦煲老鼠粉 Clay Pot" read as "PIS Fits #10) Clay Pot" → "Clay Pot". Cuts after the last junk token.
     */
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

        // Leading item code: "P04", "D11", "p08".
        if ($kept && preg_match('/^[A-Za-z]{1,2}\d{1,3}$/', $kept[0])) {
            array_shift($kept);
        }

        return implode(' ', $kept);
    }

    /**
     * A row of a handwritten cash bill, where amounts are whole ringgit written "25/=" (or "25/-"):
     *   "LL MInyAK GamAT | 2 | 25/-| So/="  →  MINYAK GAMAT, qty 2, RM 25.00, line RM 50.00
     * Handwriting OCR confuses digits with letters (S→5, o→0, I→1), so amounts are only accepted when
     * qty × unit price = amount holds (or one of them can be worked out exactly from the other two).
     *
     * @return array{name: string, qty: int, cents: int}|null
     */
    private function cashBillRow(string $raw): ?array
    {
        if (! preg_match('/\/\s*[=\-–>e]/u', $raw)) {
            return null; // no "/=" amounts on this line
        }

        $tokens = substr_count($raw, '|') >= 2
            ? array_map('trim', explode('|', $raw))
            : preg_split('/\s+/', trim($raw));

        // Walk back from the end collecting numbers until the item name is reached.
        $numbers = [];
        $nameIndex = null;
        for ($i = count($tokens) - 1; $i >= 0; $i--) {
            $token = $tokens[$i];
            if (preg_match('/^([0-9SOoIlZB]{1,5})\s*\/\s*[=\-–>e]?$/u', $token, $m)) {      // "So/=", "IS/e", "25/-"
                $numbers[] = (int) strtr($m[1], 'SOoIlZB', '5001128');
            } elseif (preg_match('/^\d{1,3}$/', $token)) {                                   // plain qty "2"
                $numbers[] = (int) $token;
            } elseif (preg_match_all('/\p{L}/u', $token) >= 3) {
                $nameIndex = $i;
                break;
            } // anything else ("|_|", "_", OCR specks) is skipped
        }

        if ($nameIndex === null || $numbers === []) {
            return null;
        }

        // $numbers is reversed: [amount, unit price, qty].
        [$amount, $price, $qty] = array_pad($numbers, 3, null);
        if ($qty !== null && $price !== null && $qty * $price !== $amount) {
            return null; // doesn't add up → OCR misread; let the user type it
        }
        if ($qty === null && $price !== null) {
            if ($price === 0 || $amount % $price !== 0) {
                return null;
            }
            $qty = intdiv($amount, $price);   // quantity unreadable: 35 / 35 = 1
        }
        $qty ??= 1;

        $name = preg_replace('/^\S{1,2}[\]\)\.|]?\s+(?=\p{L}{3})/u', '', trim($tokens[$nameIndex])); // row number "LL", "3]"
        $name = $this->cleanName($name);
        if (preg_match('/\p{Ll}\p{Lu}/u', $name)) {
            $name = mb_strtoupper($name); // handwriting OCR mixes case: "MInyAK GamAT"
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
        $name = preg_replace('/\s\d+[.,]\d{2}\b/', '', $name);                        // stray unit prices
        $name = preg_replace('/(\s+(?:[A-Z]{1,2}|\d))+$/', '', trim($name));          // trailing tax codes "T T", "TT", "1"
        if (! $keepPercent) {
            $name = preg_replace('/[^\p{L}\p{N}\s()&\/\-.,\']/u', '', $name);
            $name = preg_replace('/(?<=\p{L})\.(?=\s)/u', '', $name); // OCR speck: "CHEEZY. WEDGES"
        }

        return trim(preg_replace('/\s{2,}/', ' ', $name), ' .,-|=~');
    }

    /**
     * The shop's full address, e.g.
     *   "LOT LG-23, AOEN BIG SUBANG JAYA, NO 3 JALAN SS16/1, 47500 SUBANG JAYA, SELANGOR".
     * Anchored on the line with a Malaysian 5-digit postcode + place name; address lines directly above it
     * (LOT / NO / JALAN / LEVEL / TOWER…, up to 3) are added, stopping at the company-name line, and a state
     * alone on the next line is appended. Lines carrying money amounts are skipped, and a postcode inside a
     * longer number or after a dash (phone, tax, receipt no.) is ignored.
     */
    private function location(string $text): ?string
    {
        $lines = $this->cleanLines($text);

        // Only the seller's header: on invoices the buyer's address ("Bill to", customer block) follows the
        // INVOICE heading and must not be taken as the place of purchase.
        foreach ($lines as $i => $line) {
            // ($i >= 2: a "CUSTOMER COPY" banner above the shop name must not cut off the whole header)
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

        // Next line starting with a state name (OCR may garble the rest: "Selangor Daru! Ehsan").
        if (isset($lines[$anchor + 1]) && preg_match('/^('.self::STATES.')\b/i', $lines[$anchor + 1])
            && ! preg_match('/\d|tel|fax|gst|sst|www/i', $lines[$anchor + 1]) && mb_strlen($lines[$anchor + 1]) <= 40) {
            $parts[] = $lines[$anchor + 1];
        }

        $address = implode(', ', array_map(fn ($p) => trim($p, ' ,.;:'), $parts));

        return mb_substr(preg_replace('/\s*,\s*(,\s*)*/', ', ', $address), 0, 255);
    }

    /**
     * Fallback when the postcode is unreadable ("43300" OCR'd as "45.00"): a street line near the top
     * (JALAN / TAMAN / LORONG …) plus up to 3 following address lines, ending at the line that names the
     * state or MALAYSIA. Phone / e-mail / web lines end the address.
     */
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
                $parts = [$line]; // no state found: keep just the street line rather than guess further
            }

            return mb_substr(preg_replace('/\s*,\s*(,\s*)*/', ', ', implode(', ', array_map(fn ($p) => trim($p, ' ,.;:'), $parts))), 0, 255);
        }

        return null;
    }

    /** A street / building line above the postcode line — not the company name, phone, tax or web line. */
    private function isAddressLine(string $line): bool
    {
        if (preg_match('/\d+[.,]\d{2}\b/', $line)
            || preg_match('/\b(sdn|bhd|berhad|plt|enterprise|trading|restaurants?)\b|\(\d{3,}-[a-z]\)|\bco\.?\s*no\b|company\s*no|\broc\b|tel\b|fax\b|phone|gst|sst|reg\b|www|http|\.com|@\s*(gmail|yahoo|hotmail|outlook)|\S+@\S+\.(com|my|net)|invoice|receipt|order|cashier/i', $line)
            || preg_match_all('/[a-z]/i', $line) < 3) {
            return false;
        }

        return (bool) preg_match('/\b(lot|no|jalan|jln|lorong|lrg|persiaran|lebuh|taman|tmn|blok|block|level|lvl|aras|tingkat|unit|tower|menara|plaza|mall|kompleks|complex|bangunan|wisma|pusat|centre|center|square|seksyen|section|batu|kampung|kg|ss\d+)\b|\d/i', $line);
    }

    /** @return array<int, string> non-empty lines with OCR edge noise removed */
    private function cleanLines(string $text): array
    {
        return array_values(array_filter(
            array_map(fn ($l) => preg_replace('/^[\s|~=_\-—]+|[\s|~=_\-—]+$/u', '', $l), explode("\n", $text)),
            fn ($l) => $l !== '',
        ));
    }

    /** Index of the first line holding a real postcode followed by a place name. */
    private function postcodeLine(array $lines): ?int
    {
        foreach ($lines as $i => $line) {
            if (preg_match('/\d+[.,]\d{2}\b/', $line)
                || preg_match('/invoice|receipt|order|bill\s*no|inv\b|cashier|table|@\s*(gmail|yahoo|hotmail|outlook)|\S+@\S+\.(com|my|net)/i', $line) // "Invoice no: 32754 ORDER"
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

    /**
     * The shop name is normally the first real text line at the top of a receipt.
     * Skips web addresses, phone / tax / invoice lines and OCR noise; gives up after the first few lines.
     */
    private function merchant(string $text): ?string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), fn ($l) => $l !== ''));

        // Prefer the registered company line near the top: "ZUS COFFEE (M) SDN BHD" rather than a logo word "ZUS".
        // OCR often reads "Sdn Bhd" as "Son Bhd" / "Sda Bid" / "Sdn 8hd".
        $company = '/\b(sdn|son|sda|bhd|8hd|berhad|plt|enterprise|trading)\b|\b(sdn|son|sda)\.?\s*bid\b/i';
        foreach (array_slice($lines, 0, 8) as $i => $line) {
            if (! preg_match($company, $line) || preg_match('/www|http|\btel\b|\bgst\b|\bsst\b|invoice|receipt|total/i', $line)) {
                continue;
            }

            // Keep the receipt's own casing: "SON" → "SDN", "Sda" → "Sdn".
            $fix = fn (string $from, string $to) => fn ($m) => ctype_upper(str_replace('.', '', $m[0])) ? strtoupper($to) : $to;
            $clean = preg_replace(['/\(\s*(reg[^)]*|\d{3,}[^)]*)\)/i', '/[\[\]{}|]/'], '', $line);
            $clean = preg_replace_callback('/\b(son|sda)\b(?=\.?\s*(bhd|bid|8hd)\b)/i', $fix('son', 'Sdn'), $clean);
            $clean = preg_replace_callback('/(?<=sdn)(\.?\s*)\K(bid|8hd)\b/i', $fix('bid', 'Bhd'), $clean);
            $clean = trim($clean, " \t-~_=|.,:;'\"`*#@");
            // "SDN. BHD. (26388-T)" on its own line → the name is on the line above.
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
