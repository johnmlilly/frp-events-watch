<?php
/**
 * Plugin Name: FRP Events Watch
 * Description: Daily email listing events that start soon and past events still mentioned on key pages. Read-only; never edits content.
 * Version: 1.5.0
 * Requires PHP: 7.4
 * Author: John Lilly
 * Author URI: https://johnlilly.dev
 *
 * Recipient, Resend settings, preview and manual send: Tools > FRP Events Watch.
 * With a Resend API key set, mail goes through Resend's API; otherwise through wp_mail.
 */

defined('ABSPATH') || exit;

const FRP_WATCH_HOOK = 'frp_events_watch_daily';
const FRP_WATCH_SOON_HOURS = 24;
// How long after it ends a past event is still searched for by title. Links count at any age.
const FRP_WATCH_PAST_LOOKBACK_DAYS = 30;
// Pages that carry time-sensitive event info. Edit freely.
const FRP_WATCH_PAGES = ['/', '/community/', '/families/', '/lcm/', '/lcw/', '/lgw/',
                         '/thewayoftrust/', '/prayer/', '/courses/', '/gsc/'];

// Daily at 12:00 UTC. WP-Cron fires on the first site visit after that time.
add_action('init', function () {
    if (!wp_next_scheduled(FRP_WATCH_HOOK)) {
        $next = gmmktime(12, 0, 0);
        if ($next <= time()) {
            $next += DAY_IN_SECONDS;
        }
        wp_schedule_event($next, 'daily', FRP_WATCH_HOOK);
    }
});
add_action(FRP_WATCH_HOOK, function () {
    if (!frp_watch_claim_run(time())) {
        return;
    }
    $run = frp_watch_run(true);
    update_option('frp_watch_last_run', ['time' => time(), 'status' => $run['status']], false);
});
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook(FRP_WATCH_HOOK);
});

/**
 * Two visits racing can both start the daily job. Only one scheduled run per hour gets through:
 * the conditional UPDATE is atomic, so exactly one process sees a changed row.
 */
function frp_watch_claim_run($now) {
    global $wpdb;
    $wpdb->query("INSERT IGNORE INTO $wpdb->options (option_name, option_value, autoload) VALUES ('frp_watch_lock', '0', 'no')");
    $claimed = $wpdb->query($wpdb->prepare(
        "UPDATE $wpdb->options SET option_value = %s WHERE option_name = 'frp_watch_lock' AND CAST(option_value AS UNSIGNED) < %d",
        (string) $now, $now - HOUR_IN_SECONDS));
    wp_cache_delete('frp_watch_lock', 'options');
    return $claimed === 1;
}

function frp_watch_admin_url($args = []) {
    return add_query_arg(array_merge(['page' => 'frp-events-watch'], $args), admin_url('tools.php'));
}

function frp_watch_norm($s) {
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = str_replace(["\u{2019}", "\u{2018}", "\u{201C}", "\u{201D}", "\u{2013}", "\u{2014}"],
                     ["'", "'", '"', '"', '-', '-'], $s);
    return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s)));
}

/**
 * Events and dates of recurring events that end after the lookback start and start within a year.
 * The far end tells which titles and links still belong to an event to come.
 * @return array|WP_Error list of ['id', 'title', 'url', 'start', 'end'] with UTC timestamps
 */
function frp_watch_events($now) {
    if (!function_exists('tribe_events') || !function_exists('tribe_get_event')) {
        return new WP_Error('frp_watch', 'The Events Calendar is not active.');
    }
    $utc = new DateTimeZone('UTC');
    $from = (new DateTimeImmutable('@' . ($now - FRP_WATCH_PAST_LOOKBACK_DAYS * DAY_IN_SECONDS)))->setTimezone($utc);
    $to = (new DateTimeImmutable('@' . ($now + YEAR_IN_SECONDS)))->setTimezone($utc);

    $events = [];
    $posts = tribe_events()->where('ends_after', $from)->where('starts_before', $to)
                           ->where('status', 'publish')->per_page(-1)->all();
    foreach ($posts as $p) {
        $e = tribe_get_event($p);
        if (!$e || empty($e->dates->start_utc)) {
            continue;
        }
        $start = $e->dates->start_utc->getTimestamp();
        $end = empty($e->dates->end_utc) ? $start : $e->dates->end_utc->getTimestamp();
        $events[] = ['id' => $e->ID, 'title' => html_entity_decode(get_the_title($e), ENT_QUOTES, 'UTF-8'),
                     'url' => get_permalink($e), 'start' => $start, 'end' => $end];
    }
    return $events;
}

