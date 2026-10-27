<?php
/**
 * Plugin Name: FRP Events Watch
 * Description: Daily email listing events that start soon and past events still mentioned on key pages. Read-only; never edits content.
 * Version: 1.1.1
 * Requires PHP: 7.4
 *
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
    $run = frp_watch_run(true);
    update_option('frp_watch_last_run', ['time' => time(), 'status' => $run['status']], false);
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

/**
 * Page-builder meta keys that hold what the page shows. Other meta (SEO descriptions, caches,
 * old builder data) can keep mentioning an event after it is removed from the page, so it is skipped.
 */
const FRP_WATCH_BUILDER_META = ['_elementor_data', '_fl_builder_data', '_bricks_page_content_2',
                                'ct_builder_shortcodes', '_themify_builder_settings_json'];

/** @return array [source name => text]: saved content, rendered content, and page-builder data */
function frp_watch_page_text($id) {
    global $post;
    $page = get_post($id);
    $sources = ['saved content' => $page->post_content];

    $prev = $post;
    $post = $page;
    setup_postdata($page);
    $sources['rendered content'] = apply_filters('the_content', $page->post_content);
    $post = $prev;
    wp_reset_postdata();

    foreach (FRP_WATCH_BUILDER_META as $key) {
        $v = get_post_meta($id, $key, true);
        if (is_string($v) && $v !== '') {
            $sources["builder data ($key)"] = str_replace('\/', '/', $v);
        }
    }

    // Avia (Enfold) keeps its layout in meta but leaves it behind when a page is switched back
    // to the default editor, so only read it while the Avia builder is active on the page.
    if (get_post_meta($id, '_aviaLayoutBuilder_active', true) === 'active') {
        $v = get_post_meta($id, '_aviaLayoutBuilderCleanData', true);
        if (is_string($v) && $v !== '') {
            $sources['builder data (_aviaLayoutBuilderCleanData)'] = $v;
        }
    }
    return $sources;
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

/** @return string|null why the page mentions the event (e.g. "title in rendered content"), or null */
function frp_watch_mentions($event, $sources) {
    $slug = strtolower(basename(untrailingslashit($event['url'])));
    $title = frp_watch_norm($event['title']);
    foreach ($sources as $name => $text) {
        if (strpos(strtolower($text), "/event/$slug") !== false) {
            return "link in $name";
        }
        if (strpos(frp_watch_norm($text), $title) !== false) {
            return "title in $name";
        }
    }
    return null;
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
 *                    ['title', 'url', 'when', 'in_progress', 'pages' => [path => url]], error items
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

    [$pages, $errors] = frp_watch_pages();
    $why = []; // "title: path" => reason, shown on the Tools page
    $where = function ($e) use ($pages, &$why) {
        $hits = [];
        foreach ($pages as $path => $sources) {
            if ($reason = frp_watch_mentions($e, $sources)) {
                $hits[$path] = home_url($path);
                $why["{$e['title']}: $path"] = $reason;
            }
        }
        return $hits;
    };

    $sections = [];
    $items = [];
    foreach ($soon as $e) {
        $started = $e['start'] < $now;
        $items[] = ['title' => $e['title'], 'url' => $e['url'], 'in_progress' => $started,
                    'when' => ($started ? 'started ' : '') . wp_date('D M j g:ia T', $e['start']),
                    'pages' => $where($e)];
    }
    if ($items) {
        $sections[] = ['key' => 'soon', 'title' => 'Starting within ' . FRP_WATCH_SOON_HOURS . ' hours', 'items' => $items];
    }
    $n_soon = count($items);

    $items = [];
    foreach ($past as $e) {
        $hits = $where($e);
        if ($hits) {
            $items[] = ['title' => $e['title'], 'url' => $e['url'], 'in_progress' => false,
                        'when' => 'ended ' . wp_date('D M j', $e['end']), 'pages' => $hits];
        }
    }
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
                'sections' => $sections, 'why' => $why];
    }
    return ['subject' => "FRP events: $n_soon soon, $n_stale past still live", 'quiet' => false,
            'summary' => "$n_soon starting soon · $n_stale past " . ($n_stale === 1 ? 'event' : 'events') . ' still on the site',
            'sections' => $sections, 'why' => $why, 'complete' => true];
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
            $lines[] = '  On: ' . ($item['pages'] ? implode(', ', array_keys($item['pages'])) : 'not found on scanned pages');
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
            $chips = '';
            foreach ($item['pages'] as $path => $url) {
                $chips .= '<a href="' . $h($url) . '" style="display:inline-block;font-size:12px;color:#1f2a44;background:#eef2ff;border-radius:10px;padding:2px 9px;margin:2px 4px 2px 0;text-decoration:none;">'
                        . $h($path) . '</a>';
            }
            if (!$chips) {
                $chips = '<span style="color:#9ca3af;">Not on any tracked page</span>';
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
    $html = frp_watch_render_html($report, admin_url('tools.php?page=frp-events-watch'));
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
    return ['status' => $status, 'text' => $report['subject'] . "\n\n" . $text, 'html' => $html,
            'why' => $report['why'] ?? []];
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
            update_option('frp_watch_quiet_email', empty($_POST['frp_watch_quiet_email']) ? '0' : '1', false);
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
        $last = get_option('frp_watch_last_run');
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
                </p>
            </form>
            <?php $run = frp_watch_run($send); ?>
            <h2><?php echo $send ? 'Result' : 'Preview'; ?></h2>
            <p><?php echo esc_html($run['status']); ?></p>
            <?php if ($run['html']) : ?>
                <iframe sandbox="" srcdoc="<?php echo esc_attr($run['html']); ?>"
                        style="width:100%;max-width:680px;height:640px;border:1px solid #dcdcde;background:#fff;"></iframe>
            <?php endif; ?>
            <details<?php echo $run['html'] ? '' : ' open'; ?>>
                <summary>Plain-text version</summary>
                <pre style="white-space:pre-wrap"><?php echo esc_html($run['text']); ?></pre>
            </details>
            <?php if ($run['why']) : ?>
                <h2>Why each page matched</h2>
                <ul>
                    <?php foreach ($run['why'] as $what => $reason) : ?>
                        <li><?php echo esc_html("$what — $reason"); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php
    });
});
