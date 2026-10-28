<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Stubs the WordPress and The Events Calendar functions the plugin calls, for a site at
 * https://www.familyrenewalproject.com in UTC.
 *
 * frp_watch_refs() caches its link pattern and Avia ID lookups for the whole run, so every test
 * uses the same home URL, and an event ID means the same event in every test (see EVENTS).
 */
abstract class FrpTestCase extends TestCase {
    const HOME = 'https://www.familyrenewalproject.com';
    const NOW = 1793102400; // 2026-10-27 12:00 UTC

    /** Event posts by ID: [slug, title, status, start, end]. */
    const EVENTS = [
        100 => ['lcw-marian-paint-by-number', 'LCW: Marian Paint by Number', 'publish', '2026-08-01 18:00', '2026-08-01 20:00'],
        101 => ['old-draft', 'Old Draft', 'draft', '2026-01-01 18:00', '2026-01-01 20:00'],
        102 => ['far-future', 'Far Future', 'publish', '2028-06-01 18:00', '2028-06-01 20:00'],
        103 => ['prayer-night', 'Prayer Night', 'publish', '2025-01-07 18:00', '2025-01-07 20:00'],
        104 => ['old-trash__trashed', 'Old Trash', 'trash', '2025-05-01 18:00', '2025-05-01 20:00'],
        105 => ['scheduled-thing', 'Scheduled Thing', 'future', '2026-12-01 18:00', '2026-12-01 20:00'],
    ];

    /** @var array events tribe_events() returns: list of ['id', 'title', 'url', 'start', 'end'] */
    protected $fetched = [];
    /** @var array post ID => WP_Post-like object for pages */
    protected $pages = [];
    /** @var array post ID => [meta key => value] */
    protected $meta = [];
    /** @var array option name => value */
    protected $options = [];

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $test = $this;

