<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

class Csv
{
    // A text cell that starts with = + - @ can run as a formula when the CSV is opened in Excel
    public static function cell(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }

    // $rows can be a generator / cursor, so a big export never loads everything into memory
    public static function download(string $filename, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows the text correctly
            fputcsv($out, $header);

            foreach ($rows as $row) {
                fputcsv($out, array_map([self::class, 'cell'], $row));
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
