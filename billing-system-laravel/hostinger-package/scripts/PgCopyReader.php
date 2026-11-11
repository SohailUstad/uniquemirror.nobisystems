<?php

declare(strict_types=1);

/**
 * Streaming reader for the public-table text COPY sections emitted by pg_dumpall.
 * It deliberately does not connect to a database or retain whole table contents.
 */
final class PgCopyReader
{
    /**
     * Yield each COPY block as ['table' => string, 'columns' => list<string>, 'rows' => iterable].
     * Each row is a list of string|null values in column order. Consume a block's rows
     * before advancing to the next block; the input stream is shared and forward-only.
     * Non-public COPY blocks and all other SQL are skipped.
     *
     * @return Generator<int, array{table:string, columns:list<string>, rows:iterable}>
     */
    public static function blocks(string $path): Generator
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Cannot open PostgreSQL dump.');
        }

        try {
            while (($line = fgets($handle)) !== false) {
                if (preg_match('/^COPY\s+public\b/i', $line) !== 1) {
                    continue;
                }
                if (preg_match('/^COPY public\.([A-Za-z_][A-Za-z0-9_]*) \(([A-Za-z_][A-Za-z0-9_]*(?:, ?[A-Za-z_][A-Za-z0-9_]*)*)\) FROM stdin;\r?\n?$/D', $line, $m) !== 1) {
                    throw new UnexpectedValueException('Malformed or unsupported public COPY block header.');
                }

                $table = $m[1];
                $columns = explode(',', $m[2]);
                $columns = array_map('trim', $columns);
                if (count($columns) !== count(array_unique($columns))) {
                    throw new UnexpectedValueException("Duplicate column name in public COPY block for table {$table}.");
                }

                yield [
                    'table' => $table,
                    'columns' => $columns,
                    'rows' => self::rows($handle, $table, count($columns)),
                ];
            }

            if (!feof($handle)) {
                throw new RuntimeException('Failed while reading PostgreSQL dump.');
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return Generator<int, list<string|null>> */
    private static function rows($handle, string $table, int $columnCount): Generator
    {
        $rowNumber = 0;
        while (($line = fgets($handle)) !== false) {
            if (str_ends_with($line, "\n")) {
                $line = substr($line, 0, -1);
                if (str_ends_with($line, "\r")) {
                    $line = substr($line, 0, -1);
                }
            }
            if ($line === '\\.') {
                return;
            }

            ++$rowNumber;
            $fields = explode("\t", $line);
            if (count($fields) !== $columnCount) {
                throw new UnexpectedValueException("Unsupported row shape in public COPY block for table {$table} at row {$rowNumber}.");
            }

            $row = [];
            foreach ($fields as $field) {
                $row[] = $field === '\\N' ? null : self::unescape($field, $table, $rowNumber);
            }
            yield $row;
        }

        throw new UnexpectedValueException("Unterminated public COPY block for table {$table}.");
    }

    private static function unescape(string $value, string $table, int $rowNumber): string
    {
        $out = '';
        $length = strlen($value);
        for ($i = 0; $i < $length; ++$i) {
            $char = $value[$i];
            if ($char !== "\\") {
                $out .= $char;
                continue;
            }
            if (++$i >= $length) {
                throw new UnexpectedValueException("Trailing escape in public COPY row for table {$table} at row {$rowNumber}.");
            }

            $escape = $value[$i];
            $simple = [
                "\\" => "\\", 't' => "\t", 'n' => "\n", 'r' => "\r",
                'b' => "\x08", 'f' => "\x0c", 'v' => "\x0b",
            ];
            if (isset($simple[$escape])) {
                $out .= $simple[$escape];
                continue;
            }
            if ($escape >= '0' && $escape <= '7') {
                $digits = $escape;
                while (strlen($digits) < 3 && $i + 1 < $length && $value[$i + 1] >= '0' && $value[$i + 1] <= '7') {
                    $digits .= $value[++$i];
                }
                $byte = octdec($digits);
                if ($byte > 255) {
                    throw new UnexpectedValueException("Invalid octal escape in public COPY row for table {$table} at row {$rowNumber}.");
                }
                $out .= chr($byte);
                continue;
            }
            if ($escape === 'x') {
                $digits = '';
                while (strlen($digits) < 2 && $i + 1 < $length && ctype_xdigit($value[$i + 1])) {
                    $digits .= $value[++$i];
                }
                if ($digits === '') {
                    throw new UnexpectedValueException("Invalid hexadecimal escape in public COPY row for table {$table} at row {$rowNumber}.");
                }
                $out .= chr(hexdec($digits));
                continue;
            }

            // PostgreSQL treats an unrecognized escaped character as that character.
            $out .= $escape;
        }
        return $out;
    }
}
