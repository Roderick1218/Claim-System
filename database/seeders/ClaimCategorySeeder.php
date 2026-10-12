<?php

namespace Database\Seeders;

use App\Models\ClaimCategory;
use Illuminate\Database\Seeder;

/**
 * Default categories + receipt keywords (English / Malay / common Malaysian brands) for auto-selection.
 * Safe to re-run: keywords are only filled in where an admin hasn't set any yet.
 */
class ClaimCategorySeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->defaults() as $name => [$requiresReceipt, $keywords]) {
            $category = ClaimCategory::firstOrCreate(['name' => $name], ['requires_receipt' => $requiresReceipt, 'is_active' => true]);
            if (blank($category->keywords) && $keywords !== []) {
                $category->forceFill(['keywords' => implode("\n", $keywords)])->save();
            }
        }
    }

    /** @return array<string, array{0: bool, 1: array<int, string>}> */
    public static function defaults(): array
    {
        return [
            'Petrol / Fuel' => [true, [
                'petronas', 'shell', 'petron', 'caltex', 'bhpetrol', 'bh petrol', 'five petroleum', 'mesra',
                'petrol', 'fuel', 'diesel', 'ron95', 'ron 95', 'ron97', 'ron 97', 'primax', 'v-power', 'dynamic diesel',
                'euro 5', 'pump', 'litre', 'liter', 'minyak petrol', 'stesen minyak', 'budi95',
            ]],
            'Toll' => [true, [
                'toll', 'tol', 'plus malaysia', 'plus expressways', 'lebuhraya', 'expressway', 'highway', 'smart tag',
                'smarttag', 'rfid', 'kesas', 'sprint', 'duke', 'ldp', 'npe', 'akleh', 'besraya', 'guthrie corridor',
                'lekas', 'sistem lingkaran', 'penang bridge', 'jambatan', 'plaza tol',
            ]],
            'Parking' => [true, [
                'parking', 'car park', 'carpark', 'parkir', 'letak kereta', 'tempat letak', 'park & ride', 'park and ride',
                'flexiparking', 'smart parking', 'secure parking', 'parkeasy', 'mbpj', 'dbkl parking', 'season parking',
                'entry time', 'exit time', 'duration',
            ]],
            'Food & Beverage' => [true, [
                'kfc', 'mcdonald', 'mcd', 'burger king', 'domino', 'pizza hut', 'subway', 'texas chicken', 'marrybrown',
                'starbucks', 'zus', 'tealive', 'chatime', 'coffee bean', 'old town', 'oldtown', 'secret recipe', 'nando',
                'sushi king', 'kenny rogers', 'mamak', 'nasi kandar', 'kopitiam', 'restaurant', 'restoran', 'cafe', 'café',
                'bistro', 'kedai makan', 'food court', 'medan selera', 'bakery', 'noodle', 'nasi', 'mee', 'kuey teow',
                'roti', 'kopi', 'teh', 'coffee', 'latte', 'tea', 'juice', 'drink', 'minuman', 'makanan', 'food',
                'dine in', 'dine-in', 'take away', 'takeaway', 'bungkus', 'grabfood', 'foodpanda', 'shopeefood', 'catering',
                'chicken', 'ayam', 'burger', 'sandwich', 'cake', 'dessert', 'bubble tea',
            ]],
            'Accommodation' => [true, [
                'hotel', 'resort', 'inn', 'lodge', 'motel', 'homestay', 'airbnb', 'hostel', 'guest house', 'guesthouse',
                'chalet', 'suites', 'check-in', 'check in', 'check-out', 'check out', 'room', 'bilik', 'penginapan',
                'night', 'malam', 'tourism tax', 'cukai pelancongan', 'agoda', 'booking.com',
            ]],
            'Materials' => [true, [
                'hardware', 'perkakasan', 'electrical', 'elektrik', 'engineering', 'construction', 'pembinaan', 'building material',
                'cement', 'simen', 'steel', 'besi', 'paint', 'cat', 'cable', 'wire', 'pipe', 'paip', 'pvc', 'plywood',
                'timber', 'kayu', 'sand', 'pasir', 'brick', 'bata', 'tile', 'jubin', 'roof', 'bumbung', 'zinc',
                'screw', 'skru', 'bolt', 'nut', 'nail', 'paku', 'sealant', 'silicone', 'waterproof', 'membrane',
                'led', 'lamp', 'bulb', 'light fitting', 'switch', 'socket', 'breaker', 'supply & install', 'supply and install',
                'mr diy', 'mr. diy', 'ace hardware', 'kedai besi',
            ]],
            'Transport' => [true, [
                'grab', 'grabcar', 'taxi', 'teksi', 'e-hailing', 'airasia ride', 'maxim', 'indrive',
                'lrt', 'mrt', 'monorail', 'ktm', 'komuter', 'ets', 'erl', 'klia ekspres', 'rapidkl', 'rapid kl', 'bus',
                'bas', 'train', 'keretapi', 'flight', 'airline', 'airasia', 'malaysia airlines', 'firefly', 'batik air',
                'boarding pass', 'ticket', 'tiket', 'ferry', 'feri', 'car rental', 'sewa kereta', 'touch n go reload',
            ]],
            'Office Supplies' => [true, [
                'stationery', 'alat tulis', 'popular', 'mph', 'office supply', 'office supplies', 'paper', 'kertas',
                'a4', 'printer', 'toner', 'ink', 'dakwat', 'cartridge', 'pen', 'pencil', 'pensel', 'file', 'fail',
                'folder', 'stapler', 'envelope', 'sampul', 'photocopy', 'fotostat', 'printing', 'cetak', 'laminate',
                'binding', 'notebook', 'post-it', 'marker', 'courier', 'poslaju', 'pos malaysia', 'j&t', 'ninja van', 'stamp', 'setem',
            ]],
            'Other' => [false, []],
        ];
    }
}
