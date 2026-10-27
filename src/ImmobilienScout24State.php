<?php

declare(strict_types=1);

namespace App;

final class ImmobilienScout24State
{
    /**
     * ImmoScout24 embeds its page state as JavaScript, not strict JSON
     * (it contains bare `undefined` values).
     */
    public static function extract(string $html, string $variable = 'window.__INITIAL_STATE__'): array
    {
        $position = strpos($html, $variable);
        if ($position === false) {
            throw new \RuntimeException(sprintf('Could not find %s in the ImmoScout24 response.', $variable));
        }

        $start = strpos($html, '{', $position);
        if ($start === false) {
            throw new \RuntimeException(sprintf('Could not find the start of %s.', $variable));
        }

        $json = self::jsToJson(self::extractJsonObject($html, $start));

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Could not decode the ImmoScout24 state: '.$exception->getMessage(), 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('The ImmoScout24 state is not an object.');
        }

        return $decoded;
    }

    /**
     * Extracts a balanced JSON object from a JavaScript source string.
     */
    private static function extractJsonObject(string $input, int $start): string
    {
        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($input);

        for ($i = $start; $i < $length; $i++) {
            $char = $input[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
                continue;
            }

            if ($char === '{') {
                $depth++;
                continue;
            }

            if ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($input, $start, $i - $start + 1);
                }
            }
        }

        throw new \RuntimeException('Unterminated ImmoScout24 state.');
    }

    private static function jsToJson(string $js): string
    {
        $result = '';
        $length = strlen($js);
        $inString = false;
        $escaped = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $js[$i];

            if ($inString) {
                $result .= $char;
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
                $result .= $char;
                continue;
            }

            $previousChar = $i > 0 ? $js[$i - 1] : '';
            $nextChar = $i + 9 < $length ? $js[$i + 9] : '';

            if ($char === 'u'
                && substr($js, $i, 9) === 'undefined'
                && !self::isIdentifierChar($previousChar)
                && !self::isIdentifierChar($nextChar)
            ) {
                $result .= 'null';
                $i += 8;
                continue;
            }

            $result .= $char;
        }

        return $result;
    }

    private static function isIdentifierChar(string $char): bool
    {
        return $char !== '' && (ctype_alnum($char) || $char === '_' || $char === '$');
    }
}
