<?php
/**
 * Plugin Name: FRP Events Watch
 * Description: Daily email listing events that start soon and past events still mentioned on key pages. Read-only; never edits content.
 * Version: 1.0.0
 * Requires PHP: 7.4
 *
 * Port of events_watch.py that runs inside WordPress, so Cloudflare is never involved.
 * Recipient, Resend settings, preview and manual send: Tools > FRP Events Watch.
 * With a Resend API key set, mail goes through Resend's API; otherwise through wp_mail.
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
 * @return array|null [subject, body, complete], or null when there is nothing to report.
 *                    complete is false when events could not be read.
 */
function frp_watch_report($now, $soon_from) {
    $events = frp_watch_events($now);
    if (is_wp_error($events)) {
        return ['FRP events watch: error', $events->get_error_message(), false];
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
            $when = ($e['start'] < $now ? 'started ' : '') . wp_date('D M j g:ia T', $e['start']);
            $lines[] = "- {$e['title']} ($when)";
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
    return ['FRP events: ' . count($soon) . " soon, $stale past still live", implode("\n", $lines), true];
}

function frp_watch_recipient() {
    return get_option('frp_watch_mail_to') ?: get_option('admin_email');
}

/** @return true|string true on success, otherwise an error message */
function frp_watch_mail($to, $subject, $body) {
    $key = get_option('frp_watch_resend_key');
    if (!$key) {
        return wp_mail($to, $subject, $body) ?: 'wp_mail failed';
    }
    $r = wp_remote_post('https://api.resend.com/emails', [
        'timeout' => 30,
        'headers' => ['Authorization' => "Bearer $key", 'Content-Type' => 'application/json'],
        'body' => wp_json_encode(['from' => get_option('frp_watch_mail_from') ?: 'onboarding@resend.dev',
                                  'to' => [$to], 'subject' => $subject, 'text' => $body]),
    ]);
    if (is_wp_error($r)) {
        return 'Resend request failed: ' . $r->get_error_message();
    }
    $code = wp_remote_retrieve_response_code($r);
    return $code >= 200 && $code < 300 ?: "Resend returned $code: " . wp_remote_retrieve_body($r);
}

/**
 * Previews never move the soon window. A send moves it forward only once the report is
 * delivered (or there was nothing to report), so a failed run is retried next time.
 * @return string what happened, for the admin page
 */
function frp_watch_run($send) {
    $now = time();
    $report = frp_watch_report($now, frp_watch_soon_from($now));
    $done = !$report;
    if ($report) {
        [$subject, $body, $complete] = $report;
        if ($send) {
            $to = frp_watch_recipient();
            $ok = frp_watch_mail($to, $subject, $body);
            $done = $ok === true && $complete;
            $subject = ($ok === true ? "Sent to $to" : "Failed sending to $to: $ok") . "\n\n$subject";
        }
    }
    if ($send && $done) {
        update_option('frp_watch_soon_until', $now + FRP_WATCH_SOON_HOURS * HOUR_IN_SECONDS, false);
    }
    return $report ? "$subject\n\n$body" : 'Nothing to report.';
}

add_action('admin_menu', function () {
    add_management_page('FRP Events Watch', 'FRP Events Watch', 'manage_options', 'frp-events-watch', function () {
        $notice = '';
        if (isset($_POST['frp_watch_save']) && check_admin_referer('frp_watch')) {
            $to = sanitize_email(wp_unslash($_POST['frp_watch_mail_to'] ?? ''));
            $from = sanitize_text_field(wp_unslash($_POST['frp_watch_mail_from'] ?? ''));
            $key = sanitize_text_field(wp_unslash($_POST['frp_watch_resend_key'] ?? ''));
            update_option('frp_watch_mail_to', $to, false);
            update_option('frp_watch_mail_from', $from, false);
            // Blank keeps the saved key; the remove box clears it.
            if (!empty($_POST['frp_watch_resend_clear'])) {
                delete_option('frp_watch_resend_key');
            } elseif ($key) {
                update_option('frp_watch_resend_key', $key, false);
            }
            $notice = 'Saved. Reports will go to ' . frp_watch_recipient() . '.';
        }
        if (isset($_POST['frp_watch_test']) && check_admin_referer('frp_watch')) {
            $to = frp_watch_recipient();
            $ok = frp_watch_mail($to, 'FRP events watch: test', 'Test email from the FRP Events Watch plugin. Delivery works.');
            $notice = $ok === true ? "Test email sent to $to. Check spam if it doesn't arrive." : "Failed sending to $to: $ok";
        }
        $send = isset($_POST['frp_watch_send']) && check_admin_referer('frp_watch');
        $next = wp_next_scheduled(FRP_WATCH_HOOK);
        $saved_key = get_option('frp_watch_resend_key');
        ?>
        <div class="wrap">
            <h1>FRP Events Watch</h1>
            <?php if ($notice) : ?>
                <div class="notice notice-info"><p><?php echo esc_html($notice); ?></p></div>
            <?php endif; ?>
            <form method="post">
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
                <p><?php submit_button('Save', 'primary', 'frp_watch_save', false); ?></p>
                <p>Next scheduled run: <?php echo $next ? esc_html(wp_date('D M j g:ia T', $next)) : 'not scheduled'; ?></p>
                <p>
                    <?php submit_button('Send test email', 'secondary', 'frp_watch_test', false); ?>
                    <?php submit_button('Send report now', 'secondary', 'frp_watch_send', false); ?>
                </p>
            </form>
            <h2><?php echo $send ? 'Result' : 'Preview (not sent)'; ?></h2>
            <pre style="white-space:pre-wrap"><?php echo esc_html(frp_watch_run($send)); ?></pre>
        </div>
        <?php
    });
});