/** @return array [[post ID => path], errors] for FRP_WATCH_PAGES */
function frp_watch_pages() {
    $pages = [];
    $errors = [];
    foreach (FRP_WATCH_PAGES as $path) {
        if ($path === '/') {
            $id = get_option('show_on_front') === 'page' ? (int) get_option('page_on_front') : 0;
            // Enfold can set the front page in its own theme options instead.
            if (!$id && function_exists('avia_get_option')) {
                $id = (int) avia_get_option('frontpage');
            }
        } else {
            $id = url_to_postid(home_url($path));
        }
        if (!$id || !get_post($id)) {
            $errors[] = "$path: not a page or post";
            continue;
        }
        $pages[$id] = $path;
    }
    return [$pages, $errors];
}

/**
 * Everywhere an event can be mentioned: the tracked pages, the nav menus, and the active widgets
 * of each sidebar (menus and widgets show on the tracked pages too). Pages are read from the
 * database, not rendered, so other meta (SEO descriptions, caches) and content built at render
 * time (post sliders, event lists) are not scanned.
 * @param array $pages post ID => path, from frp_watch_pages()
 * @return Generator of ['label', 'url', 'sources' => [source name => text]]
 */
function frp_watch_places($pages) {
    foreach ($pages as $id => $path) {
        $sources = ['content' => get_post($id)->post_content];
        // Avia (Enfold) keeps its layout in meta but leaves it behind when a page is switched back
        // to the default editor, so only read it while the Avia builder is active on the page.
        if (get_post_meta($id, '_aviaLayoutBuilder_active', true) === 'active') {
            $v = get_post_meta($id, '_aviaLayoutBuilderCleanData', true);
            if (is_string($v) && $v !== '') {
                $sources['Avia builder data'] = $v;
            }
        }
        yield ['label' => $path, 'url' => home_url($path), 'sources' => $sources];
    }

    foreach (wp_get_nav_menus() as $menu) {
        $text = '';
        foreach (wp_get_nav_menu_items($menu) ?: [] as $item) {
            $text .= '<a href="' . $item->url . '">' . $item->title . "</a>\n";
        }
        yield ['label' => "Menu: $menu->name", 'sources' => ['menu' => $text],
               'url' => admin_url('nav-menus.php?action=edit&menu=' . $menu->term_id)];
    }

    // Widget instances live in widget_<type> options, keyed by the number in their id (text-3 → 3).
    global $wp_registered_sidebars;
    foreach (wp_get_sidebars_widgets() as $sidebar => $ids) {
        if ($sidebar === 'wp_inactive_widgets' || !is_array($ids)) {
            continue;
        }
        $text = '';
        foreach ($ids as $wid) {
            if (!preg_match('/^(.+)-(\d+)$/', $wid, $m)) {
                continue;
            }
            $instance = get_option('widget_' . $m[1])[$m[2]] ?? null;
            if (is_array($instance)) {
                array_walk_recursive($instance, function ($v) use (&$text) {
                    if (is_string($v)) {
                        $text .= $v . "\n";
                    }
                });
            }
        }
        if ($text !== '') {
            $name = $wp_registered_sidebars[$sidebar]['name'] ?? $sidebar;
            yield ['label' => "Widgets: $name", 'url' => admin_url('widgets.php'),
                   'sources' => ['widgets' => $text]];
        }
    }
}

/** The events' single-event URL base, "event" unless changed in The Events Calendar's settings. */
function frp_watch_event_base() {
    $base = '';
    if (class_exists('Tribe__Events__Main') && method_exists('Tribe__Events__Main', 'instance')) {
        $base = Tribe__Events__Main::instance()->getRewriteSlugSingular();
    } elseif (function_exists('tribe_get_option')) {
        $base = tribe_get_option('singleEventSlug', 'event');
    }
    return trim((string) $base, '/') ?: 'event';
}

/**
 * Every link to an event in $text, whatever the event: links are resolved to events afterwards, so
 * a link to an event that ended long ago, was unpublished or was deleted is found too. Three forms:
 * - a URL on this site under the event base, with or without the host (/event/retreat/, and
 *   /event/retreat/2026-10-20/ for one date of a recurring event); /other/event/retreat and
 *   /event/retreat-2026 are not /event/retreat;
 * - plain permalinks (?tribe_events=retreat);
 * - Avia's internal links, which store the post type and ID instead of a URL (tribe_events,123).
 * @return array list of [key, offset]; the key is "slug" or "slug/date", or "#id" for an ID whose
 *               event no longer exists
 */
