<?php

namespace App\Services\ImportExport;

use DateTimeInterface;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;

/**
 * Reads the first sheet of an .xlsx/.ods/.csv file into plain string cells.
 *
 * Everything is handed on as trimmed text: the import has to parse a German
 * "1.250,50" out of a CSV cell anyway, so normalizing the spreadsheet's own
 * typed values (float, bool, date) into the same shape keeps exactly one
 * parsing path in ListingImporter instead of two.
 */
class SpreadsheetReader
{
    /**
     * @return array{headers: list<string>, rows: list<array{line: int, cells: list<string>}>}
     */
    public function read(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Datei nicht gefunden: {$path}");
        }

        $reader = $this->readerFor($path);
        $reader->open($path);

        $headers = [];
        $rows = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $line => $row) {
                $cells = array_map($this->stringify(...), $row->toArray());

                if ($line === 1) {
                    $headers = $cells;

                    continue;
                }

                // Excel loves to hand out thousands of visually empty trailing rows.
                if (implode('', $cells) === '') {
                    continue;
                }

                $rows[] = ['line' => $line, 'cells' => $cells];
            }

            break; // Only the first sheet — the workbook's second sheet is the help text.
        }

        $reader->close();

        return ['headers' => $headers, 'rows' => $rows];
    }

    private function readerFor(string $path): XlsxReader|CsvReader
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'csv') {
            return new XlsxReader;
        }

        $options = new CsvOptions;
        $options->FIELD_DELIMITER = $this->sniffDelimiter($path);

        return new CsvReader($options);
    }

    /**
     * German Excel writes CSV with semicolons, everyone else with commas — and a
     * wrong guess turns the whole file into one column, so sniff it from the
     * header line rather than making the content team pick a setting.
     */
    private function sniffDelimiter(string $path): string
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return ',';
        }

        $line = fgets($handle);
        fclose($handle);

        if ($line === false) {
            return ',';
        }

        $counts = [
            ';' => substr_count($line, ';'),
            ',' => substr_count($line, ','),
            "\t" => substr_count($line, "\t"),
        ];

        arsort($counts);
        $best = (string) array_key_first($counts);

        return $counts[$best] > 0 ? $best : ',';
    }

    private function stringify(mixed $value): string
    {
        $value = match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'ja' : 'nein',
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_float($value) => rtrim(rtrim(sprintf('%.10F', $value), '0'), '.'),
            is_scalar($value) => (string) $value,
            default => '',
        };

        // A CSV saved by a German Excel is Windows-1252, not UTF-8.
        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        }

        return trim(str_replace("\u{FEFF}", '', $value));
    }
}
