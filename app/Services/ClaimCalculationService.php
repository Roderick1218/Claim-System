<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * All money maths is done in integer cents (quantity in thousandths) — never floats.
 *
 * Line rule (applied in this order):
 *   gross      = round_half_up(quantity × unit_price)      when a unit price is given
 *              = amount                                    when the unit price is empty (receipt shows a total only)
 *   line_total = gross − discount + tax
 *
 * Claim: subtotal = Σ gross, discount = Σ discount, tax = Σ tax, total = Σ line_total.
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
     * @param  array<int, array>  $calculatedLines  output of calculateLine()
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

    /** "12.5" with scale 3 → 12500. Rejects anything that is not a plain decimal. */
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
