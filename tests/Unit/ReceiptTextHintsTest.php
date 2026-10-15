<?php

namespace Tests\Unit;

use App\Services\ReceiptTextHints;
use PHPUnit\Framework\TestCase;

class ReceiptTextHintsTest extends TestCase
{
    // KFC 收据照片的真实 Tesseract 输出（psm 4）
    private const KFC = <<<'TXT'
        Taxinvo iced 841-3
        25 Nov 16 10:22:28
        2 cHEEZY WEDGES TT ©.50 11.008
        1 HS 15PC TT 62.70 S
        CASH 2 100.00
        Total item sold:
        Sub Total 73.70
        Total: 73.70
        Payment 100.00
        Change Que 26.30
        TXT;

    public function test_finds_total_but_not_subtotal_and_month_name_date(): void
    {
        $hints = (new ReceiptTextHints)->find(self::KFC);

        $this->assertSame(['73.70'], $hints['totals']);
        $this->assertSame([['raw' => '25 Nov 16', 'iso' => '2016-11-25']], $hints['dates']);
        $this->assertSame(['10:22'], $hints['times']);
    }

    public function test_numeric_day_first_dates(): void
    {
        $hints = (new ReceiptTextHints)->find("DATE: 05/10/2026\n2026-10-06\nJUMLAH RM 12.50");

        $this->assertSame(['2026-10-05', '2026-10-06'], array_column($hints['dates'], 'iso'));
        $this->assertSame(['12.50'], $hints['totals']);
    }

    public function test_nothing_is_guessed_from_empty_or_unclear_text(): void
    {
        $this->assertSame(
            ['merchants' => [], 'locations' => [], 'totals' => [], 'dates' => [], 'times' => [], 'items' => [], 'items_match_total' => false],
            (new ReceiptTextHints)->find("~ ee\n31/02/2026\nTOTAL ITEMS 3"),
        );
    }