function frp_watch_refs($text) {
    static $re, $ids = [];
    if (!$re) {
        $host = preg_quote(preg_replace('/^www\./i', '', (string) wp_parse_url(home_url(), PHP_URL_HOST)), '#');
        $home = preg_quote(untrailingslashit((string) wp_parse_url(home_url('/'), PHP_URL_PATH)), '#');
        $base = preg_quote(frp_watch_event_base(), '#');
        $re = ["#(?:(?:https?:)?//(?:www\.)?$host|(?<![\w.~%/-]))$home/$base/([\w%.~-]+)(?:/(\d{4}-\d{2}-\d{2}))?(?![\w%.~-])#i",
               '#[?&](?:amp;)?tribe_events=([\w%.~-]+)()#i'];
    }
    $refs = [];
    foreach ($re as $r) {
        if (preg_match_all($r, $text, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $slug = strtolower($hit[1][0]);
                $date = $hit[2][0] ?? '';
                $refs[] = [$date !== '' ? "$slug/$date" : $slug, $hit[0][1]];
            }
        }
    }
    if (preg_match_all('#\btribe_events,(\d+)\b#', $text, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            $id = (int) $hit[1][0];
            if (!isset($ids[$id])) {
                // Drafts have no pretty URL, so fall back to the slug, which links by URL also use.
                $p = get_post($id);
                $url = $p ? get_permalink($p) : '';
                $slug = $p ? strtolower(preg_replace('/__trashed$/', '', $p->post_name)) : '';
                $ids[$id] = ($url ? frp_watch_refs($url)[0][0] ?? null : null) ?? ($slug !== '' ? $slug : "#$id");
            }
            $refs[] = [$ids[$id], $hit[0][1]];
        }
    }
    return $refs;
}

/** The key frp_watch_refs() gives a link to this event. */
function frp_watch_event_key($event) {
    return frp_watch_refs($event['url'])[0][0] ?? "#{$event['id']}";
}

/**
 * Where each event is mentioned on the tracked pages, menus and widgets. Links to any event are collected (see
 * frp_watch_refs); titles only for the events in $titles.
 * @param array $pages post ID => path, from frp_watch_pages()
 * @param array $titles event key => title regex
 * @return array event key => [place label => ['url', 'how' => where on the page]]
 */
function frp_watch_find($titles, $pages) {
    $hits = [];
    foreach (frp_watch_places($pages) as $place) {
        $found = [];
        foreach ($place['sources'] as $text) {
            // Block attributes can store URLs with escaped slashes.
            $text = str_replace('\/', '/', $text);
            $here = [];
            foreach (frp_watch_refs($text) as [$key, $at]) {
                $here[$key][] = frp_watch_element($text, $at, 'link');
            }
            // Titles are searched with HTML tags turned into spaces, so "<strong>Retreat</strong> 2026"
            // reads "retreat 2026". Shortcodes survive, so the element around a title can still be named.
            $norm = $titles ? frp_watch_norm(preg_replace('/<[^>]*>/', ' ', $text)) : '';
            foreach ($titles as $key => $re) {
                if (preg_match_all($re, $norm, $m, PREG_OFFSET_CAPTURE)) {
                    foreach ($m[0] as [, $at]) {
                        $here[$key][] = frp_watch_element($norm, $at, 'title');
                    }
                }
            }
            foreach ($here as $key => $how) {
                $found[$key] = array_merge($found[$key] ?? [], $how);
            }
        }
        foreach ($found as $key => $how) {
            $hits[$key][$place['label']] = ['url' => $place['url'], 'how' => array_values(array_unique($how))];
        }
    }
    return $hits;
}

/**
 * A title must stand alone: "Retreat" doesn't match "Retreats". Empty titles never match.
 * @return string|null regex for normalized text
 */
function frp_watch_title_re($title) {
    $title = frp_watch_norm($title);
    return $title !== '' ? '/(?<![\p{L}\p{N}])' . preg_quote($title, '/') . '(?![\p{L}\p{N}])/u' : null;
}

/**
 * Checks a linked event that isn't among the fetched events, which hold every event or date ending
 * after the lookback start and starting within a year. So a published one is either long past or
 * more than a year away.
 * @param array $fetched event key => event
 * @return array|null ['title', 'url', 'when', 'sort'] when the link is stale: the event ended, isn't
 *                    published, or no longer exists; null when it still leads somewhere current
 */
