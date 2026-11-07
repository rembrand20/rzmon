<?php
declare(strict_types=1);

/**
 * Logo en icoon van RzSense (1.214.0, eerder RzMon): een donkere tegel met een schild en "Rz", signaalbogen uit de
 * rechterbovenhoek en het woordmerk Rz//Sense AI met de slogan. De tegel is in het lichte en het donkere thema
 * hetzelfde; de letters krijgen hun kleur uit app.css (--rz-name, --rz-acc, --rz-slogan). "Rz" in de tegel is een
 * pad (Orbitron ExtraBold), dus het icoon heeft geen lettertype nodig; het woordmerk gebruikt Orbitron uit
 * public/assets/fonts/. De technische naam blijft rzmon (map, add-on, service).
 * Bestanden: public/favicon.svg, public/apple-touch-icon.png, public/assets/icons/, brand/ (README, Pushover).
 */
final class Brand
{
    private static int $n = 0;

    /** "Rz" in Orbitron ExtraBold, 23 px, gecentreerd op x = 54, basislijn y = 67 (viewBox 120) */
    public const RZ = 'M50.79 67.0 45.90 61.16H50.23L54.29 65.95V67.0ZM37.77 67.0V50.46H51.00Q51.91 50.46 52.66 50.91Q53.42 51.35 53.87 52.11Q54.33 52.87 54.33 53.77V58.13Q54.33 59.03 53.87 59.78Q53.42 60.54 52.66 60.99Q51.91 61.44 51.00 61.44L41.08 61.46V67.0ZM41.21 58.10H50.84Q50.90 58.10 50.94 58.07Q50.98 58.03 50.98 57.97V53.90Q50.98 53.85 50.94 53.81Q50.90 53.77 50.84 53.77H41.21Q41.16 53.77 41.12 53.81Q41.08 53.85 41.08 53.90V57.97Q41.08 58.03 41.12 58.07Q41.16 58.10 41.21 58.10ZM56.70 67.0V63.54L65.24 56.93H56.70V53.66H70.23V57.11L61.69 63.72H70.23V67.0Z';

    /** Het icoon als SVG (viewBox 0 0 120 120); $id maakt de verlopen uniek op de pagina */
    public static function icon(string $id, string $attr = ''): string
    {
        return '<svg viewBox="0 0 120 120"' . $attr . '><defs>'
            . '<linearGradient id="' . $id . 't" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#1c3170"/><stop offset="1" stop-color="#0e1838"/></linearGradient>'
            . '<linearGradient id="' . $id . 'l" x1="0" y1="1" x2="1" y2="0"><stop offset="0" stop-color="#2f6bff"/><stop offset="1" stop-color="#22e3ff"/></linearGradient></defs>'
            . '<rect x="3" y="3" width="114" height="114" rx="28" fill="url(#' . $id . 't)" stroke="url(#' . $id . 'l)" stroke-width="2.5"/>'
            . '<path class="rz-sh" d="M54 22 L82 31 V54 C82 74 70 88 54 96 C38 88 26 74 26 54 V31 Z" fill="#17306e" stroke="url(#' . $id . 'l)" stroke-width="4.5" stroke-linejoin="round"/>'
            . '<path d="' . self::RZ . '" fill="#ffffff"/>'
            . '<circle class="rz-src" cx="92" cy="28" r="3.6" fill="#22e3ff"/>'
            . '<path class="rz-a1" d="M92 17 A11 11 0 0 1 103 28" fill="none" stroke="#22e3ff" stroke-width="4.5" stroke-linecap="round"/>'
            . '<path class="rz-a2" d="M92 9 A19 19 0 0 1 111 28" fill="none" stroke="#22e3ff" stroke-width="4.5" stroke-linecap="round"/></svg>';
    }

    /** Icoon + woordmerk Rz//Sense AI + slogan (zijbalk, inlogpagina: .brand.big) */
    public static function logo(): string
    {
        $id = 'rzx' . (++self::$n);
        return '<span class="rz-logo" role="img" aria-label="Rz//Sense AI">'
            . self::icon($id, ' class="rz-icon" aria-hidden="true"')
            . '<span class="rz-word" aria-hidden="true"><span class="rz-name">Rz<i>//</i>Sense<sup>AI</sup></span>'
            . '<span class="rz-slogan">Watching your servers, <br>so you don\'t have to.</span></span></span>';
    }

    /** Favicon, icoon voor het beginscherm en het manifest (in <head>) */
    public static function head(): string
    {
        return '<link rel="icon" href="favicon.svg" type="image/svg+xml">'
            . '<link rel="icon" href="assets/icons/icon-32.png" sizes="32x32" type="image/png">'
            . '<link rel="apple-touch-icon" href="apple-touch-icon.png">'
            . '<link rel="manifest" href="manifest.webmanifest">'
            . '<link rel="preload" href="assets/fonts/orbitron.woff2" as="font" type="font/woff2" crossorigin>'
            . '<meta name="theme-color" content="#0e1838">';
    }
}