    public function test_items_with_sst_added_as_tax_line_when_it_makes_the_total_add_up(): void
    {
        // KFC 收据照片裁切 + 放大 2x 后的真实 Tesseract 输出，边缘的 OCR 杂讯原样保留
        $text = <<<'TXT'
            | KFC RESTAURANTS (MALAYSIA) SDN BHD (123456-M)
            DATE: 15/10/2023 18:45
            STORE: 1234 TERMINAL: 03 —
            1 SNACK PLATE COMBO 19.90
            (2pc Chicken, Wedges, Drink) =
            =| 1 CHEEZY WEDGES (R) 7.90 &
            1 PEPSI (L) 3.90 |
            | 1 ALA CARTE CHICKEN (1pc) 6.50 |
            =| SUBTOTAL 38.20 Ff
            | SSTs4( OE Ase sid 2.290
            ~ TOTAL RM 40.49 |
            ~ PAID BY VISA 40.49 |
            CHANGE 0.00
            ORDER NUMBER: 145
            TXT;

        $h = (new ReceiptTextHints)->find($text);

        $this->assertTrue($h['items_match_total']);
        $this->assertSame(['SNACK PLATE COMBO', 'CHEEZY WEDGES (R)', 'PEPSI (L)', 'ALA CARTE CHICKEN (1pc)', 'SST'], array_column($h['items'], 'item_name'));
        $this->assertSame(['19.90', '7.90', '3.90', '6.50', null], array_column($h['items'], 'unit_price'));
        $this->assertSame('2.29', $h['items'][4]['tax_amount']);
        $this->assertSame('0.00', $h['items'][4]['amount']);
    }

    public function test_included_gst_is_not_added_twice(): void
    {
        $text = "2 CHEEZY. WEDGES T 1 6.50 11.00 oS\n| HS 15PC T T 62.70 \$\nCASH 100.00\nSub Total 73.70\nTotal: 73.70\nPayment 100.00\nGST @6% Paid 4,17";

        $h = (new ReceiptTextHints)->find($text);

        $this->assertTrue($h['items_match_total']);
        $this->assertCount(2, $h['items']);
        $this->assertSame(['CHEEZY WEDGES', '2', '5.50'], [$h['items'][0]['item_name'], $h['items'][0]['quantity'], $h['items'][0]['unit_price']]);
        $this->assertSame(['HS 15PC', '1', '62.70'], [$h['items'][1]['item_name'], $h['items'][1]['quantity'], $h['items'][1]['unit_price']]);
    }

    public function test_add_ons_qty_after_name_and_split_decimal_total(): void
    {
        // ZUS Coffee 收据的真实 Tesseract 输出（2026-10-10）
        $text = <<<'TXT'
            DESCRIPTION QTY PRICE(RM)
            SPANISH LATTE (1) 1 15.90

            - Less Sugar (30K)

            - Oat Milk Upgrade +2.50
            ICED BUTTERSCOTCH LATTE 1 16.90
            SMOKED DUCK SANDWICH 1 13.90
            ONDOLLEH CAKE 1 14.50
            SUBTOTAL 63.70
            SST (6%) 3.82
            TOTAL DUE RM67 .52
            PAYMENT MODE: E-WALLET (DUITNOW) 67.52

            CHANGE 0.00
            ITEMS COUNT: 4
            THANK YOU FOR SUPPORTING ZUS!
            TXT;

        $h = (new ReceiptTextHints)->find($text);

        $this->assertSame(['67.52'], $h['totals']);
        $this->assertTrue($h['items_match_total']);
        $this->assertSame([
            'SPANISH LATTE (1) (Less Sugar (30K))',
            '+ Oat Milk Upgrade (add-on: SPANISH LATTE (1))',
            'ICED BUTTERSCOTCH LATTE',
            'SMOKED DUCK SANDWICH',
            'ONDOLLEH CAKE',
            'SST (6%)',
        ], array_column($h['items'], 'item_name'));
        $this->assertSame(['15.90', '2.50', '16.90', '13.90', '14.50', null], array_column($h['items'], 'unit_price'));
        $this->assertSame(['1', '1', '1', '1', '1', '1'], array_column($h['items'], 'quantity'));
        $this->assertSame('3.82', $h['items'][5]['tax_amount']);
    }

    public function test_kopitiam_receipt_with_service_charge_service_tax_rounding_and_ewallet(): void
    {
        // Iron House Kopitiam 收据的真实 Tesseract 输出（WhatsApp 传来的照片，2026-10-12）
        $text = <<<'TXT'
            3 KOUPLTLAM
            Tronwil] Sda Bid
            201801014037 (1276963-V)
            TAX INVOICE
            Table Wo: HE,
            t No TPHO01-C1/286377 1. 55%
            Order No: WO286222 Cover: 1
            date 05/10/2026 #1 12:55 PM
            ##+ Dine In ##©
            1 Dry Pan Hee 12.90
            1 OIE (Knife-Cut)
            2 Tie Guan Yin (Cold) 82.20 4.40
            1 Nast Lenak Fried Chicken L2g 16.90
            1 Nasi Lemak wt Rendang Pork 17.90 «
            1 Cendo] 8.90
            1 Classic Soup Pan Hee 12,90
            1 CHU (Thick)
            1 Minced Pork 3.90
            1 Coke 3,90
            8 Subtotal 81.70
            Service Charge@10%: 8.17
            Service Taxé6s: 4.90
            Rounding Adj ~0.02
            Net Total 94.75
            TnGo Wallet 94.78
            Tax Summary Taxable Tax
            Service Tax 81.70 4.90
            TXT;

        $h = (new ReceiptTextHints)->find($text);

