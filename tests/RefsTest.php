<?php

/** frp_watch_refs: every link to an event, in every form, and nothing else. */
class RefsTest extends FrpTestCase {
    private function keys($text) {
        return array_column(frp_watch_refs($text), 0);
    }

    /** @dataProvider linkForms */
    public function test_finds_each_link_form($text, $key) {
        $this->assertSame([$key], $this->keys($text));
    }

    public function linkForms() {
        return [
            'full URL' => ['<a href="https://www.familyrenewalproject.com/event/retreat/">x</a>', 'retreat'],
            'without www' => ['<a href="https://familyrenewalproject.com/event/retreat/">x</a>', 'retreat'],
            'http' => ['<a href="http://www.familyrenewalproject.com/event/retreat">x</a>', 'retreat'],
            'relative' => ["<a href='/event/retreat/'>x</a>", 'retreat'],
            'JSON block attribute' => ['{"url":"/event/retreat/"}', 'retreat'],
            'Avia manual link' => ["[av_button link='manually,https://www.familyrenewalproject.com/event/retreat/']", 'retreat'],
            'one date of a recurring event' => ['<a href="/event/prayer-night/2026-10-20/">x</a>', 'prayer-night/2026-10-20'],
            'plain permalink' => ['<a href="/?tribe_events=retreat">x</a>', 'retreat'],
            'plain permalink, encoded &' => ['<a href="/?foo=1&amp;tribe_events=retreat">x</a>', 'retreat'],
            'uppercase slug' => ['<a href="/event/Retreat/">x</a>', 'retreat'],
        ];
    }

    /** @dataProvider notLinks */
    public function test_ignores_other_links($text) {
        $this->assertSame([], $this->keys($text));
    }

    public function notLinks() {
        return [
            'nested path' => ['<a href="/other/event/retreat/">x</a>'],
            'another site' => ['<a href="https://example.org/event/retreat/">x</a>'],
            'events archive' => ['<a href="/events/">x</a>'],
            'event base alone' => ['<a href="/event/">x</a>'],
            'plain text' => ['come to the retreat'],
        ];
    }

    public function test_longer_slug_is_a_different_event() {
        $this->assertSame(['retreat-2026'], $this->keys('<a href="/event/retreat-2026/">x</a>'));
    }

    public function test_avia_id_link_resolves_to_the_events_slug() {
        $this->assertSame(['lcw-marian-paint-by-number'], $this->keys("[av_image link='tribe_events,100']"));
    }

    public function test_avia_id_link_to_a_draft_uses_its_slug() {
        $this->assertSame(['old-draft'], $this->keys("[av_button link='tribe_events,101']"));
    }

    public function test_avia_id_link_to_a_trashed_event_drops_the_trash_suffix() {
        $this->assertSame(['old-trash'], $this->keys("[av_button link='tribe_events,104']"));
    }

    public function test_avia_id_link_to_a_deleted_event_keeps_the_id() {
        $this->assertSame(['#999'], $this->keys("[av_button link='tribe_events,999']"));
    }

    public function test_offsets_point_at_each_link() {
        $text = 'a <a href="/event/one/">1</a> b <a href="/event/two/">2</a>';
        $refs = frp_watch_refs($text);
        $this->assertSame(['one', 'two'], array_column($refs, 0));
        $this->assertSame('/event/one/', substr($text, $refs[0][1], 11));
    }

    public function test_event_key_matches_the_key_of_a_link_to_it() {
        $event = $this->event(1, 'Retreat', '/event/retreat/', 1, 2);
        $this->assertSame('retreat', frp_watch_event_key($event));
        $dated = $this->event(2, 'Prayer Night', '/event/prayer-night/2026-11-03/', 1, 2);
        $this->assertSame('prayer-night/2026-11-03', frp_watch_event_key($dated));
    }
}
