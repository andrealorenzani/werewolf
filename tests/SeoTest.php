<?php
declare(strict_types=1);

use Wm\Seo;

T::test('seo: every public page has title 30-60, description 70-160 chars and an updated date', function () {
    foreach (Seo::PAGES as $path => $p) {
        T::true(strlen($p['title']) >= 30 && strlen($p['title']) <= 60, "$path title length");
        T::true(strlen($p['description']) >= 70 && strlen($p['description']) <= 160, "$path description length");
        T::true((bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $p['updated']), "$path updated");
    }
});

T::test('seo: public head is indexable with canonical, og and valid JSON-LD; private head is noindex', function () {
    $_SERVER['HTTP_HOST'] = 'example.test';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $h = Seo::head('/how-to-play');
    T::true(str_contains($h, '<link rel="canonical" href="http://example.test/how-to-play">'), 'canonical');
    T::true(str_contains($h, 'og:image'), 'og');
    T::true(!str_contains($h, 'noindex'), 'indexable');
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $h, $m);
    T::true(count($m[1]) >= 2, 'json-ld present');
    foreach ($m[1] as $j) {
        T::true(is_array(json_decode($j, true)), 'json-ld parses');
    }
    $p = Seo::head(null);
    T::true(str_contains($p, 'noindex,nofollow'), 'private noindex');
    T::true(!str_contains($p, 'og:title'), 'private has no og');
});

T::test('seo: sitemap lists every page, robots points to the sitemap', function () {
    $_SERVER['HTTP_HOST'] = 'example.test';
    $_SERVER['SCRIPT_NAME'] = '/sitemap.php';
    $s = Seo::sitemap();
    foreach (array_keys(Seo::PAGES) as $path) {
        T::true(str_contains($s, '<loc>' . Seo::url($path) . '</loc>'), "sitemap $path");
    }
    T::true(str_starts_with($s, '<?xml') && str_ends_with(trim($s), '</urlset>'), 'sitemap is XML');
    T::true(str_contains(Seo::robots(), 'Sitemap: http://example.test/sitemap.xml'), 'robots sitemap');
});

T::test('seo: origin ignores the internal /public directory', function () {
    $_SERVER['HTTP_HOST'] = 'example.test';
    $_SERVER['SCRIPT_NAME'] = '/public/sitemap.php';
    T::same('http://example.test', Seo::origin());
});
