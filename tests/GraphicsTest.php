<?php

/** frp_watch_graphic and the "Missing a graphic" section of frp_watch_report. */
class GraphicsTest extends FrpTestCase {
    private function issue($content, $thumb = 0) {
        return frp_watch_graphic(['content' => $content, 'thumb' => $thumb]);
    }

    /** @dataProvider graphics */
    public function test_has_a_graphic($content) {
        $this->assertNull($this->issue($content));
    }

    public function graphics() {
        return [
            'img tag' => [self::FLYER],
            'img with a relative src' => ['<img class="x" src="/wp-content/uploads/flyer.png">'],
            'img with single quotes' => ["<p><img alt='' src='https://familyrenewalproject.com/wp-content/uploads/flyer.png'></p>"],
            'block editor image' => ['<!-- wp:image {"id":5} --><figure><img src="https:\/\/www.familyrenewalproject.com\/wp-content\/uploads\/flyer.png"/></figure><!-- /wp:image -->'],
            'Avia image by src' => ["[av_image src='https://www.familyrenewalproject.com/wp-content/uploads/flyer.png' attachment='']"],
            'Avia image by attachment' => ["[av_image src='' attachment='42']"],
            'Jetpack image' => ['<img src="https://i0.wp.com/www.familyrenewalproject.com/wp-content/uploads/flyer.png">'],
            'flyer next to a placeholder' => ['<img src="/wp-content/uploads/2024/09/LGW_Logo_R_Horizontal.png">' . self::FLYER],
        ];
    }

    public function test_featured_image_counts() {
        $this->assertNull($this->issue('<p>Join us!</p>', 7));
    }

    public function test_no_image() {
        $this->assertSame('No graphic', $this->issue('<p>Join us!</p>'));
        $this->assertSame('No graphic', $this->issue(''));
        $this->assertSame('No graphic', $this->issue("[av_image src='' attachment='']"));
    }

    public function test_placeholder_only() {
        $logo = '<img src="https://www.familyrenewalproject.com/wp-content/uploads/2024/09/lgw_logo_r_horizontal.png?resize=466%2C135">';
        $this->assertSame('Only a placeholder image (lgw_logo_r_horizontal.png)', $this->issue($logo . $logo));
    }

    public function test_image_on_another_site() {
        $fb = '<img src="https://scontent-ord5-2.xx.fbcdn.net/v/t39/flyer.png?oe=6AE74542&amp;x=1">';
        $this->assertSame('Graphic is linked from scontent-ord5-2.xx.fbcdn.net and may stop loading; upload it to the Media Library',
                          $this->issue($fb . '<img src="/wp-content/uploads/2024/09/LGW_Logo_R_Horizontal.png">'));
        $this->assertNull($this->issue($fb . self::FLYER));
    }

    public function test_lookalike_host_is_another_site() {
        $this->assertStringStartsWith('Graphic is linked from notfamilyrenewalproject.com',
                                      $this->issue('<img src="https://notfamilyrenewalproject.com/flyer.png">'));
    }

    private function graphicsSection() {
        foreach (frp_watch_report(self::NOW, self::NOW - 1)['sections'] as $s) {
            if ($s['key'] === 'graphics') {
                return $s['items'];
            }
        }
        return [];
    }

    public function test_report_lists_upcoming_events_without_a_graphic_soonest_first() {
        $this->fetched = [
            $this->event(400, 'Christmas Party', '/event/christmas-party/', 50 * 24, 50 * 24 + 3, '<p>Party</p>'),
            $this->event(401, 'Potluck', '/event/potluck/', 24 * 24, 24 * 24 + 3, '<img src="/wp-content/uploads/LGW_Logo_R_Horizontal.png">'),
            $this->event(402, 'Has Flyer', '/event/has-flyer/', 10 * 24, 10 * 24 + 3),
            $this->event(403, 'Ended', '/event/ended/', -48, -46, ''),
        ];
        $items = $this->graphicsSection();
        $this->assertSame(['Potluck', 'Christmas Party'], array_column($items, 'title'));
        $this->assertSame('starts Fri Nov 20', $items[0]['when']);
        $this->assertSame('Only a placeholder image (LGW_Logo_R_Horizontal.png)', $items[0]['issue']);
    }

    public function test_event_in_progress_is_listed_as_started() {
        $this->fetched = [$this->event(404, 'Retreat', '/event/retreat/', -2, 30, '')];
        $items = $this->graphicsSection();
        $this->assertTrue($items[0]['in_progress']);
        $this->assertSame('started Tue Oct 27', $items[0]['when']);
    }

    public function test_recurring_event_is_listed_once_at_its_next_date() {
        $this->fetched = [
            $this->event(405, 'Prayer Night', '/event/prayer-night/2026-11-10/', 14 * 24, 14 * 24 + 2, ''),
            $this->event(406, 'Prayer Night', '/event/prayer-night/2026-11-03/', 7 * 24, 7 * 24 + 2, ''),
        ];
        $items = $this->graphicsSection();
        $this->assertCount(1, $items);
        $this->assertSame(self::HOME . '/event/prayer-night/2026-11-03/', $items[0]['url']);
    }

    public function test_missing_graphic_is_not_a_quiet_day_and_shows_in_the_email() {
        // Past FRP_WATCH_PROMOTE_DAYS, so it isn't also listed as not on a tracked page.
        $this->fetched = [$this->event(407, 'Potluck', '/event/potluck/', 40 * 24, 40 * 24 + 3, '')];
        $report = frp_watch_report(self::NOW, self::NOW - 1);
        $report['date'] = 'Tuesday';
        $this->assertFalse($report['quiet']);
        $this->assertSame('FRP events: 0 soon, 0 past still live, 1 missing a graphic', $report['subject']);

        $text = frp_watch_render_text($report);
        $this->assertStringContainsString("MISSING A GRAPHIC\n- Potluck (starts Sun Dec 6)\n  " . self::HOME . "/event/potluck/\n  No graphic", $text);
        $this->assertStringNotContainsString('On:', $text);
        $html = frp_watch_render_html($report, '');
        $this->assertStringContainsString('No graphic', $html);
        $this->assertStringNotContainsString('Not on any tracked page', $html);
    }

    public function test_quiet_when_every_event_has_a_graphic() {
        $this->fetched = [$this->event(408, 'Has Flyer', '/event/has-flyer/', 10 * 24, 10 * 24 + 3)];
        $this->assertSame([], $this->graphicsSection());
    }
}
