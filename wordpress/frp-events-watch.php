<?php
/**
 * Plugin Name: FRP Events Watch
 * Description: Daily email listing events that start soon and past events still mentioned anywhere on the site. Read-only; never edits content.
 * Version: 1.3.0
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
const FRP_WATCH_PAST_LOOKBACK_DAYS = 14;

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
 * Events from the lookback start through a year ahead. The far end is only needed to know which
 * titles still belong to an upcoming event (see frp_watch_find).
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

/**
 * Everywhere an event can be mentioned: published posts, pages and synced patterns of every public
 * type except The Events Calendar's own, the nav menus, and the active widgets of each sidebar.
 * Posts are read from the database, not rendered, so other meta (SEO descriptions, caches) and
 * content built at render time (post sliders, event lists) are not scanned.
 * @return Generator of ['id', 'label', 'url', 'sources' => [source name => text]]
 */
function frp_watch_places() {
    $types = array_values(array_filter(array_merge(get_post_types(['public' => true]), ['wp_block']), function ($t) {
        return $t !== 'attachment' && strpos($t, 'tribe_') !== 0;
    }));
    for ($paged = 1; ; $paged++) {
        $q = new WP_Query(['post_type' => $types, 'post_status' => 'publish', 'posts_per_page' => 200,
                           'paged' => $paged, 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true,
                           'update_post_term_cache' => false, 'suppress_filters' => true]);
        foreach ($q->posts as $p) {
            $sources = ['content' => $p->post_content];
            // Avia (Enfold) keeps its layout in meta but leaves it behind when a page is switched back
            // to the default editor, so only read it while the Avia builder is active on the page.
            if (get_post_meta($p->ID, '_aviaLayoutBuilder_active', true) === 'active') {
                $v = get_post_meta($p->ID, '_aviaLayoutBuilderCleanData', true);
                if (is_string($v) && $v !== '') {
                    $sources['Avia builder data'] = $v;
                }
            }
            if ($p->post_type === 'wp_block') {
                $label = 'Synced pattern "' . $p->post_title . '"';
                $url = admin_url('post.php?post=' . $p->ID . '&action=edit');
            } else {
                $url = get_permalink($p);
                $label = wp_make_link_relative($url) ?: '/';
            }
            yield ['id' => $p->ID, 'label' => $label, 'url' => $url, 'sources' => $sources];
        }
        if (count($q->posts) < 200) {
            break;
        }
    }

    foreach (wp_get_nav_menus() as $menu) {
        $text = '';
        foreach (wp_get_nav_menu_items($menu) ?: [] as $item) {
            $text .= '<a href="' . $item->url . '">' . $item->title . "</a>\n";
        }
        yield ['id' => 0, 'label' => "Menu: $menu->name", 'sources' => ['menu' => $text],
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
            yield ['id' => 0, 'label' => "Widgets: $name", 'url' => admin_url('widgets.php'),
                   'sources' => ['widgets' => $text]];
        }
    }
}

/**
 * A link matches the event's own address, so a custom events slug or plain permalinks work. It
 * must start a URL or follow the host (/other/event/retreat doesn't match /event/retreat) and end
 * there (/event/retreat doesn't match /event/retreat-2026/). A title must stand alone: "Retreat"
 * doesn't match "Retreats". Empty titles and addresses never match.
 * @param bool $by_title false to match links only
 */
function frp_watch_matcher($event, $by_title) {
    $path = untrailingslashit(wp_make_link_relative($event['url']));
    $title = $by_title ? frp_watch_norm($event['title']) : '';
    return [
        'link' => $path !== '' ? '#(?:^|[\s"\'=(,]|//[^/\s"\'<>]*)' . preg_quote($path, '#') . '(?![\w-])#i' : null,
        'title' => $title !== '' ? '/(?<![\p{L}\p{N}])' . preg_quote($title, '/') . '(?![\p{L}\p{N}])/u' : null,
    ];
}

/**
 * Links are matched in the raw text; titles in the normalized text with HTML tags turned into
 * spaces, so "<strong>Retreat</strong> 2026" reads "retreat 2026". Shortcodes survive, so the
 * element around a title can still be named.
 * @param array $texts list of [raw, normalized] per source, from frp_watch_prepare()
 * @return string[] where on the page the event appears, e.g. ['Image link', 'Text block (title)'];
 *                  empty when it doesn't. Uses the first source that mentions it.
 */