function frp_watch_resolve($key, $fetched, $now) {
    [$slug, $date] = array_pad(explode('/', $key, 2), 2, '');
    $statuses = ['publish', 'future', 'draft', 'pending', 'private', 'trash'];
    if ($slug[0] === '#') {
        $p = get_post((int) substr($slug, 1));
        $p = $p && $p->post_type === 'tribe_events' && in_array($p->post_status, $statuses, true) ? $p : null;
        $url = '';
    } else {
        $p = get_posts(['post_type' => 'tribe_events', 'post_status' => $statuses, 'numberposts' => 1,
                        'post_name__in' => [$slug, "{$slug}__trashed"], 'suppress_filters' => true])[0] ?? null;
        $url = home_url(frp_watch_event_base() . "/$key/");
    }
    if (!$p) {
        return ['title' => $slug[0] === '#' ? "Event $slug" : $slug, 'url' => $url, 'when' => 'event no longer exists', 'sort' => 0];
    }
    $title = html_entity_decode(get_the_title($p), ENT_QUOTES, 'UTF-8');
    if ($p->post_status === 'future') {
        return null;
    }
    if ($p->post_status !== 'publish') {
        return ['title' => $title, 'url' => $url, 'when' => "event is not published ($p->post_status)", 'sort' => 0];
    }
    if ($date !== '') {
        // A date not fetched is outside the window; one before today has ended.
        $day = (new DateTimeImmutable($date, wp_timezone()))->getTimestamp();
        return $day + DAY_IN_SECONDS < $now ? ['title' => $title, 'url' => $url, 'sort' => $day,
                                               'when' => 'was on ' . wp_date('D M j, Y', $day)] : null;
    }
    // The plain link to a recurring event still leads somewhere while any of its dates is to come.
    foreach ($fetched as $k => $e) {
        if (strpos($k, "$slug/") === 0 && $e['end'] >= $now) {
            return null;
        }
    }
    $e = tribe_get_event($p);
    if (!$e || empty($e->dates->start_utc)) {
        return null;
    }
    $end = (empty($e->dates->end_utc) ? $e->dates->start_utc : $e->dates->end_utc)->getTimestamp();
    return $end < $now ? ['title' => $title, 'url' => $url, 'when' => 'ended ' . wp_date('D M j, Y', $end), 'sort' => $end] : null;
}

/**
 * Names the page element around $at: the innermost Avia element (its shortcode, e.g. [av_image ...]),
 * or a plain "Link" / "Text" outside Avia.
 */
function frp_watch_element($text, $at, $kind) {
    $names = ['av_image' => 'Image', 'av_button' => 'Button', 'av_button_big' => 'Big button (CTA)',
              'av_buttonrow' => 'Button row', 'av_promobox' => 'Promo box (CTA)', 'av_icon_box' => 'Icon box',
              'av_textblock' => 'Text block', 'av_heading' => 'Heading', 'av_slideshow' => 'Slideshow',
              'av_slideshow_full' => 'Slideshow', 'av_masonry_entries' => 'Masonry', 'av_tab' => 'Tab',
              'av_toggle' => 'Toggle', 'av_image_hotspot' => 'Image hotspot', 'av_hotspot' => 'Image hotspot',
              'av_notification' => 'Notification box'];
    $before = substr($text, 0, $at);
    $open = strrpos($before, '[');
    if ($open !== false && strpos($before, ']', $open) === false
        && preg_match('/^\[(av_[a-z0-9_]+)/i', substr($before, $open), $m)) {
        // Inside the shortcode itself, e.g. its link attribute.
        $tag = strtolower($m[1]);
    } else {
        // Inside the innermost element still open here. Elements without a closing tag (buttons,
        // images) can't contain anything, so they never count.
        $stack = [];
        preg_match_all('#\[(/?)(av_[a-z0-9_]+)#i', $before, $m, PREG_SET_ORDER);
        foreach ($m as [, $close, $name]) {
            $name = strtolower($name);
            if ($close) {
                $i = array_search($name, array_reverse($stack, true), true);
                $stack = $i === false ? $stack : array_slice($stack, 0, $i);
            } elseif (stripos($text, "[/$name]") !== false) {
                $stack[] = $name;
            }
        }
        $tag = $stack ? end($stack) : '';
    }
    $element = $tag ? $names[$tag] ?? ucfirst(str_replace('_', ' ', substr($tag, 3))) : '';
    if ($kind === 'link') {
        return $element ? "$element link" : 'Link';
    }
    return ($element ?: 'Text') . ' (title)';
}

/**
 * Start of this run's "soon" window: where the last delivered run's window ended, so a late
 * WP-Cron run neither skips events that started in the gap nor reports any twice.
 */
