<?php

/** frp_watch_report end to end, with the calendar and pages stubbed. */
class ReportTest extends FrpTestCase {
    protected function setUp(): void {
        parent::setUp();
        // Every tracked page exists unless a test removes one; the front page is ID 1.
        $this->options = ['show_on_front' => 'page', 'page_on_front' => 1];
        $id = 1;
        foreach (FRP_WATCH_PAGES as $path) {
            $this->addPage($id++, trim($path, '/') ?: 'home', '');
        }
    }

    private function report() {
        return frp_watch_report(self::NOW, self::NOW - 1);
    }

    private function section($report, $key) {
        foreach ($report['sections'] as $s) {
            if ($s['key'] === $key) {
                return $s['items'];
            }
        }
        return [];
    }

    private function page($path) {
        return array_search(trim($path, '/'), array_map(function ($p) {
            return $p->post_name;
        }, $this->pages), true);
    }

    /** Regression: /lcw/ linked an event that ended months before, through Avia's ID link. */
    public function test_lcw_link_to_an_event_that_ended_months_ago_is_reported() {
        $id = $this->page('/lcw/');
        $this->addPage($id, 'lcw', "[av_image src='x.jpg' link='tribe_events,100'][av_button link='manually,https://www.familyrenewalproject.com/event/lcw-marian-paint-by-number/']");

        $stale = $this->section($this->report(), 'stale');
        $this->assertCount(1, $stale);
        $this->assertSame('LCW: Marian Paint by Number', $stale[0]['title']);
        $this->assertSame(['Button link', 'Image link'], $stale[0]['pages']['/lcw/']['how']);
    }

    /** Regression: /families/ had an empty image whose only link was a leftover link_dynamic. */
    public function test_families_empty_image_is_not_reported() {
        $this->addPage($this->page('/families/'), 'families', file_get_contents(__DIR__ . '/fixtures-families.txt'));
        $this->assertTrue($this->report()['quiet']);
    }

    public function test_title_in_an_empty_image_is_not_reported() {
        $this->fetched = [$this->event(310, 'Fall Picnic', '/event/fall-picnic/', -48, -46)];
        $this->addPage(1, 'home', "[av_image src='' attachment='' caption='Fall Picnic'][/av_image]");
        $this->assertTrue($this->report()['quiet']);
    }

    public function test_untracked_pages_are_not_scanned() {
        $this->pages[500] = (object) ['ID' => 500, 'post_type' => 'post', 'post_name' => 'blog',
                                      'post_content' => '<a href="/event/old-draft/">x</a>'];
        $this->assertTrue($this->report()['quiet']);
    }

    public function test_missing_tracked_page_is_listed() {
        unset($this->pages[$this->page('/gsc/')]);
        $this->assertSame(['/gsc/: not a page or post'], $this->section($this->report(), 'errors'));
    }

    public function test_front_page_falls_back_to_enfold() {
        $this->options = [];
        Brain\Monkey\Functions\when('avia_get_option')->justReturn(1);
        $this->addPage(1, 'home', '<a href="/event/old-draft/">x</a>');
        $this->assertSame('/', array_keys($this->section($this->report(), 'stale')[0]['pages'])[0]);
    }

    /** Avia's stored builder copy can keep links the page no longer shows (1.5.1). */
    public function test_avia_stored_builder_copy_is_never_read() {
        $id = $this->page('/lcw/');
        $this->addPage($id, 'lcw', '', "[av_image link='tribe_events,100']");
        $this->assertTrue($this->report()['quiet']);
    }

    public function test_event_starting_soon_lists_where_it_is_linked() {
        $this->fetched = [$this->event(300, 'Big Retreat', '/event/big-retreat/', 2, 4)];
        $this->addPage(1, 'home', '<a href="/event/big-retreat/">Go</a>');

        $soon = $this->section($this->report(), 'soon');
        $this->assertSame('Big Retreat', $soon[0]['title']);
        $this->assertSame(['Link'], $soon[0]['pages']['/']['how']);
    }

    public function test_event_today_is_listed_though_yesterdays_window_covered_it() {
        // Yesterday's report ran late, so its window reached 2h past today's run.
        $this->fetched = [$this->event(310, 'Today Event', '/event/today/', 1, 3),
                          $this->event(311, 'Started Today', '/event/started/', -2, 1),
                          $this->event(312, 'Started Yesterday', '/event/yesterday/', -20, 5)];
        $soon = $this->section(frp_watch_report(self::NOW, self::NOW + 2 * HOUR_IN_SECONDS), 'soon');
        $this->assertSame(['Started Today', 'Today Event'], array_column($soon, 'title'));
    }

    public function test_past_title_is_searched_within_the_lookback() {
        $this->fetched = [$this->event(301, 'Fall Picnic', '/event/fall-picnic/', -29 * 24 - 2, -29 * 24)];
        $this->addPage(1, 'home', 'Thanks for coming to the Fall Picnic!');
        $this->assertSame('Fall Picnic', $this->section($this->report(), 'stale')[0]['title']);
    }

    public function test_title_of_a_recurring_event_with_a_date_to_come_is_not_searched() {
        $this->fetched = [
            $this->event(302, 'Prayer Night', '/event/prayer-night/2026-10-20/', -7 * 24, -7 * 24 + 2),
            $this->event(303, 'Prayer Night', '/event/prayer-night/2026-11-03/', 7 * 24, 7 * 24 + 2),
        ];
        $this->addPage(1, 'home', 'Join us for Prayer Night every other Tuesday.');
        $this->assertTrue($this->report()['quiet']);
    }

    public function test_link_to_a_past_date_of_a_recurring_event_is_reported() {
        $this->fetched = [
            $this->event(302, 'Prayer Night', '/event/prayer-night/2026-10-20/', -7 * 24, -7 * 24 + 2),
            $this->event(303, 'Prayer Night', '/event/prayer-night/2026-11-03/', 7 * 24, 7 * 24 + 2),
        ];
        $this->addPage(1, 'home', '<a href="/event/prayer-night/2026-10-20/">Prayer Night</a>');
        $stale = $this->section($this->report(), 'stale');
        $this->assertSame(self::HOME . '/event/prayer-night/2026-10-20/', $stale[0]['url']);
    }

    public function test_links_in_menus_and_active_widgets_count_but_inactive_widgets_do_not() {
        Brain\Monkey\Functions\when('wp_get_nav_menus')->justReturn([(object) ['name' => 'Main', 'term_id' => 3]]);
        Brain\Monkey\Functions\when('wp_get_nav_menu_items')->justReturn([
            (object) ['url' => self::HOME . '/event/old-draft/', 'title' => 'Old'],
        ]);
        Brain\Monkey\Functions\when('wp_get_sidebars_widgets')->justReturn([
            'footer' => ['text-2'], 'wp_inactive_widgets' => ['text-3'],
        ]);
        $this->options['widget_text'] = [2 => ['text' => '<a href="/event/gone-event/">x</a>'],
                                          3 => ['text' => '<a href="/event/far-gone/">x</a>']];

        $stale = $this->section($this->report(), 'stale');
        $titles = array_column($stale, 'title');
        sort($titles);
        $this->assertSame(['Old Draft', 'gone-event'], $titles);
    }

    public function test_quiet_day() {
        $report = $this->report();
        $this->assertTrue($report['quiet']);
        $this->assertTrue($report['complete']);
        $this->assertSame('FRP events: nothing to report', $report['subject']);
    }
}
