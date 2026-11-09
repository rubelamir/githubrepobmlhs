<?php

declare(strict_types=1);

namespace School\Accounting\Support;

/**
 * Taka amount in Bengali words for receipts, e.g. 125050 paisa →
 * "এক হাজার দুইশত পঞ্চাশ টাকা পঞ্চাশ পয়সা মাত্র".
 * Uses the Indian scale (হাজার, লক্ষ, কোটি).
 */
final class AmountInWords
{
    private const UNDER_HUNDRED = [
        '', 'এক', 'দুই', 'তিন', 'চার', 'পাঁচ', 'ছয়', 'সাত', 'আট', 'নয়',
        'দশ', 'এগারো', 'বারো', 'তেরো', 'চৌদ্দ', 'পনেরো', 'ষোলো', 'সতেরো', 'আঠারো', 'উনিশ',
        'বিশ', 'একুশ', 'বাইশ', 'তেইশ', 'চব্বিশ', 'পঁচিশ', 'ছাব্বিশ', 'সাতাশ', 'আটাশ', 'ঊনত্রিশ',
        'ত্রিশ', 'একত্রিশ', 'বত্রিশ', 'তেত্রিশ', 'চৌত্রিশ', 'পঁয়ত্রিশ', 'ছত্রিশ', 'সাঁইত্রিশ', 'আটত্রিশ', 'ঊনচল্লিশ',
        'চল্লিশ', 'একচল্লিশ', 'বিয়াল্লিশ', 'তেতাল্লিশ', 'চুয়াল্লিশ', 'পঁয়তাল্লিশ', 'ছেচল্লিশ', 'সাতচল্লিশ', 'আটচল্লিশ', 'ঊনপঞ্চাশ',
        'পঞ্চাশ', 'একান্ন', 'বাহান্ন', 'তিপ্পান্ন', 'চুয়ান্ন', 'পঞ্চান্ন', 'ছাপ্পান্ন', 'সাতান্ন', 'আটান্ন', 'ঊনষাট',
        'ষাট', 'একষট্টি', 'বাষট্টি', 'তেষট্টি', 'চৌষট্টি', 'পঁয়ষট্টি', 'ছেষট্টি', 'সাতষট্টি', 'আটষট্টি', 'ঊনসত্তর',
        'সত্তর', 'একাত্তর', 'বাহাত্তর', 'তিয়াত্তর', 'চুয়াত্তর', 'পঁচাত্তর', 'ছিয়াত্তর', 'সাতাত্তর', 'আটাত্তর', 'ঊনআশি',
        'আশি', 'একাশি', 'বিরাশি', 'তিরাশি', 'চুরাশি', 'পঁচাশি', 'ছিয়াশি', 'সাতাশি', 'আটাশি', 'ঊননব্বই',
        'নব্বই', 'একানব্বই', 'বিরানব্বই', 'তিরানব্বই', 'চুরানব্বই', 'পঁচানব্বই', 'ছিয়ানব্বই', 'সাতানব্বই', 'আটানব্বই', 'নিরানব্বই',
    ];

    private function __construct()
    {
    }

    public static function taka(int $paisa): string
    {
        $negative = $paisa < 0;
        $paisa = abs($paisa);
        $taka = intdiv($paisa, 100);
        $fraction = $paisa % 100;
        $text = ($taka === 0 ? 'শূন্য' : self::number($taka)) . ' টাকা';
        if ($fraction > 0) {
            $text .= ' ' . self::UNDER_HUNDRED[$fraction] . ' পয়সা';
        }

        return ($negative ? 'ঋণাত্মক ' : '') . $text . ' মাত্র';
    }

    public static function number(int $value): string
    {
        if ($value === 0) {
            return 'শূন্য';
        }
        $parts = [];
        $crore = intdiv($value, 10000000);
        $value %= 10000000;
        if ($crore > 0) {
            $parts[] = self::number($crore) . ' কোটি';
        }
        foreach ([[100000, 'লক্ষ'], [1000, 'হাজার']] as [$unit, $word]) {
            $count = intdiv($value, $unit);
            $value %= $unit;
            if ($count > 0) {
                $parts[] = self::UNDER_HUNDRED[$count] . ' ' . $word;
            }
        }
        $hundreds = intdiv($value, 100);
        $value %= 100;
        if ($hundreds > 0) {
            $parts[] = self::UNDER_HUNDRED[$hundreds] . 'শত';
        }
        if ($value > 0) {
            $parts[] = self::UNDER_HUNDRED[$value];
        }

        return implode(' ', $parts);
    }
}