function frp_watch_soon_from($now) {
    $from = (int) get_option('frp_watch_soon_until');
    return $from ? max($from, $now - FRP_WATCH_PAST_LOOKBACK_DAYS * DAY_IN_SECONDS) : $now - 1;
}

/**
 * Soon = starts after $soon_from and within FRP_WATCH_SOON_HOURS of now, and hasn't ended yet.
 * @return array ['subject', 'summary', 'sections', 'quiet', 'complete']. quiet is true when there is
 *               nothing to report; complete is false when events could not be read.
 *                    Each section: ['key' => soon|stale|errors, 'title', 'items']; event items are
 *                    ['title', 'url', 'when', 'in_progress', 'pages' => [place label => ['url', 'how']]], error items
 *                    are strings.
 */
function frp_watch_report($now, $soon_from) {
    $events = frp_watch_events($now);
    if (is_wp_error($events)) {
        return ['subject' => 'FRP events watch: error', 'summary' => 'The check could not run.', 'quiet' => false,
                'sections' => [['key' => 'errors', 'title' => 'Error', 'items' => [$events->get_error_message()]]],
                'complete' => false];
    }
    $soon_until = $now + FRP_WATCH_SOON_HOURS * HOUR_IN_SECONDS;
    $fetched = [];
    $live = [];
    foreach ($events as $e) {
        $fetched[frp_watch_event_key($e)] = $e;
        if ($e['end'] >= $now) {
            $live[frp_watch_norm($e['title'])] = true;
        }
    }
    $soon = array_filter($fetched, function ($e) use ($now, $soon_from, $soon_until) {
        return $e['start'] > $soon_from && $e['start'] <= $soon_until && $e['end'] >= $now;
    });
    uasort($soon, function ($a, $b) { return $a['start'] <=> $b['start']; });

    // Titles are searched for events starting soon and events that ended within the lookback. A past
    // event's title also names the next date of a recurring event, so it is searched only when no
    // event that hasn't ended shares it. Links are found for every event, however old.
    $titles = [];
    foreach ($fetched as $key => $e) {
        $past = $e['end'] < $now && !isset($live[frp_watch_norm($e['title'])]);
        if ((isset($soon[$key]) || $past) && ($re = frp_watch_title_re($e['title']))) {
            $titles[$key] = $re;
        }
    }
    [$pages, $errors] = frp_watch_pages();
    $hits = frp_watch_find($titles, $pages);

    $sections = [];
    $items = [];
    foreach ($soon as $key => $e) {
        $started = $e['start'] < $now;
        $items[] = ['title' => $e['title'], 'url' => $e['url'], 'in_progress' => $started,
                    'when' => ($started ? 'started ' : '') . wp_date('D M j g:ia T', $e['start']),
                    'pages' => $hits[$key] ?? []];
    }
    if ($items) {
        $sections[] = ['key' => 'soon', 'title' => 'Starting within ' . FRP_WATCH_SOON_HOURS . ' hours', 'items' => $items];
    }
    $n_soon = count($items);

    $items = [];
    foreach ($hits as $key => $pages) {
        if (isset($fetched[$key])) {
            $e = $fetched[$key];
            $item = $e['end'] < $now ? ['title' => $e['title'], 'url' => $e['url'], 'sort' => $e['end'],
                                        'when' => 'ended ' . wp_date('D M j', $e['end'])] : null;
        } else {
            $item = frp_watch_resolve($key, $fetched, $now);
        }
        if ($item) {
            $items[] = $item + ['in_progress' => false, 'pages' => $pages];
        }
    }
    usort($items, function ($a, $b) { return $a['sort'] <=> $b['sort']; });
    if ($items) {
        $sections[] = ['key' => 'stale', 'title' => 'Past but still on site', 'items' => $items];
    }
    $n_stale = count($items);

    $quiet = !$n_soon && !$n_stale;
    if ($errors) {
        $sections[] = ['key' => 'errors', 'title' => 'Pages that could not be checked', 'items' => $errors];
    }
    if ($quiet) {
        return ['subject' => 'FRP events: nothing to report', 'quiet' => true, 'complete' => true,
                'summary' => 'Nothing to report. No events start in the next ' . FRP_WATCH_SOON_HOURS
                           . ' hours, and no past events are on the tracked pages.',
                'sections' => $sections];
    }
    return ['subject' => "FRP events: $n_soon soon, $n_stale past still live", 'quiet' => false,
            'summary' => "$n_soon starting soon · $n_stale past " . ($n_stale === 1 ? 'event' : 'events') . ' still on tracked pages',
            'sections' => $sections, 'complete' => true];
}

