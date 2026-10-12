<?php

namespace Tests\Unit;

use App\Services\ClaimCalculationService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ClaimCalculationServiceTest extends TestCase
{
    private ClaimCalculationService $calc;

    protected function setUp(): void
    {
        $this->calc = new ClaimCalculationService;
    }

    public function test_spec_example_lines(): void
    {
        $this->assertSame('4.00', $this->calc->calculateLine(['quantity' => '2', 'unit_price' => '2.00'])['line_total']);
        $this->assertSame('12.00', $this->calc->calculateLine(['quantity' => '1', 'unit_price' => '12'])['line_total']);
        $this->assertSame('61.50', $this->calc->calculateLine(['quantity' => '30', 'unit_price' => '2.05'])['line_total']);
    }

    public function test_fractional_quantity_rounds_half_up_to_cents(): void
    {
        // 12.5 L × 2.05 = 25.625 → 25.63
        $line = $this->calc->calculateLine(['quantity' => '12.5', 'unit_price' => '2.05']);
        $this->assertSame('12.500', $line['quantity']);
        $this->assertSame('25.63', $line['line_total']);
    }

    public function test_discount_then_tax(): void
    {
        $line = $this->calc->calculateLine(['quantity' => '3', 'unit_price' => '10', 'discount_amount' => '5', 'tax_amount' => '1.50']);
        $this->assertSame('30.00', $line['gross']);
        $this->assertSame('26.50', $line['line_total']);
    }

    public function test_total_only_line_uses_amount_when_unit_price_empty(): void
    {
        $line = $this->calc->calculateLine(['quantity' => '', 'unit_price' => '', 'amount' => '88.90']);
        $this->assertNull($line['unit_price']);
        $this->assertSame('1.000', $line['quantity']);
        $this->assertSame('88.90', $line['line_total']);
    }

    public function test_missing_price_and_amount_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calc->calculateLine(['quantity' => '1']);
    }

    public function test_totals_have_no_float_drift(): void
    {
        $lines = array_map(fn () => $this->calc->calculateLine(['unit_price' => '0.10']), range(1, 3));
        $totals = $this->calc->calculateTotals($lines);
        $this->assertSame('0.30', $totals['total_amount']);
        $this->assertSame('0.30', $totals['subtotal_amount']);
    }
}
