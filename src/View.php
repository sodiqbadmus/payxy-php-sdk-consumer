<?php

declare(strict_types=1);

namespace Playground;

/**
 * Minimal HTML layout shared by every page.
 */
final class View
{
    public static function page(string $title, string $body, string $extraHead = ''): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . self::e($title) . ' · Payxy Playground</title>'
            . '<link rel="stylesheet" href="/assets/app.css">' . $extraHead . '</head>'
            . '<body><header class="top"><a href="/" class="brand">Payxy <span>Playground</span></a>'
            . '<span class="pill">Sandbox · payxy/payxy-php-sdk from Packagist</span></header>'
            . '<main><h1>' . self::e($title) . '</h1>' . $body . '</main></body></html>';
    }

    /**
     * @param array<string, string> $rows label => already-escaped HTML
     */
    public static function table(array $rows): string
    {
        $html = '<table>';
        foreach ($rows as $label => $value) {
            $html .= '<tr><th>' . self::e($label) . '</th><td>' . $value . '</td></tr>';
        }

        return $html . '</table>';
    }

    public static function badge(string $text, string $tone): string
    {
        return '<span class="badge ' . self::e($tone) . '">' . self::e($text) . '</span>';
    }

    public static function e(?string $value): string
    {
        return htmlspecialchars((string) $value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
