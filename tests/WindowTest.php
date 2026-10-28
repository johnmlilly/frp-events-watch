<?php

/** The "starting soon" window carried between runs. */
class WindowTest extends FrpTestCase {
    public function test_first_run_starts_now() {
        $this->assertSame(self::NOW - 1, frp_watch_soon_from(self::NOW));
    }

    public function test_later_run_starts_where_the_last_delivered_window_ended() {
        $this->options['frp_watch_soon_until'] = self::NOW - 3 * HOUR_IN_SECONDS;
        $this->assertSame(self::NOW - 3 * HOUR_IN_SECONDS, frp_watch_soon_from(self::NOW));
    }

    public function test_window_start_is_capped_at_the_lookback() {
        $this->options['frp_watch_soon_until'] = self::NOW - 90 * DAY_IN_SECONDS;
        $this->assertSame(self::NOW - FRP_WATCH_PAST_LOOKBACK_DAYS * DAY_IN_SECONDS, frp_watch_soon_from(self::NOW));
    }
}