function frp_watch_render_text($report) {
    $lines = $report['quiet'] ? [$report['summary'], ''] : [];
    foreach ($report['sections'] as $s) {
        $lines[] = strtoupper($s['title']);
        foreach ($s['items'] as $item) {
            if ($s['key'] === 'errors') {
                $lines[] = "- $item";
                continue;
            }
            $lines[] = "- {$item['title']} ({$item['when']})";
            $lines[] = "  {$item['url']}";
            if (!$item['pages']) {
                $lines[] = '  On: not found on tracked pages';
            }
            foreach ($item['pages'] as $path => $page) {
                $lines[] = "  On: $path (" . implode(', ', $page['how']) . ')';
            }
        }
        $lines[] = '';
    }
    return rtrim(implode("\n", $lines));
}

/** Email-safe HTML: tables and inline styles only. */
function frp_watch_render_html($report, $footer_url) {
    $accent = ['soon' => '#2563eb', 'stale' => '#d97706', 'errors' => '#6b7280'];
    $font = "-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
    $h = function ($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };

    $body = '';
    foreach ($report['sections'] as $s) {
        $c = $accent[$s['key']];
        $body .= '<tr><td style="padding:12px 24px 4px;">'
               . "<div style=\"font-size:12px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:$c;margin-bottom:8px;\">"
               . $h($s['title']) . '</div>';
        foreach ($s['items'] as $item) {
            if ($s['key'] === 'errors') {
                $body .= '<div style="font-size:13px;color:#6b7280;margin-bottom:4px;">' . $h($item) . '</div>';
                continue;
            }
            $tag = $item['in_progress']
                ? ' <span style="display:inline-block;font-size:11px;font-weight:600;color:#166534;background:#dcfce7;border-radius:10px;padding:1px 8px;vertical-align:middle;">In progress</span>'
                : '';
            // One line per page: the page as a link, then where on it the event appears.
            $chips = '';
            foreach ($item['pages'] as $path => $page) {
                $chips .= '<div style="margin-top:4px;">'
                        . '<a href="' . $h($page['url']) . '" style="display:inline-block;font-size:12px;color:#1f2a44;background:#eef2ff;border-radius:10px;padding:2px 9px;margin-right:6px;text-decoration:none;">'
                        . $h($path) . '</a>'
                        . '<span style="font-size:12px;color:#6b7280;">' . $h(implode(' · ', $page['how'])) . '</span></div>';
            }
            if (!$chips) {
                $chips = ' <span style="color:#9ca3af;">Not on any tracked page</span>';
            }
            $body .= "<div style=\"border-left:4px solid $c;background:#f9fafb;border-radius:0 6px 6px 0;padding:10px 14px;margin-bottom:10px;\">"
                   . '<a href="' . $h($item['url']) . '" style="font-size:15px;font-weight:600;color:#111827;text-decoration:none;">' . $h($item['title']) . '</a>' . $tag
                   . '<div style="font-size:13px;color:#374151;margin-top:3px;font-weight:600;">' . $h(ucfirst($item['when'])) . '</div>'
                   . '<div style="font-size:13px;color:#374151;margin-top:6px;">On: ' . $chips . '</div>'
                   . '</div>';
        }
        $body .= '</td></tr>';
    }

    $footer = 'Sent by FRP Events Watch' . ($footer_url ? ' · <a href="' . $h($footer_url) . '" style="color:#6b7280;">Settings</a>' : '');
    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $h($report['subject']) . '</title></head>'
         . '<body style="margin:0;padding:0;background:#f4f5f7;">'
         . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;"><tr><td align="center" style="padding:24px 12px;">'
         . "<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"max-width:600px;background:#ffffff;border-radius:8px;font-family:$font;color:#1f2937;\">"
         . '<tr><td style="background:#1f2a44;color:#ffffff;padding:20px 24px;border-radius:8px 8px 0 0;">'
         . '<div style="font-size:18px;font-weight:600;">FRP Events Watch</div>'
         . '<div style="font-size:13px;color:#c7cdd9;margin-top:2px;">' . $h($report['date']) . '</div></td></tr>'
         . '<tr><td style="padding:20px 24px 4px;font-size:15px;">' . $h($report['summary']) . '</td></tr>'
         . $body
         . '<tr><td style="padding:16px 24px 20px;font-size:12px;color:#6b7280;border-top:1px solid #e5e7eb;">' . $footer . '</td></tr>'
         . '</table></td></tr></table></body></html>';
}

function frp_watch_recipient() {
    return get_option('frp_watch_mail_to') ?: get_option('admin_email');
}