        Functions\when('home_url')->alias(function ($path = '') {
            return self::HOME . '/' . ltrim($path, '/');
        });
        Functions\when('admin_url')->alias(function ($path = '') {
            return self::HOME . '/wp-admin/' . $path;
        });
        Functions\when('wp_timezone')->alias(function () {
            return new DateTimeZone('UTC');
        });
        Functions\when('wp_date')->alias(function ($format, $ts) {
            return gmdate($format, $ts);
        });
        Functions\when('get_option')->alias(function ($name, $default = false) use ($test) {
            return $test->options[$name] ?? $default;
        });
        Functions\when('get_post_meta')->alias(function ($id, $key) use ($test) {
            return $test->meta[$id][$key] ?? '';
        });
        Functions\when('get_post')->alias(function ($id) use ($test) {
            return $test->pages[$id] ?? self::eventPost($id);
        });
        Functions\when('get_posts')->alias(function ($args) {
            foreach (array_keys(self::EVENTS) as $id) {
                $p = self::eventPost($id);
                if (in_array($p->post_name, $args['post_name__in'], true)
                    && in_array($p->post_status, $args['post_status'], true)) {
                    return [$p];
                }
            }
            return [];
        });
        Functions\when('get_permalink')->alias(function ($p) {
            $p = is_object($p) ? $p : self::eventPost($p);
            if (!$p) {
                return false;
            }
            if (isset($p->url)) {
                return $p->url;
            }
            // Like WordPress: only published posts get a pretty URL.
            return $p->post_status === 'publish' ? self::HOME . "/event/$p->post_name/"
                                                 : self::HOME . "/?post_type=tribe_events&p=$p->ID";
        });
        Functions\when('get_the_title')->alias(function ($p) {
            return $p->post_title;
        });
        Functions\when('url_to_postid')->alias(function ($url) use ($test) {
            foreach ($test->pages as $id => $p) {
                if ($url === self::HOME . '/' . $p->post_name . '/') {
                    return $id;
                }
            }
            return 0;
        });
        Functions\when('wp_get_nav_menus')->justReturn([]);
        Functions\when('wp_get_sidebars_widgets')->justReturn([]);
        Functions\when('tribe_get_event')->alias(function ($p) {
            $s = $p->start ?? strtotime(self::EVENTS[$p->ID][3] . ' UTC');
            $e = $p->end ?? strtotime(self::EVENTS[$p->ID][4] . ' UTC');
            return (object) ['ID' => $p->ID, 'post_title' => $p->post_title, 'url' => $p->url ?? null,
                             'post_content' => $p->post_content ?? '', 'thumb' => $p->thumb ?? 0,
                             'dates' => (object) ['start_utc' => new DateTimeImmutable("@$s"),
                                                  'end_utc' => new DateTimeImmutable("@$e")]];
        });
        Functions\when('tribe_events')->alias(function () use ($test) {
            return new class($test->fetched) {
                private $events;
                public function __construct($events) {
                    $this->events = $events;
                }
                public function where() {
                    return $this;
                }
                public function per_page() {
                    return $this;
                }
                public function all() {
                    return array_map(function ($e) {
                        return (object) ['ID' => $e['id'], 'post_title' => $e['title'], 'url' => $e['url'],
                                         'start' => $e['start'], 'end' => $e['end'],
                                         'post_content' => $e['content'], 'thumb' => $e['thumb']];
                    }, $this->events);
                }
            };
        });
        Functions\when('is_wp_error')->alias(function ($v) {
            return $v instanceof WP_Error;
        });
        Functions\when('get_post_thumbnail_id')->alias(function ($p) {
            return $p->thumb ?? 0;
        });
        Functions\when('wp_get_attachment_url')->alias(function ($id) {
            return $id ? self::HOME . "/wp-content/uploads/attachment-$id.png" : false;
        });
        Functions\when('wp_salt')->justReturn('test-salt');
        Functions\when('update_option')->alias(function ($name, $value) use ($test) {
            $test->options[$name] = $value;
            return true;
        });
        Functions\stubEscapeFunctions();
        Functions\when('sanitize_email')->alias(function ($s) {
            return preg_replace('/[^a-z0-9+_.@-]/i', '', $s);
        });
        Functions\when('is_email')->alias(function ($s) {
            return filter_var($s, FILTER_VALIDATE_EMAIL) ? $s : false;
        });
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    protected static function eventPost($id) {
        if (!isset(self::EVENTS[$id])) {
            return null;
        }
        [$slug, $title, $status] = self::EVENTS[$id];
        return (object) ['ID' => $id, 'post_type' => 'tribe_events', 'post_name' => $slug,
                         'post_title' => $title, 'post_status' => $status];
    }

    /** Adds a tracked page; $avia is its Avia builder data, if the builder is active on it. */
    protected function addPage($id, $slug, $content, $avia = null) {
        $this->pages[$id] = (object) ['ID' => $id, 'post_type' => 'page', 'post_name' => $slug,
                                      'post_title' => ucfirst($slug), 'post_content' => $content,
                                      'post_status' => 'publish'];
        if ($avia !== null) {
            $this->meta[$id] = ['_aviaLayoutBuilder_active' => 'active', '_aviaLayoutBuilderCleanData' => $avia];
        }
    }

    const FLYER = '<img src="https://www.familyrenewalproject.com/wp-content/uploads/2026/10/flyer.png" alt="">';

    /**
     * An event as tribe_events() returns it; times are hours from NOW. Its description has a flyer
     * unless $content says otherwise; $thumb is its featured image ID.
     */
    protected function event($id, $title, $path, $start_hours, $end_hours, $content = self::FLYER, $thumb = 0) {
        return ['id' => $id, 'title' => $title, 'url' => self::HOME . $path,
                'start' => self::NOW + (int) ($start_hours * HOUR_IN_SECONDS),
                'end' => self::NOW + (int) ($end_hours * HOUR_IN_SECONDS),
                'content' => $content, 'thumb' => $thumb];
    }
}
