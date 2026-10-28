<?php

/** The "not on any tracked page", "possible duplicates" and urgent-graphic parts of frp_watch_report. */
class ChecksTest extends FrpTestCase {
    protected function setUp(): void {
        parent::setUp();
        $this->options = ['show_on_front' => 'page', 'page_on_front' => 1];
        $id = 1;
        foreach (FRP_WATCH_PAGES as $path) {
            $this->addPage($id++, trim($path, '/') ?: 'home', '');
        }
    }

    private function report() {
        return frp_watch_report(self::NOW, self::NOW - 1);
    }

    private function section($key, $report = null) {
        foreach (($report ?? $this->report())['sections'] as $s) {
            if ($s['key'] === $key) {
                return $s['items'];
            }
        }
        return [];
    }

    public function test_upcoming_event_on_no_tracked_page_is_listed() {
        $this->fetched = [$this->event(500, 'Potluck', '/event/potluck/', 10 * 24, 10 * 24 + 3)];
        $items = $this->section('unpromoted');
        $this->assertSame(['Potluck'], array_column($items, 'title'));
        $this->assertSame('starts Fri Nov 6', $items[0]['when']);
        $this->assertSame('Not linked or named on any tracked page, menu or widget', $items[0]['issue']);
    }

    public function test_event_linked_or_named_on_a_tracked_page_is_promoted() {
        $this->fetched = [
            $this->event(501, 'Potluck', '/event/potluck/', 10 * 24, 10 * 24 + 3),
            $this->event(502, 'Advent Talk', '/event/advent-talk/', 12 * 24, 12 * 24 + 3),
        ];
        $this->addPage(6, 'lgw', '<a href="/event/potluck/">Join us</a> and come to the Advent Talk.');
        $this->assertSame([], $this->section('unpromoted'));
    }

    public function test_recurring_event_linked_by_its_series_link_is_promoted() {
        $this->fetched = [
            $this->event(503, 'Prayer Night', '/event/prayer-night/2026-11-03/', 7 * 24, 7 * 24 + 2),
            $this->event(504, 'Prayer Night', '/event/prayer-night/2026-11-10/', 14 * 24, 14 * 24 + 2),
        ];
        $this->addPage(8, 'prayer', '<a href="/event/prayer-night/">Every Tuesday</a>');
        $this->assertSame([], $this->section('unpromoted'));
    }

    public function test_events_far_off_or_starting_soon_are_not_listed_as_unpromoted() {
        $this->fetched = [
            $this->event(505, 'Far Off', '/event/far-off/', (FRP_WATCH_PROMOTE_DAYS + 1) * 24, (FRP_WATCH_PROMOTE_DAYS + 1) * 24 + 3),
            $this->event(506, 'Tonight', '/event/tonight/', 6, 8),
        ];
        $report = $this->report();
        $this->assertSame([], $this->section('unpromoted', $report));
        $this->assertSame(['Tonight'], array_column($this->section('soon', $report), 'title'));
    }

    public function test_same_title_on_the_same_day_is_a_possible_duplicate() {
        $this->fetched = [
            $this->event(510, 'Christmas Party', '/event/christmas-party/', 50 * 24, 50 * 24 + 3),
            $this->event(511, 'Christmas Party', '/event/christmas-party-2/', 50 * 24 + 1, 50 * 24 + 4),
            $this->event(512, 'Christmas Party', '/event/christmas-party-3/', 51 * 24, 51 * 24 + 3),
        ];
        $items = $this->section('duplicates');
        $this->assertCount(1, $items);
        $this->assertSame(self::HOME . '/event/christmas-party/', $items[0]['url']);
        $this->assertSame('Same title and day as ' . self::HOME . '/event/christmas-party-2/', $items[0]['issue']);
    }

    public function test_dates_of_one_recurring_event_are_not_duplicates() {
        $this->fetched = [
            $this->event(513, 'Prayer Night', '/event/prayer-night/2026-11-03/', 7 * 24, 7 * 24 + 2),
            $this->event(514, 'Prayer Night', '/event/prayer-night/2026-11-10/', 14 * 24, 14 * 24 + 2),
        ];
        $this->assertSame([], $this->section('duplicates'));
    }

    public function test_ended_events_are_not_duplicates() {
        $this->fetched = [
            $this->event(515, 'Picnic', '/event/picnic/', -5, -3),
            $this->event(516, 'Picnic', '/event/picnic-2/', -4, -2),
        ];
        $this->assertSame([], $this->section('duplicates'));
    }

    public function test_missing_graphic_within_a_week_is_urgent_and_goes_first() {
        $this->fetched = [
            $this->event(520, 'Bonfire', '/event/bonfire/', 3 * 24, 3 * 24 + 3, ''),
            $this->event(521, 'Potluck', '/event/potluck/', 20 * 24, 20 * 24 + 3, ''),
        ];
        $this->addPage(1, 'home', '<a href="/event/bonfire/">x</a><a href="/event/potluck/">y</a>');
        $report = $this->report();
        $report['date'] = 'Tuesday';
        $this->assertSame('graphics', $report['sections'][0]['key']);
        $this->assertSame([true, false], array_column($report['sections'][0]['items'], 'urgent'));
        $this->assertSame('Urgent: FRP events: 0 soon, 0 past still live, 2 missing graphics', $report['subject']);
        $this->assertStringContainsString('- Bonfire (starts Fri Oct 30) URGENT', frp_watch_render_text($report));
        $this->assertSame(1, substr_count(frp_watch_render_html($report, ''), '>Urgent</span>'));
    }

    public function test_no_urgent_graphic_keeps_the_usual_order_and_subject() {
        $this->fetched = [$this->event(522, 'Potluck', '/event/potluck/', 20 * 24, 20 * 24 + 3, '')];
        $this->addPage(1, 'home', '<a href="/event/potluck/">y</a>');
        $report = $this->report();
        $this->assertSame('FRP events: 0 soon, 0 past still live, 1 missing a graphic', $report['subject']);
        $this->assertSame([false], array_column($this->section('graphics', $report), 'urgent'));
    }
}
