<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Разрешены: <a href="" title="">, <code>, <i>, <strong>.
 * Все остальные теги и спецсимволы экранируются.
 * Если разрешённые теги записаны неверно или не закрыты (невалидный XHTML),
 * бросается InvalidArgumentException.
 */
final class CommentSanitizer
{
    private const ALLOWED = ['a', 'code', 'i', 'strong'];
    private const A_ATTRS = ['href', 'title'];

    public static function sanitize(string $input): string
    {
        $parts = preg_split('/(<[^<>]*>)/u', $input, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        if ($parts === false) {
            throw new InvalidArgumentException('Text contains invalid characters.');
        }

        $out = '';
        $stack = [];

        foreach ($parts as $part) {
            if (! preg_match('/^<(\/?)([A-Za-z][A-Za-z0-9]*)([^<>]*)>$/', $part, $m)) {
                $out .= self::esc($part);
                continue;
            }

            [, $closing, $name, $rest] = $m;
            $lower = strtolower($name);

            // неразрешённый тег -> показываем как обычный текст
            if (! in_array($lower, self::ALLOWED, true)) {
                $out .= self::esc($part);
                continue;
            }

            if ($name !== $lower) {
                throw new InvalidArgumentException("Tag <{$name}> must be lowercase.");
            }

            if ($closing === '/') {
                if (trim($rest) !== '') {
                    throw new InvalidArgumentException("Invalid closing tag </{$name}>.");
                }
                if (array_pop($stack) !== $name) {
                    throw new InvalidArgumentException("Closing tag </{$name}> is in the wrong place.");
                }
                $out .= "</{$name}>";
                continue;
            }

            if ($name === 'a') {
                if (in_array('a', $stack, true)) {
                    throw new InvalidArgumentException('Nested <a> tags are not allowed.');
                }
                $out .= '<a'.self::anchorAttrs($rest).' rel="nofollow ugc">';
            } else {
                if ($rest !== '') {
                    throw new InvalidArgumentException("Tag <{$name}> cannot have attributes.");
                }
                $out .= "<{$name}>";
            }

            $stack[] = $name;
        }

        if ($stack) {
            throw new InvalidArgumentException('Tag <'.end($stack).'> is not closed.');
        }

        return $out;
    }

    private static function anchorAttrs(string $rest): string
    {
        if (! preg_match('/^(?:\s+[a-z]+="[^"]*")*\s*$/', $rest)) {
            throw new InvalidArgumentException('Tag <a> has invalid attributes. Use <a href="" title="">.');
        }

        preg_match_all('/\s+([a-z]+)="([^"]*)"/', $rest, $pairs, PREG_SET_ORDER);

        $attrs = [];
        foreach ($pairs as [, $key, $value]) {
            if (! in_array($key, self::A_ATTRS, true) || isset($attrs[$key])) {
                throw new InvalidArgumentException("Attribute \"{$key}\" is not allowed in <a>.");
            }

            $value = html_entity_decode($value, ENT_QUOTES | ENT_XML1, 'UTF-8');

            if ($key === 'href' && $value !== '' && ! self::isSafeUrl($value)) {
                throw new InvalidArgumentException('Only http(s) links are allowed in href.');
            }

            $attrs[$key] = $value;
        }

        $html = '';
        foreach ($attrs as $key => $value) {
            $html .= " {$key}=\"".self::esc($value).'"';
        }

        return $html;
    }

    private static function isSafeUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }
}
