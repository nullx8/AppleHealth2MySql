<?php
/**
 * Memory-light reader for large Health Auto Export JSON files.
 *
 * The whole file is held as ONE string (≈ file size in RAM), but never decoded
 * as a whole: the scanner walks the structure and json_decode()s only single
 * data points / single workouts. Yields the same tuples as explodePayload():
 *   [section, name, units, point]
 */

declare(strict_types=1);

final class JsonScanner
{
    private int $i = 0;
    private int $len;

    public function __construct(private string $s)
    {
        $this->len = strlen($s);
    }

    private function ws(): void
    {
        $this->i += strspn($this->s, " \t\r\n", $this->i);
    }

    private function peek(): string
    {
        $this->ws();
        if ($this->i >= $this->len) {
            throw new RuntimeException('unexpected end of JSON');
        }
        return $this->s[$this->i];
    }

    private function expect(string $c): void
    {
        if ($this->peek() !== $c) {
            throw new RuntimeException("expected '$c' at byte {$this->i}, got '{$this->s[$this->i]}'");
        }
        $this->i++;
    }

    /** End offset (exclusive) of the string starting at $i (which is a quote). */
    private function stringEnd(int $i): int
    {
        $j = $i + 1;
        while (true) {
            $q = strpos($this->s, '"', $j);
            if ($q === false) {
                throw new RuntimeException('unterminated string');
            }
            // count backslashes before the quote
            $b = 0;
            for ($k = $q - 1; $k > $i && $this->s[$k] === '\\'; $k--) {
                $b++;
            }
            if ($b % 2 === 0) {
                return $q + 1;
            }
            $j = $q + 1;
        }
    }

    /** Skip one value; return [start, end) of its raw text. */
    private function valueSpan(): array
    {
        $c = $this->peek();
        $start = $this->i;
        if ($c === '"') {
            $this->i = $this->stringEnd($this->i);
        } elseif ($c === '{' || $c === '[') {
            $depth = 0;
            $j = $this->i;
            while (true) {
                $j += strcspn($this->s, '"{}[]', $j);
                if ($j >= $this->len) {
                    throw new RuntimeException('unterminated container');
                }
                $ch = $this->s[$j];
                if ($ch === '"') {
                    $j = $this->stringEnd($j);
                    continue;
                }
                $depth += ($ch === '{' || $ch === '[') ? 1 : -1;
                $j++;
                if ($depth === 0) {
                    break;
                }
            }
            $this->i = $j;
        } else {
            $this->i += strcspn($this->s, ",}] \t\r\n", $this->i);
        }
        return [$start, $this->i];
    }

    private function raw(array $span): string
    {
        return substr($this->s, $span[0], $span[1] - $span[0]);
    }

    private function decode(array $span): mixed
    {
        return json_decode($this->raw($span), true, 512, JSON_BIGINT_AS_STRING);
    }

    /** Iterate object members: yields key => nothing; caller must consume the value. */
    private function objectKeys(): Generator
    {
        $this->expect('{');
        if ($this->peek() === '}') {
            $this->i++;
            return;
        }
        while (true) {
            $key = $this->decode($this->valueSpan());
            $this->expect(':');
            yield (string)$key;          // caller consumes value here
            $c = $this->peek();
            $this->i++;
            if ($c === '}') {
                return;
            }
            if ($c !== ',') {
                throw new RuntimeException("expected ',' or '}' at byte " . ($this->i - 1));
            }
        }
    }

    /** Iterate array elements: yields once per element; caller consumes it. */
    private function arrayItems(): Generator
    {
        $this->expect('[');
        if ($this->peek() === ']') {
            $this->i++;
            return;
        }
        while (true) {
            yield;
            $c = $this->peek();
            $this->i++;
            if ($c === ']') {
                return;
            }
            if ($c !== ',') {
                throw new RuntimeException("expected ',' or ']' at byte " . ($this->i - 1));
            }
        }
    }

    public function points(): Generator
    {
        if ($this->peek() !== '{') {
            throw new RuntimeException('top level is not an object');
        }
        foreach ($this->objectKeys() as $top) {
            if ($top !== 'data' || $this->peek() !== '{') {
                // Unknown top-level key: keep it as one row.
                yield ['(top)', $top, null, $this->decode($this->valueSpan())];
                continue;
            }
            foreach ($this->objectKeys() as $section) {
                if ($this->peek() !== '[') {
                    yield [$section, '(section)', null, $this->decode($this->valueSpan())];
                    continue;
                }
                foreach ($this->arrayItems() as $_) {
                    yield from $this->item($section);
                }
            }
        }
    }

    /** One element of a section: metric (with data list) or e.g. a workout. */
    private function item(string $section): Generator
    {
        if ($this->peek() !== '{') {
            yield [$section, '(value)', null, $this->decode($this->valueSpan())];
            return;
        }
        $meta = [];
        $pointSpans = null;
        foreach ($this->objectKeys() as $key) {
            if ($key === 'data' && $this->peek() === '[') {
                $pointSpans = [];
                if (isset($meta['name'])) {
                    // name (and usually units) already known: stream without buffering
                    $name  = (string)$meta['name'];
                    $units = isset($meta['units']) && is_scalar($meta['units']) ? (string)$meta['units'] : null;
                    foreach ($this->arrayItems() as $_) {
                        yield [$section, $name, $units, $this->decode($this->valueSpan())];
                    }
                    continue;
                }
                foreach ($this->arrayItems() as $_) {
                    $pointSpans[] = $this->valueSpan();
                }
            } else {
                $meta[$key] = $this->decode($this->valueSpan());
            }
        }
        $name  = (string)($meta['name'] ?? '(unnamed)');
        $units = isset($meta['units']) && is_scalar($meta['units']) ? (string)$meta['units'] : null;
        if ($pointSpans === null) {
            yield [$section, $name, $units, $meta];
            return;
        }
        foreach ($pointSpans as $span) {
            yield [$section, $name, $units, $this->decode($span)];
        }
    }
}
