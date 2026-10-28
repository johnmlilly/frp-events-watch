<?php

/** Dismiss links: frp_watch_dismiss_page, and dismissed items left out of frp_watch_report. */
class DismissTest extends FrpTestCase {
    protected function setUp(): void {
        parent::setUp();
        $this->options = ['show_on_front' => 'page', 'page_on_front' => 1];
        $id = 1;
        foreach (FRP_WATCH_PAGES as $path) {
            $this->addPage($id++, trim($path, '/') ?: 'home', '');
        }
        // Linked from the front page, so only its missing graphic is reported.
        $this->fetched = [$this->event(600, 'Potluck', '/event/potluck/', 20 * 24, 20 * 24 + 3, '')];
        $this->addPage(1, 'home', '<a href="/event/potluck/">Potluck</a>');
    }

    private function report() {
        return frp_watch_report(self::NOW, self::NOW - 1);
    }

    /** The query of the item's dismiss link. */
    private function link($item) {
        parse_str(parse_url($item['dismiss'], PHP_URL_QUERY), $q);
        return $q;
    }

    private function item() {
        return $this->report()['sections'][0]['items'][0];
    }

    public function test_item_has_a_dismiss_link() {
        $q = $this->link($this->item());
        $this->assertStringStartsWith(self::HOME . '/wp-admin/admin-post.php?', $this->item()['dismiss']);
        $this->assertSame(['action' => 'frp_watch_dismiss', 's' => 'graphics', 'title' => 'Potluck'],
                          array_intersect_key($q, ['action' => 1, 's' => 1, 'title' => 1]));
        $this->assertStringContainsString('Dismiss: ' . $this->item()['dismiss'],
                                          frp_watch_render_text($this->report()));
    }

    public function test_opening_the_link_only_asks() {
        [$status, $html] = frp_watch_dismiss_page($this->link($this->item()), false);
        $this->assertSame(200, $status);
        $this->assertStringContainsString('<form method="post"', $html);
        $this->assertStringContainsString('<strong>Potluck</strong> (Missing a graphic)', $html);
        $this->assertArrayNotHasKey('frp_watch_dismissed', $this->options);
    }

    public function test_confirming_hides_the_item_until_it_changes() {
        $item = $this->item();
        [$status] = frp_watch_dismiss_page($this->link($item), true);
        $this->assertSame(200, $status);
        $this->assertSame('Potluck', $this->options['frp_watch_dismissed'][$item['id']]['title']);

        $report = $this->report();
        $report['date'] = 'Tuesday';
        $this->assertTrue($report['quiet']);
        $this->assertSame([$item['id']], $report['hidden']);
        $this->assertStringContainsString('1 dismissed item is hidden.', frp_watch_render_text($report));
        $this->assertStringContainsString('1 dismissed item is hidden.', frp_watch_render_html($report, ''));

        // A placeholder instead of nothing is a different issue, so the item comes back.
        $this->fetched[0]['content'] = '<img src="/wp-content/uploads/LGW_Logo_R_Horizontal.png">';
        $this->assertFalse($this->report()['quiet']);
    }

    /** @dataProvider tampered */
    public function test_a_tampered_link_is_refused($field, $value) {
        $q = $this->link($this->item());
        $q[$field] = $value;
        [$status] = frp_watch_dismiss_page($q, true);
        $this->assertSame(403, $status);
        $this->assertArrayNotHasKey('frp_watch_dismissed', $this->options);
    }

    public function tampered() {
        return [['t', 'abc'], ['item', 'aaaaaaaaaaaa'], ['title', 'Other'], ['s', 'stale'], ['item', '../x']];
    }

    public function test_events_starting_soon_cannot_be_dismissed() {
        $this->fetched = [$this->event(601, 'Tonight', '/event/tonight/', 6, 8)];
        $this->addPage(1, 'home', '<a href="/event/tonight/">x</a>');
        $this->assertArrayNotHasKey('dismiss', $this->report()['sections'][0]['items'][0]);
    }

    public function test_dismissed_items_no_longer_reported_are_forgotten() {
        $item = $this->item();
        $this->options['frp_watch_dismissed'] = [$item['id'] => ['title' => 'Potluck', 'section' => 'graphics', 'time' => 1],
                                                 'aaaaaaaaaaaa' => ['title' => 'Gone', 'section' => 'stale', 'time' => 1]];
        frp_watch_prune_dismissed($this->report()['hidden']);
        $this->assertSame([$item['id']], array_keys($this->options['frp_watch_dismissed']));
    }
}
