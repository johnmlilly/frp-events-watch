<?php
/**
 * Plugin Name: FRP Events Watch
 * Description: Daily email listing events that start soon and past events still mentioned on key pages. Read-only; never edits content.
 * Version: 1.0.0
 * Requires PHP: 7.4
 *
 * Port of events_watch.py that runs inside WordPress, so Cloudflare is never involved.
 * Preview and manual send: Tools > FRP Events Watch.
 * Recipient: define FRP_WATCH_MAIL_TO in wp-config.php, otherwise the site admin email.
 */

defined('ABSPATH') || exit;

const FRP_WATCH_HOOK = 'frp_events_watch_daily';
const FRP_WATCH_SOON_HOURS = 24;
const FRP_WATCH_PAST_LOOKBACK_DAYS = 14;
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
    frp_watch_run(true);
});
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook(FRP_WATCH_HOOK);
});

function frp_watch_norm($s) {
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = str_replace(["\u{2019}", "\u{2018}", "\u{201C}", "\u{201D}", "\u{2013}", "\u{2014}"],
                     ["'", "'", '"', '"', '-', '-'], $s);
    return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s)));
}

/** @return array|WP_Error list of ['title', 'url', 'start', 'end'] with UTC timestamps */
function frp_watch_events($now) {
    if (!function_exists('tribe_events') || !function_exists('tribe_get_event')) {
        return new WP_Error('frp_watch', 'The Events Calendar is not active.');
    }
    $utc = new DateTimeZone('UTC');
    $from = (new DateTimeImmutable('@' . ($now - FRP_WATCH_PAST_LOOKBACK_DAYS * DAY_IN_SECONDS)))->setTimezone($utc);
    $to = (new DateTimeImmutable('@' . ($now + (FRP_WATCH_SOON_HOURS + 24) * HOUR_IN_SECONDS)))->setTimezone($utc);

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
        $events[] = ['title' => html_entity_decode(get_the_title($e), ENT_QUOTES, 'UTF-8'),
                     'url' => get_permalink($e), 'start' => $start, 'end' => $end];
    }
    return $events;
}

/** Rendered content plus raw content and meta (page builders keep their data in meta). */
function frp_watch_page_text($id) {
    global $post;
    $page = get_post($id);
    $text = $page->post_content;

    $prev = $post;
    $post = $page;
    setup_postdata($page);
    $text .= ' ' . apply_filters('the_content', $page->post_content);
    $post = $prev;
    wp_reset_postdata();

    foreach (get_post_meta($id) as $values) {
        foreach ($values as $v) {
            if (is_string($v)) {
                $text .= ' ' . str_replace('\/', '/', $v);
            }
        }
    }
    return $text;
}

function frp_watch_pages() {
    $pages = [];
    $errors = [];
    foreach (FRP_WATCH_PAGES as $path) {
        if ($path === '/') {
            $id = get_option('show_on_front') === 'page' ? (int) get_option('page_on_front') : 0;
        } else {
            $id = url_to_postid(home_url($path));
        }
        if (!$id || !get_post($id)) {
            $errors[] = "$path: not a page or post";
            continue;
        }
        $pages[$path] = frp_watch_page_text($id);
    }
    return [$pages, $errors];
}

function frp_watch_mentions($event, $text) {
    $slug = strtolower(basename(untrailingslashit($event['url'])));
    return strpos(strtolower($text), "/event/$slug") !== false
        || strpos(frp_watch_norm($text), frp_watch_norm($event['title'])) !== false;
}

/** @return array|null [subject, body], or null when there is nothing to report */
function frp_watch_report($now) {
    $events = frp_watch_events($now);
    if (is_wp_error($events)) {
        return ['FRP events watch: error', $events->get_error_message()];
    }
    $soon = array_filter($events, function ($e) use ($now) {
        return $e['start'] >= $now && $e['start'] <= $now + FRP_WATCH_SOON_HOURS * HOUR_IN_SECONDS;
    });
    $past = array_filter($events, function ($e) use ($now) {
        return $e['end'] < $now;
    });
    usort($soon, function ($a, $b) { return $a['start'] <=> $b['start']; });
    usort($past, function ($a, $b) { return $a['end'] <=> $b['end']; });

    [$pages, $errors] = frp_watch_pages();
    $where = function ($e) use ($pages) {
        return array_keys(array_filter($pages, function ($text) use ($e) {
            return frp_watch_mentions($e, $text);
        }));
    };

    $lines = [];
    if ($soon) {
        $lines[] = 'STARTING WITHIN ' . FRP_WATCH_SOON_HOURS . 'h';
        foreach ($soon as $e) {
            $hits = $where($e);
            $lines[] = "- {$e['title']} (" . wp_date('D M j g:ia T', $e['start']) . ')';
            $lines[] = "  {$e['url']}";
            $lines[] = '  On: ' . ($hits ? implode(', ', $hits) : 'not found on scanned pages');
        }
        $lines[] = '';
    }

    $stale = 0;
    foreach ($past as $e) {
        $hits = $where($e);
        if (!$hits) {
            continue;
        }
        if (!$stale++) {
            $lines[] = 'PAST BUT STILL ON SITE';
        }
        $lines[] = "- {$e['title']} (ended " . wp_date('D M j', $e['end']) . ')';
        $lines[] = "  {$e['url']}";
        $lines[] = '  On: ' . implode(', ', $hits);
    }
    if ($stale) {
        $lines[] = '';
    }

    if ($errors) {
        $lines[] = 'PAGES THAT COULD NOT BE CHECKED';
        foreach ($errors as $x) {
            $lines[] = "- $x";
        }
    }

    if (!$soon && !$stale) {
        return null;
    }
    return ['FRP events: ' . count($soon) . " soon, $stale past still live", implode("\n", $lines)];
}

/** @return string what happened, for the admin page */
function frp_watch_run($send) {
    $report = frp_watch_report(time());
    if (!$report) {
        return 'Nothing to report.';
    }
    [$subject, $body] = $report;
    if ($send) {
        $to = defined('FRP_WATCH_MAIL_TO') ? FRP_WATCH_MAIL_TO : get_option('admin_email');
        $ok = wp_mail($to, $subject, $body);
        $subject = ($ok ? "Sent to $to" : "wp_mail failed sending to $to") . "\n\n$subject";
    }
    return "$subject\n\n$body";
}

add_action('admin_menu', function () {
    add_management_page('FRP Events Watch', 'FRP Events Watch', 'manage_options', 'frp-events-watch', function () {
        $send = isset($_POST['frp_watch_send']) && check_admin_referer('frp_watch_send');
        $next = wp_next_scheduled(FRP_WATCH_HOOK);
        ?>
        <div class="wrap">
            <h1>FRP Events Watch</h1>
            <p>Next scheduled run: <?php echo $next ? esc_html(wp_date('D M j g:ia T', $next)) : 'not scheduled'; ?></p>
            <form method="post">
                <?php wp_nonce_field('frp_watch_send'); ?>
                <?php submit_button('Send email now', 'secondary', 'frp_watch_send', false); ?>
            </form>
            <h2><?php echo $send ? 'Result' : 'Preview (not sent)'; ?></h2>
            <pre style="white-space:pre-wrap"><?php echo esc_html(frp_watch_run($send)); ?></pre>
        </div>
        <?php
    });
});
