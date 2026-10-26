<?php
declare(strict_types=1);

namespace Wm;

/**
 * Single source of truth for SEO: the list of public pages, the shared <head> partial, robots.txt and sitemap.xml.
 * The site origin is taken from the request (never stored in the repo). Keep `PAGES` in sync with every public page
 * and bump its `updated` date when the page's content changes (see .claude/skills/seo/SKILL.md).
 */
final class Seo
{
    public const SITE_NAME = 'Werewolf, Mafia & Impostor';

    /** Every public (indexable) page: path => metadata. Anything not listed here must be noindex. */
    public const PAGES = [
        '/' => [
            'title' => 'Play Werewolf, Mafia & Impostor Online Free',
            'description' => 'Play Werewolf, Mafia and Impostor online for free in your browser. Create a game, share the code or QR, and let the server moderate. No sign-up.',
            'updated' => '2026-10-23',
        ],
        '/how-to-play' => [
            'title' => 'How to Play Werewolf, Mafia & Impostor: Rules',
            'description' => 'Learn the rules of Werewolf, Mafia and Impostor: day and night phases, voting, scoring and every role explained, from Seer to Godfather.',
            'updated' => '2026-10-26',
        ],
    ];

    /** https://host[/subdir] of the current request, no trailing slash. */
    public static function origin(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        if (!preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?$/', $host)) {
            $host = 'localhost';
        }
        $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
        // when the repo root is the web root, the root .htaccess maps /x to public/x: the public/ part is not in the URL
        $dir = preg_replace('#/public$#', '', $dir);
        return ($https ? 'https://' : 'http://') . $host . $dir;
    }

    public static function url(string $path): string
    {
        return self::origin() . ($path === '/' ? '/' : $path);
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * The shared <head> content. $path is a key of PAGES, or null for a private view (game rooms, join/create screens),
     * which is rendered noindex and canonicalised to the home page.
     * @param array<int,array> $jsonLd extra JSON-LD nodes
     */
    public static function head(?string $path, array $jsonLd = []): string
    {
        $public = $path !== null && isset(self::PAGES[$path]);
        $page = self::PAGES[$public ? $path : '/'];
        $canon = self::url($public ? $path : '/');
        $img = self::origin() . '/img/og.png';
        $title = $public ? $page['title'] : self::SITE_NAME;
        $e = [self::class, 'e'];
        $h = '<meta charset="utf-8">' . "\n"
            . '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
            . '<meta name="color-scheme" content="dark light">' . "\n"
            . '<meta name="theme-color" content="#1b1633">' . "\n"
            . '<title>' . $e($title) . "</title>\n"
            . '<link rel="icon" href="data:image/svg+xml,<svg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 100 100\'><text y=\'.9em\' font-size=\'90\'>🐺</text></svg>">' . "\n";
        if (!$public) {
            return $h . '<meta name="robots" content="noindex,nofollow">' . "\n" . '<link rel="canonical" href="' . $e($canon) . '">' . "\n";
        }
        $h .= '<meta name="description" content="' . $e($page['description']) . '">' . "\n"
            . '<meta name="robots" content="index,follow,max-image-preview:large,max-snippet:-1">' . "\n"
            . '<link rel="canonical" href="' . $e($canon) . '">' . "\n"
            . '<meta property="og:type" content="website">' . "\n"
            . '<meta property="og:site_name" content="' . $e(self::SITE_NAME) . '">' . "\n"
            . '<meta property="og:locale" content="en_US">' . "\n"
            . '<meta property="og:title" content="' . $e($title) . '">' . "\n"
            . '<meta property="og:description" content="' . $e($page['description']) . '">' . "\n"
            . '<meta property="og:url" content="' . $e($canon) . '">' . "\n"
            . '<meta property="og:image" content="' . $e($img) . '">' . "\n"
            . '<meta property="og:image:width" content="1200">' . "\n"
            . '<meta property="og:image:height" content="630">' . "\n"
            . '<meta property="og:image:alt" content="Werewolf, Mafia &amp; Impostor: free online party games">' . "\n"
            . '<meta name="twitter:card" content="summary_large_image">' . "\n"
            . '<meta name="twitter:title" content="' . $e($title) . '">' . "\n"
            . '<meta name="twitter:description" content="' . $e($page['description']) . '">' . "\n"
            . '<meta name="twitter:image" content="' . $e($img) . '">' . "\n";
        $nodes = [[
            '@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => self::SITE_NAME,
            'url' => self::url('/'), 'inLanguage' => 'en',
        ], [
            '@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => $title, 'url' => $canon,
            'description' => $page['description'], 'dateModified' => $page['updated'], 'inLanguage' => 'en',
            'isPartOf' => ['@type' => 'WebSite', 'url' => self::url('/')],
        ], ...$jsonLd];
        foreach ($nodes as $n) {
            $h .= '<script type="application/ld+json">'
                . str_replace('</', '<\/', json_encode($n, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
                . "</script>\n";
        }
        return $h;
    }

    public static function robots(): string
    {
        return "User-agent: *\nAllow: /\nDisallow: /api.php\n\nSitemap: " . self::url('/sitemap.xml') . "\n";
    }

    public static function sitemap(): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach (self::PAGES as $path => $p) {
            $x .= '  <url><loc>' . self::e(self::url($path)) . '</loc><lastmod>' . $p['updated'] . '</lastmod></url>' . "\n";
        }
        return $x . "</urlset>\n";
    }
}