/**
 * Sends $text, plus $html as the main part when given.
 * @return true|string true on success, otherwise an error message
 */
function frp_watch_mail($to, $subject, $text, $html = null) {
    $key = get_option('frp_watch_resend_key');
    if (!$key) {
        if (!$html) {
            return wp_mail($to, $subject, $text) ?: 'wp_mail failed';
        }
        $alt = function ($mailer) use ($text) {
            $mailer->AltBody = $text;
        };
        add_action('phpmailer_init', $alt);
        $ok = wp_mail($to, $subject, $html, ['Content-Type: text/html; charset=UTF-8']);
        remove_action('phpmailer_init', $alt);
        return $ok ?: 'wp_mail failed';
    }
    $payload = ['from' => get_option('frp_watch_mail_from') ?: 'onboarding@resend.dev',
                'to' => [$to], 'subject' => $subject, 'text' => $text];
    if ($html) {
        $payload['html'] = $html;
    }
    $r = wp_remote_post('https://api.resend.com/emails', [
        'timeout' => 30,
        'headers' => ['Authorization' => "Bearer $key", 'Content-Type' => 'application/json'],
        'body' => wp_json_encode($payload),
    ]);
    if (is_wp_error($r)) {
        return 'Resend request failed: ' . $r->get_error_message();
    }
    $code = wp_remote_retrieve_response_code($r);
    return $code >= 200 && $code < 300 ?: "Resend returned $code: " . wp_remote_retrieve_body($r);
}

function frp_watch_quiet_email() {
    return get_option('frp_watch_quiet_email', '1') === '1';
}

/**
 * Previews never move the soon window. A send moves it forward only once the email is
 * delivered (or skipped on a quiet day), so a failed run is retried next time.
 * @return array ['status', 'text', 'html'] for the admin page
 */
function frp_watch_run($send) {
    $now = time();
    $report = frp_watch_report($now, frp_watch_soon_from($now));
    $report['date'] = wp_date('l, F j, Y', $now);
    $text = frp_watch_render_text($report);
    $html = frp_watch_render_html($report, frp_watch_admin_url());
    $skip = $report['quiet'] && !frp_watch_quiet_email();

    $done = true;
    if (!$send) {
        $status = 'Preview only, not sent.' . ($skip ? ' Quiet-day emails are off, so nothing would be sent today.' : '');
    } elseif ($skip) {
        $status = 'Nothing to report; quiet-day emails are off, so nothing was sent.';
    } else {
        $to = frp_watch_recipient();
        $ok = frp_watch_mail($to, $report['subject'], $text, $html);
        $done = $ok === true && $report['complete'];
        $status = $ok === true ? "Sent to $to." : "Failed sending to $to: $ok";
    }
    if ($send && $done) {
        update_option('frp_watch_soon_until', $now + FRP_WATCH_SOON_HOURS * HOUR_IN_SECONDS, false);
    }
    return ['status' => $status, 'text' => $report['subject'] . "\n\n" . $text, 'html' => $html];
}

// The form posts here and redirects back, so refreshing the Tools page never repeats a send.
add_action('admin_post_frp_watch', function () {
    if (!current_user_can('manage_options')) {
        wp_die('Sorry, you are not allowed to do that.', 403);
    }
    check_admin_referer('frp_watch');
    $notice = '';
    if (isset($_POST['frp_watch_save'])) {
        $to = sanitize_email(wp_unslash($_POST['frp_watch_mail_to'] ?? ''));
        $from = sanitize_text_field(wp_unslash($_POST['frp_watch_mail_from'] ?? ''));
        $key = sanitize_text_field(wp_unslash($_POST['frp_watch_resend_key'] ?? ''));
        update_option('frp_watch_mail_to', $to, false);
        update_option('frp_watch_mail_from', $from, false);
        update_option('frp_watch_quiet_email', empty($_POST['frp_watch_quiet_email']) ? '0' : '1', false);
        // Blank keeps the saved key; the remove box clears it.
        if (!empty($_POST['frp_watch_resend_clear'])) {
            delete_option('frp_watch_resend_key');
        } elseif ($key) {
            update_option('frp_watch_resend_key', $key, false);
        }
        $notice = 'Saved. Reports will go to ' . frp_watch_recipient() . '.';
    } elseif (isset($_POST['frp_watch_test'])) {
        $to = frp_watch_recipient();
        $ok = frp_watch_mail($to, 'FRP events watch: test', 'Test email from the FRP Events Watch plugin. Delivery works.');
        $notice = $ok === true ? "Test email sent to $to. Check spam if it doesn't arrive." : "Failed sending to $to: $ok";
    } elseif (isset($_POST['frp_watch_send'])) {
        $notice = 'Report: ' . frp_watch_run(true)['status'];
    }
    set_transient('frp_watch_notice_' . get_current_user_id(), $notice, MINUTE_IN_SECONDS);
    wp_safe_redirect(frp_watch_admin_url());
    exit;
});