        $this->assertSame(['Tronwil Sdn Bhd'], $h['merchants']);
        $this->assertSame(['94.75'], $h['totals']);
        $this->assertTrue($h['items_match_total']);
        $this->assertSame([
            'Dry Pan Hee', 'Tie Guan Yin (Cold)', 'Nast Lenak Fried Chicken L2g', 'Nasi Lemak wt Rendang Pork',
            'Cendo', 'Classic Soup Pan Hee', 'Minced Pork', 'Coke',
            'Service Charge (10%)', 'SERVICE TAX', 'Rounding Adj',
        ], array_column($h['items'], 'item_name'));
        $this->assertSame(['2', '2.20'], [$h['items'][1]['quantity'], $h['items'][1]['unit_price']]);
        $this->assertSame('8.17', $h['items'][8]['unit_price']);
        $this->assertSame('4.90', $h['items'][9]['tax_amount']);
        $this->assertSame('0.02', $h['items'][10]['discount_amount']); // ~0.02 由总额判定为 -0.02
    }

    public function test_pos_receipt_with_per_each_prices_and_lost_decimal_points(): void
    {
        // Family Noodle House 收据的真实 Tesseract 输出（FeedMe POS，471px 的 WhatsApp 照片，2026-10-12）。
        // 右边金额的小数点都丢了（"1760"、"17 80"、"400"），"(13.00/ea)" 倒是读得出来
        $text = <<<'TXT'
            FAMILY NOODLE HOUSE TAMAN EQUINE
            FAMILY 2 NOODLF HOUSE
            REG NO. 202203142340 (003404962 M)
            24, JALAN EQUINE 9D, TAMAN EQUINE, 45.00, SER
            KEMBANGAN, SELANGOR, MALAYSIA
            +601 76802116
            familynoodiehouse2022@gmail com
            Invoice no: 32754 ORDER
            Date: 08/10/2026 12.47
            Cashier Lun T9
            aty tem Price (MYR)
            1 PIS Fits #10) Clay Pot Lou Shu Fun 1760
            (11.50/ea)
            1 POs AAI: FHF) White Pepper 13.00
            Spicy Pork Noodle (13 00/ea)
            1 SSA#t) BWita Spicy Creamy Park 17 80
            Noodle (13 00/ea)
            + D4. Meat Ball (Spes) + 1.80
            +h? Miced Meat + 3.00
            1 D17 i898 HF Ho ka sai (Mk) (4 50/ea) 400
            hice +050
            1 (EF%) Bal 11h) Bitter Gourd Fish 1950
            Ball Noodle (Dry) (10.50/ea)
            1 Meg) IRIAILH) Spicy Creamy Pork
            Noodle (13.00/ea)
            ++iGR Egg (pes) +1.20
            Pte Miced Meat + 3.00
            1 p08 Wf4# Kopi (3.50/ea) 400
            + ice +0 50
            7 Qy
            Subtotal 7900,
            SERVICE CHARGE (5%) 395
            Bill rounding 005
            Total (MYR) 83.00
            bulT NOW 83 00
            Change 0.00
            Sel. eT CHARGE 5% 7900 395
            TXT;

        $h = (new ReceiptTextHints)->find($text);

        $this->assertTrue($h['items_match_total']);
        $this->assertSame(['83.00'], $h['totals']);
        $this->assertSame(['12:47'], $h['times']);
        $this->assertSame(['FAMILY NOODLE HOUSE TAMAN EQUINE'], $h['merchants']);
        $this->assertSame(['24, JALAN EQUINE 9D, TAMAN EQUINE, 45.00, SER, KEMBANGAN, SELANGOR, MALAYSIA'], $h['locations']);

        $names = array_column($h['items'], 'item_name');
        $this->assertContains('Clay Pot Lou Shu Fun', $names);
        $this->assertContains('White Pepper Spicy Pork Noodle', $names);
        $this->assertContains('Bitter Gourd Fish Ball Noodle (Dry)', $names);
        $this->assertContains('Kopi', $names);
        $this->assertContains('Service Charge (5%)', $names);
        $this->assertCount(4, array_filter($names, fn ($n) => str_starts_with($n, '+ ') && str_contains($n, 'Spicy Creamy')));
    }

    public function test_a4_invoice_table_rows_seller_address_and_misread_total(): void
    {
        // A4 供应商发票照片的真实 Tesseract --psm 6 输出（2026-10-12）
        $text = <<<'TXT'
            Systematic Electrical Engineering Sdn. Bhd.
            CeaNe | resTaaH
            wie 224. Jalan PIS 704, Taman Sei Many
            Bare 6 | 5 Jalan Ketang Lama.
            ssood Petaling Jays. Selangor Darul Ehswn
            Tek: 03-77833579
            Werte Ow sees com my Erm! systemancifjeresd com IY
            INVOICE
            — nvoice NO s £77204
            tap Site Sdn Bhd Date oe 10/2026
            | 41900 Sen Kembangan. Selangor Customer Code pr! A021
            ] AOA? Site: No. |) Jalan High-Tech 6! (new warehouse}
            | ) | Aoos2-1 To supply & install with 3.00 280.00 840.00
            ») 1 no PHILIPS 100w led flood light
            b) | no PVC-LINK ABS junction box
            2 | A00i22 To supply & install with 2.00 175.00 350.00
            4) ITALY 12° globe light firting
            b) | no PHILIPS E27 19w led bulb
            » | aoo32-3 To replace TS 36w led tbe 10.00 20.00 200.00
            |
            sae Tot Amovat RMD] 1,390.00,
            Piewse creas all cheques made payable to Systematic Electrical Engineering Sdn-Bhd
            TXT;

        $h = (new ReceiptTextHints)->find($text);

        $this->assertSame(['Systematic Electrical Engineering Sdn. Bhd'], $h['merchants']);
        $this->assertSame(['1390.00'], $h['totals']);
        $this->assertTrue($h['items_match_total']);
        $this->assertSame(['3', '2', '10'], array_column($h['items'], 'quantity'));
        $this->assertSame(['280.00', '175.00', '20.00'], array_column($h['items'], 'unit_price'));
        $this->assertSame('To supply & install with (1 no PHILIPS 100w led flood light, no PVC-LINK ABS junction box)', $h['items'][0]['item_name']);
        $this->assertStringStartsWith('To supply & install with (ITALY', $h['items'][1]['item_name']);
        // 要的是 INVOICE 上面的卖家地址，不是下面买家的 "41900 Sen Kembangan"
        $this->assertStringContainsString('Petaling', $h['locations'][0]);
        $this->assertStringNotContainsString('Kembangan', $h['locations'][0]);
    }

    public function test_invoice_rows_with_clean_item_codes_and_one_misread_amount(): void
    {
        // 同一张发票重拍后的真实 Tesseract 输出（2026-10-12）：编号读对了（"A0032-1" 不能被当成参考编号丢掉），
        // 但 840.00 读成了 240.00
        $text = <<<'TXT'
            Systematic Electrical Engineering Sdn. Bhd.
            46000 Petaling Jaya,Sclangor Darul Ehsan.
            INVOICE
            : Invoice No SEE/7204
            Atap Sifu Sda Bhd Date 06/10/2026
            No Code Description Quantity | Unit Ee ‘Amount
            40032 Site: No. 11 Jalan High-Tech 6/1 (new warehouse)
            1 |. A0032-1 To supply & install with: 3.00 280,00 240.00
            a) | no PHILIPS 100w led flood light
            b) | no PVC-LINK ABS junction box
            2 | A0032-2 To supply & install with: 2.00 175,00 350.00
            a) ITALY 12" globe light fitting
            b) | no PHILIPS E27 19w led bulb
            3 | A0032-3 To replace T8 36w led tube 10.00 20.00 200.00
            Total Amount (RM) 1,390.00
            TXT;

        $h = (new ReceiptTextHints)->find($text);

        $this->assertSame([['raw' => '06/10/2026', 'iso' => '2026-10-06']], $h['dates']);
        $this->assertTrue($h['items_match_total']);
        $this->assertSame(['3', '2', '10'], array_column($h['items'], 'quantity'));
        $this->assertSame(['280.00', '175.00', '20.00'], array_column($h['items'], 'unit_price'));
        $this->assertStringStartsWith('To replace T8 36w led tube', $h['items'][2]['item_name']);
    }

    public function test_misread_amount_is_not_replaced_when_the_total_does_not_confirm_it(): void
    {
        // qty × 单价 = 840 但印的是 240，而总额 440 = 240 + 200 跟印出的金额一致，所以保留印出的金额
        $h = (new ReceiptTextHints)->find("To supply 3.00 280.00 240.00\nTo replace 10.00 20.00 200.00\nTotal Amount 440.00");

        $this->assertTrue($h['items_match_total']);
        $this->assertSame(['80.00', '20.00'], array_column($h['items'], 'unit_price')); // 3 × 80.00 = 印出的 240.00
    }

    public function test_discount_line_is_applied_when_it_makes_the_total_match(): void
    {
        $h = (new ReceiptTextHints)->find("1 NASI LEMAK 10.00\n1 TEH TARIK 5.00\nDISCOUNT 3.00\nTOTAL 12.00\nCASH 20.00");

        $this->assertTrue($h['items_match_total']);
        $this->assertSame(['NASI LEMAK', 'TEH TARIK', 'Discount'], array_column($h['items'], 'item_name'));
        $this->assertSame('3.00', $h['items'][2]['discount_amount']);
    }

    public function test_handwritten_cash_bill_rows_are_recovered_using_qty_times_price(): void
    {
        // 手写 cash bill 的真实 Tesseract 输出（2026-10-10），"/=" 表示整数令吉
        $text = <<<'TXT'
            PARTICULAR QUANTITY UNIT PRICE AMOUNT
            BUTIR-BUTIR KUANTITI HARGA NILAI
            LL MInyAK GamAT | 2 | 25/-| So/=
            2| SABUN HERBA | 3 | IS/e| 45/>
            3] TONIK KESIHATAN | |_| 35/>| 35/=
            ME Dison sae | 05-90
            Be ais f=
            Sef HT. RAIBR oe [2S
            TXT;

        $h = (new ReceiptTextHints)->find($text);

        $this->assertSame(['MINYAK GAMAT', 'SABUN HERBA', 'TONIK KESIHATAN'], array_column($h['items'], 'item_name'));
        $this->assertSame(['2', '3', '1'], array_column($h['items'], 'quantity'));
        $this->assertSame(['25.00', '15.00', '35.00'], array_column($h['items'], 'unit_price'));
        $this->assertFalse($h['items_match_total']); // 总额读不出来，要用户自己核对，折扣和邮费也要自己加
    }

    public function test_cash_bill_row_that_does_not_multiply_out_is_rejected(): void
    {
        // 2 × 25 ≠ 70，肯定有一位读错，留给用户填，不去猜
        $this->assertSame([], (new ReceiptTextHints)->find('MINYAK GAMAT | 2 | 25/= | 70/=')['items']);
    }

    public function test_footer_lines_starting_with_dash_are_not_treated_as_modifiers(): void
    {
        $h = (new ReceiptTextHints)->find("1 HS 15PC 62.70\nCASH 100.00\n- Complete a brief survey at\nTotal: 62.70");

        $this->assertSame(['HS 15PC'], array_column($h['items'], 'item_name'));
    }

    public function test_items_that_do_not_add_up_are_still_offered_but_flagged(): void
    {
        $h = (new ReceiptTextHints)->find("1 NASI LEMAK 8.50\n1 TEH TARIK 2.50\nTOTAL 12.00");

        $this->assertCount(2, $h['items']);
        $this->assertFalse($h['items_match_total']); // 表单会显示差额警告
    }

    public function test_location_is_read_from_the_shop_address(): void
    {
        // KFC 收据的真实 Tesseract 输出（2026-10-10），地址在第 2、3 行
        $kfc = <<<'TXT'
            | KFC RESTAURANTS (MALAYSIA) SDN BHD (123456-M)
            | LOT LG-23, AOEN BIG SUBANG JAYA
            NO 3 JALAN SS16/1, 47500 SUBANG JAYA, SELANGOR

            TAX INVOICE / SIMPLIFIED TAX INVOICE bn
            GST REG. NO: 001234567890 ~
            DATE: 15/10/2023 18:45 =
            RECEIPT NO: 001-0987654 —
            STORE: 1234 TERMINAL: 03 =
            1 PEPSI (L) 3.90 —e
            = TOTAL RM 40.49 —
            TXT;
        $hints = new ReceiptTextHints;

        // 完整地址：postcode 那行加上面的街道行，到公司名为止
        $this->assertSame(['LOT LG-23, AOEN BIG SUBANG JAYA, NO 3 JALAN SS16/1, 47500 SUBANG JAYA, SELANGOR'], $hints->find($kfc)['locations']);
        $this->assertSame(['47500 SUBANG JAYA SELANGOR'], $hints->find("ABC\n47500 SUBANG JAYA SELANGOR")['locations']);
        $this->assertSame(['NO 12 JALAN MAWAR, 88300 KOTA KINABALU, SABAH'], $hints->find("NO 12 JALAN MAWAR\n88300 KOTA KINABALU\nSABAH")['locations']);
        $this->assertSame(['No 12, Jalan Klang, 58000 Kuala Lumpur'], $hints->find("KEDAI ABC SDN BHD\nNo 12, Jalan Klang, 58000 Kuala Lumpur")['locations']);
        $this->assertSame(['40400 SHAH ALAM SELANGOR DARUL EHSAN'], $hints->find('40400 SHAH ALAM SELANGOR DARUL EHSAN')['locations']);
    }

    public function test_full_address_from_first_kfc_photo_with_ocr_specks(): void
    {
        // 第一张 KFC 照片的真实 Tesseract 输出
        $text = "KFC (PENINSULAR MALAYSIA)\nSON. BHD. (26388-T)\nLvl 12 Tower 1,VSquare@PJ City Centre\nJalan Utara 46200 Petaling Jaya\nSelangor Daru! Ehsan\n\nGST 1D:000947937280";

        $this->assertSame(
            ['Lvl 12 Tower 1, VSquare@PJ City Centre, Jalan Utara 46200 Petaling Jaya, Selangor Daru! Ehsan'],
            (new ReceiptTextHints)->find($text)['locations'],
        );
    }

    public function test_no_location_is_guessed_from_numbers_that_are_not_postcodes(): void
    {
        $hints = new ReceiptTextHints;

        $this->assertSame([], $hints->find("GST REG NO: 001234567890\nTEL: 03-12345 ABC\nRECEIPT NO: 001-0987654")['locations']);
        $this->assertSame([], $hints->find("12345 NASI LEMAK 8.50\nTOTAL 8.50")['locations']); // 这是物品编号 + 价钱，不是 postcode
        $this->assertSame([], $hints->find("ABC TRADING\nTOTAL 4.00")['locations']);
    }

    public function test_merchant_is_first_real_text_line_skipping_web_address_and_noise(): void
    {
        $hints = new ReceiptTextHints;

        // 公司名分成两行，"SDN" 读成 "SON"，注册号要去掉
        $this->assertSame(['KFC (PENINSULAR MALAYSIA) SDN. BHD'], $hints->find("www KFC COm.my\n\nKFC (PENINSULAR MALAYSIA)\nSON. BHD. (26988-T)")['merchants']);
        // 注册公司名优先于顶部的 logo 字
        $this->assertSame(['ZUS COFFEE (M) SDN BHD'], $hints->find("Be\nR\nZUS\ni COFFEE\n| ZUS COFFEE (M) SDN BHD\n(Reg No: 2019010XXXXX)")['merchants']);
        $this->assertSame(['KEDAI RUNCIT ABC SDN BHD'], $hints->find("KEDAI RUNCIT ABC SDN BHD\nNo 12, Jalan Klang")['merchants']);
        $this->assertSame(['ABC TRADING'], $hints->find("~ ee\nTel: 03-1234567\nABC TRADING")['merchants']);
    }
}