function frp_watch_mentions($matcher, $texts) {
    foreach ($texts as [$text, $norm]) {
        $found = [];
        if ($matcher['link'] && preg_match_all($matcher['link'], $text, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [, $at]) {
                $found[] = frp_watch_element($text, $at, 'link');
            }
        }
        if ($matcher['title'] && preg_match_all($matcher['title'], $norm, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [, $at]) {
                $found[] = frp_watch_element($norm, $at, 'title');
            }
        }
        if ($found) {
            return array_values(array_unique($found));
        }
    }
    return [];
}

function frp_watch_prepare($sources) {
    return array_map(function ($text) {
        return [$text, frp_watch_norm(preg_replace('/<[^>]*>/', ' ', $text))];
    }, array_values($sources));
}

/**
 * A past event's title also names the next instance of a recurring event, so a past event
 * matches by title only when no event that hasn't ended shares it; links still count.
 * @param array $events events to look for, keyed however the caller likes
 * @param array $all every fetched event, to find titles still in use
 * @return array same keys as $events => [place label => ['url', 'how']]
 */
function frp_watch_find($events, $all, $now) {
    $hits = array_fill_keys(array_keys($events), []);
    if (!$events) {
        return $hits;
    }
    $live = [];
    foreach ($all as $e) {
        if ($e['end'] >= $now) {
            $live[frp_watch_norm($e['title'])] = true;
        }
    }
    $matchers = [];
    foreach ($events as $k => $e) {
        $matchers[$k] = frp_watch_matcher($e, $e['end'] >= $now || !isset($live[frp_watch_norm($e['title'])]));
    }
    foreach (frp_watch_places() as $place) {
        $texts = frp_watch_prepare($place['sources']);
        foreach ($events as $k => $e) {
            if ($place['id'] !== $e['id'] && ($how = frp_watch_mentions($matchers[$k], $texts))) {
                $hits[$k][$place['label']] = ['url' => $place['url'], 'how' => $how];
            }
        }
    }
    return $hits;
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
    $element = '';
    if (preg_match_all('/\[(av_[a-z0-9_]+)/i', substr($text, 0, $at), $m)) {
        $tag = strtolower(end($m[1]));
        $element = $names[$tag] ?? ucfirst(str_replace('_', ' ', substr($tag, 3)));
    }
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
    $soon = array_filter($events, function ($e) use ($now, $soon_from, $soon_until) {
        return $e['start'] > $soon_from && $e['start'] <= $soon_until && $e['end'] >= $now;
    });
    $past = array_filter($events, function ($e) use ($now) {
        return $e['end'] < $now;
    });
    usort($soon, function ($a, $b) { return $a['start'] <=> $b['start']; });
    usort($past, function ($a, $b) { return $a['end'] <=> $b['end']; });

    $find = [];
    foreach ($soon as $i => $e) {
        $find["soon$i"] = $e;
    }
    foreach ($past as $i => $e) {
        $find["past$i"] = $e;
    }
    $hits = frp_watch_find($find, $events, $now);

    $sections = [];
    $items = [];
    foreach ($soon as $i => $e) {
        $started = $e['start'] < $now;
        $items[] = ['title' => $e['title'], 'url' => $e['url'], 'in_progress' => $started,
                    'when' => ($started ? 'started ' : '') . wp_date('D M j g:ia T', $e['start']),
                    'pages' => $hits["soon$i"]];
    }
    if ($items) {
        $sections[] = ['key' => 'soon', 'title' => 'Starting within ' . FRP_WATCH_SOON_HOURS . ' hours', 'items' => $items];
    }
    $n_soon = count($items);

    $items = [];
    foreach ($past as $i => $e) {
        if ($hits["past$i"]) {
            $items[] = ['title' => $e['title'], 'url' => $e['url'], 'in_progress' => false,
                        'when' => 'ended ' . wp_date('D M j', $e['end']), 'pages' => $hits["past$i"]];
        }
    }
    if ($items) {
        $sections[] = ['key' => 'stale', 'title' => 'Past but still on site', 'items' => $items];
    }
    $n_stale = count($items);

    if (!$n_soon && !$n_stale) {
        return ['subject' => 'FRP events: nothing to report', 'quiet' => true, 'complete' => true,
                'summary' => 'Nothing to report. No events start in the next ' . FRP_WATCH_SOON_HOURS
                           . ' hours, and no past events are mentioned on the site.',
                'sections' => $sections];
    }
    return ['subject' => "FRP events: $n_soon soon, $n_stale past still live", 'quiet' => false,
            'summary' => "$n_soon starting soon · $n_stale past " . ($n_stale === 1 ? 'event' : 'events') . ' still on the site',
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
                $lines[] = '  On: not found on the site';
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
                $chips = ' <span style="color:#9ca3af;">Not found on the site</span>';
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