add_action('admin_menu', function () {
    add_management_page('FRP Events Watch', 'FRP Events Watch', 'manage_options', 'frp-events-watch', function () {
        $notice = get_transient('frp_watch_notice_' . get_current_user_id());
        delete_transient('frp_watch_notice_' . get_current_user_id());
        // The preview scans the whole site, so it runs only when asked for.
        $preview = isset($_GET['preview']);
        $next = wp_next_scheduled(FRP_WATCH_HOOK);
        $saved_key = get_option('frp_watch_resend_key');
        $last = get_option('frp_watch_last_run');
        ?>
        <div class="wrap">
            <h1>FRP Events Watch</h1>
            <?php if ($notice) : ?>
                <div class="notice notice-info"><p><?php echo esc_html($notice); ?></p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="frp_watch">
                <?php wp_nonce_field('frp_watch'); ?>
                <p>
                    <label for="frp_watch_mail_to">Send reports to</label><br>
                    <input type="email" class="regular-text" id="frp_watch_mail_to" name="frp_watch_mail_to"
                           value="<?php echo esc_attr(get_option('frp_watch_mail_to', '')); ?>"
                           placeholder="<?php echo esc_attr(get_option('admin_email')); ?>">
                </p>
                <p>
                    <label for="frp_watch_resend_key">Resend API key (optional; without it, the site's normal mail is used)</label><br>
                    <input type="password" class="regular-text" id="frp_watch_resend_key" name="frp_watch_resend_key"
                           autocomplete="off" placeholder="<?php echo $saved_key ? esc_attr('Saved, ends in ' . substr($saved_key, -4)) : 're_...'; ?>">
                    <?php if ($saved_key) : ?>
                        <label><input type="checkbox" name="frp_watch_resend_clear" value="1"> Remove</label>
                    <?php endif; ?>
                </p>
                <p>
                    <label for="frp_watch_mail_from">From address for Resend (must be on a domain verified in Resend)</label><br>
                    <input type="text" class="regular-text" id="frp_watch_mail_from" name="frp_watch_mail_from"
                           value="<?php echo esc_attr(get_option('frp_watch_mail_from', '')); ?>"
                           placeholder="onboarding@resend.dev">
                </p>
                <p>
                    <label><input type="checkbox" name="frp_watch_quiet_email" value="1" <?php checked(frp_watch_quiet_email()); ?>>
                        Email me on days with nothing to report</label>
                </p>
                <p><?php submit_button('Save', 'primary', 'frp_watch_save', false); ?></p>
                <p>
                    Next scheduled run: <?php echo $next ? esc_html(wp_date('D M j g:ia T', $next)) : 'not scheduled'; ?><br>
                    Last scheduled run: <?php echo $last ? esc_html(wp_date('D M j g:ia T', $last['time']) . ' — ' . $last['status']) : 'never'; ?>
                </p>
                <?php if ($next && $next < time() - HOUR_IN_SECONDS) : ?>
                    <div class="notice notice-warning inline"><p>The scheduled run is overdue, so WP-Cron doesn't seem to be firing. Check Tools → Site Health for scheduled event or loopback warnings.</p></div>
                <?php endif; ?>
                <p>
                    <?php submit_button('Send test email', 'secondary', 'frp_watch_test', false); ?>
                    <?php submit_button('Send report now', 'secondary', 'frp_watch_send', false); ?>
                    <a class="button" href="<?php echo esc_url(frp_watch_admin_url(['preview' => 1])); ?>#frp-watch-preview">Preview report</a>
                </p>
            </form>
            <?php if ($preview) : ?>
                <?php $run = frp_watch_run(false); ?>
                <h2 id="frp-watch-preview">Preview</h2>
                <p><?php echo esc_html($run['status']); ?></p>
                <iframe sandbox="" srcdoc="<?php echo esc_attr($run['html']); ?>"
                        style="width:100%;max-width:680px;height:640px;border:1px solid #dcdcde;background:#fff;"></iframe>
                <details>
                    <summary>Plain-text version</summary>
                    <pre style="white-space:pre-wrap"><?php echo esc_html($run['text']); ?></pre>
                </details>
            <?php endif; ?>
        </div>
        <?php
    });
});
