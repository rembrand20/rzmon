<?php
declare(strict_types=1);

/**
 * Logo en icoon van RzMon (1.73.0). Het woordmerk staat inline in de pagina: de letters krijgen de tekstkleur
 * (currentColor), dus werkt het vanzelf in het lichte en het donkere thema; de z houdt zijn oranje verloop.
 * Bestanden: public/favicon.svg, public/icons/, brand/ (README, Pushover). Opnieuw maken: zie brand/README.md.
 */
final class Brand
{
    private static int $n = 0;

    private static function grad(string $id): string
    {
        return '<linearGradient id="' . $id . '" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#FFCB3D"/>'
            . '<stop offset="1" stop-color="#FF8A00"/></linearGradient>';
    }

    /** Icoon (z op een donkere tegel) + woordmerk RzMon */
    public static function logo(): string
    {
        $g = 'rzg' . (++self::$n);
        $i = 'rzi' . self::$n;
        return '<span class="rz-logo" role="img" aria-label="RzMon">'
            . '<svg class="rz-icon" viewBox="0 0 120 120" aria-hidden="true"><defs>' . self::grad($i) . '</defs>'
            . '<rect width="120" height="120" rx="26" fill="#151B2E"/><g transform="translate(-45.85 -45.13) scale(1.45)">'
            . '<path d="M54 51 H90 L55 94 H92" fill="none" stroke="url(#' . $i . ')" stroke-width="13.8" stroke-linejoin="round" stroke-linecap="round"/></g></svg>'
            . '<svg class="rz-word" viewBox="-1 -1 278 108" aria-hidden="true"><defs>' . self::grad($g) . '</defs>'
            . '<g fill="none" stroke="currentColor" stroke-width="12" stroke-linejoin="round" stroke-linecap="round">'
            . '<path d="M6 100 V6 H32 A25 25 0 0 1 32 56 H6 M30 56 L57 95"/>'
            . '<path d="M54 51 H90 L55 94 H92" stroke="url(#' . $g . ')"/>'
            . '<path d="M108 100 V8 L135 66 L162 8 V100"/>'
            . '<circle cx="200" cy="74" r="22" stroke-width="11"/><path d="M238 100 V68 A16 16 0 0 1 270 68 V100 M238 52 V68" stroke-width="11"/>'
            . '</g></svg></span>';
    }

    /** Favicon, icoon voor het beginscherm en het manifest (in <head>) */
    public static function head(): string
    {
        return '<link rel="icon" href="favicon.svg" type="image/svg+xml">'
            . '<link rel="icon" href="icons/icon-32.png" sizes="32x32" type="image/png">'
            . '<link rel="apple-touch-icon" href="icons/apple-touch-icon.png">'
            . '<link rel="manifest" href="manifest.webmanifest">'
            . '<meta name="theme-color" content="#151B2E">';
    }
}
