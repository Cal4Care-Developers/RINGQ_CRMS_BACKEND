<?php

// Extension names are saved as "First Last": only the first letter of each
// word (after a space or hyphen) is upper-cased and the rest is kept as typed,
// so "McDonald", "O'Brien" and "RingQ L1" survive. Lowercase particles such as
// "van der berg" become "Van Der Berg".
class PersonName
{
    public static function capitalize($value)
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }
        return preg_replace_callback('/(^|[\s-])(\p{Ll})/u', function ($m) {
            return $m[1] . mb_strtoupper($m[2], 'UTF-8');
        }, $value) ?? $value;
    }
}
