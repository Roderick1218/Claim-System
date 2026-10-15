<?php

namespace App\Services;

use InvalidArgumentException;

/*
 * 所有金额都换成整数 cent 来算（数量用千分位整数），不用 float，避免 0.1 + 0.2 这类误差导致对不上账。
 *
 * 每行的算法：
 *   gross      = round_half_up(quantity × unit_price)      有单价时
 *              = amount                                    没单价时（收据只印了总额）
 *   line_total = gross − discount + tax
 *
 * 整单：subtotal = Σ gross，discount = Σ discount，tax = Σ tax，total = Σ line_total
 */
class ClaimCalculationService
{
    /**
     * @param  array{quantity?: mixed, unit_price?: mixed, amount?: mixed, discount_amount?: mixed, tax_amount?: mixed}  $line
     * @return array{quantity: string, unit_price: ?string, discount_amount: string, tax_amount: string, gross: string, line_total: string}
     */
    public function calculateLine(array $line): array
    {
        $qtyMilli = $this->filled($line['quantity'] ?? null) ? $this->toScaled($line['quantity'], 3) : 1000;
        $priceCents = $this->filled($line['unit_price'] ?? null) ? $this->toScaled($line['unit_price'], 2) : null;
        $discount = $this->filled($line['discount_amount'] ?? null) ? $this->toScaled($line['discount_amount'], 2) : 0;
        $tax = $this->filled($line['tax_amount'] ?? null) ? $this->toScaled($line['tax_amount'], 2) : 0;

        if ($priceCents !== null) {
            // 千分位 × cent，再除 1000 四舍五入回 cent
            $gross = intdiv($qtyMilli * $priceCents + 500, 1000);
        } elseif ($this->filled($line['amount'] ?? null)) {
            $gross = $this->toScaled($line['amount'], 2);
        } else {
            throw new InvalidArgumentException('Either unit price or amount is required.');
        }

        $lineTotal = $gross - $discount + $tax;

        return [
            'quantity' => $this->fromScaled($qtyMilli, 3),
            'unit_price' => $priceCents === null ? null : $this->fromScaled($priceCents, 2),
            'discount_amount' => $this->fromScaled($discount, 2),
            'tax_amount' => $this->fromScaled($tax, 2),
            'gross' => $this->fromScaled($gross, 2),
            'line_total' => $this->fromScaled($lineTotal, 2),
        ];
    }

    /**
     * @param  array<int, array>  $calculatedLines  calculateLine() 的结果
     * @return array{subtotal_amount: string, discount_amount: string, tax_amount: string, total_amount: string}
     */
    public function calculateTotals(array $calculatedLines): array
    {
        $sum = ['gross' => 0, 'discount_amount' => 0, 'tax_amount' => 0, 'line_total' => 0];

        foreach ($calculatedLines as $line) {
            foreach ($sum as $key => $_) {
                $sum[$key] += $this->toScaled($line[$key], 2);
            }
        }

        return [
            'subtotal_amount' => $this->fromScaled($sum['gross'], 2),
            'discount_amount' => $this->fromScaled($sum['discount_amount'], 2),
            'tax_amount' => $this->fromScaled($sum['tax_amount'], 2),
            'total_amount' => $this->fromScaled($sum['line_total'], 2),
        ];
    }

    // "12.5" 配 scale 3 → 12500。多出来的小数位四舍五入；不是普通小数格式（如 1e3、1,000）直接报错
    public function toScaled(mixed $value, int $scale): int
    {
        $value = trim((string) $value);

        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $value, $m)) {
            throw new InvalidArgumentException("Invalid decimal value [{$value}].");
        }

        $fraction = $m[3] ?? '';
        $roundUp = false;
        if (strlen($fraction) > $scale) {
            $roundUp = (int) $fraction[$scale] >= 5;
            $fraction = substr($fraction, 0, $scale);
        }

        $int = (int) ($m[2].str_pad($fraction, $scale, '0'));
        if ($roundUp) {
            $int++;
        }

        return $m[1] === '-' ? -$int : $int;
    }

    // toScaled 的反向：12500 配 scale 3 → "12.500"
    public function fromScaled(int $value, int $scale): string
    {
        $negative = $value < 0;
        $digits = str_pad((string) abs($value), $scale + 1, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '').substr($digits, 0, -$scale).'.'.substr($digits, -$scale);
    }

    private function filled(mixed $value): bool
    {
        return $value !== null && trim((string) $value) !== '';
    }
}
