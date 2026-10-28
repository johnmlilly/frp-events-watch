<?php

/**
 * frp_watch_resolve: a link to an event outside the fetched window is stale when the event ended,
 * isn't published or no longer exists, and fine when it is still to come.
 */
class ResolveTest extends FrpTestCase {
    public function test_event_that_ended_long_ago_is_stale() {
        $item = frp_watch_resolve('lcw-marian-paint-by-number', [], self::NOW);
        $this->assertSame('LCW: Marian Paint by Number', $item['title']);
        $this->assertSame('ended Sat Aug 1, 2026', $item['when']);
        $this->assertSame(self::HOME . '/event/lcw-marian-paint-by-number/', $item['url']);
    }

    public function test_draft_is_stale() {
        $this->assertSame('event is not published (draft)', frp_watch_resolve('old-draft', [], self::NOW)['when']);
    }

    public function test_trashed_event_is_found_by_its_old_slug() {
        $item = frp_watch_resolve('old-trash', [], self::NOW);
        $this->assertSame('Old Trash', $item['title']);
        $this->assertSame('event is not published (trash)', $item['when']);
    }

    public function test_missing_event_is_stale() {
        $this->assertSame('event no longer exists', frp_watch_resolve('gone-event', [], self::NOW)['when']);
        $this->assertSame('event no longer exists', frp_watch_resolve('#999', [], self::NOW)['when']);
    }

    public function test_scheduled_event_is_fine() {
        $this->assertNull(frp_watch_resolve('scheduled-thing', [], self::NOW));
    }

    public function test_event_more_than_a_year_ahead_is_fine() {
        $this->assertNull(frp_watch_resolve('far-future', [], self::NOW));
    }

    public function test_past_date_of_a_recurring_event_is_stale() {
        $item = frp_watch_resolve('prayer-night/2026-09-01', [], self::NOW);
        $this->assertSame('was on Tue Sep 1, 2026', $item['when']);
        $this->assertSame(self::HOME . '/event/prayer-night/2026-09-01/', $item['url']);
    }

    public function test_series_link_is_fine_while_a_date_is_to_come() {
        $next = $this->event(201, 'Prayer Night', '/event/prayer-night/2026-11-03/', 168, 170);
        $this->assertNull(frp_watch_resolve('prayer-night', ['prayer-night/2026-11-03' => $next], self::NOW));
    }

    public function test_series_link_is_stale_once_every_date_has_passed() {
        $this->assertSame('ended Tue Jan 7, 2025', frp_watch_resolve('prayer-night', [], self::NOW)['when']);
    }
}
