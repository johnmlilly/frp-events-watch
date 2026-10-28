<?php

/** Title matching and naming where on the page a match is. */
class MatchingTest extends FrpTestCase {
    /** @dataProvider titles */
    public function test_title_matches_whole_phrases_only($text, $matches) {
        $re = frp_watch_title_re('Retreat 2026');
        $this->assertSame($matches, (bool) preg_match($re, frp_watch_norm($text)));
    }

    public function titles() {
        return [
            'exact' => ['Retreat 2026', true],
            'any case, extra spaces' => ["join the  RETREAT\n2026!", true],
            'entity' => ['Retreat&nbsp;2026', true],
            'longer word' => ['Retreats 2026', false],
            'longer number' => ['Retreat 20267', false],
            'part of the title' => ['Retreat', false],
        ];
    }

    public function test_title_normalizes_curly_quotes_and_dashes() {
        $re = frp_watch_title_re("Mom's Night \u{2013} Fall");
        $this->assertSame(1, preg_match($re, frp_watch_norm("Mom\u{2019}s night - fall")));
    }

    public function test_empty_title_never_matches() {
        $this->assertNull(frp_watch_title_re(''));
        $this->assertNull(frp_watch_title_re('   '));
    }

    /** @dataProvider elements */
    public function test_names_the_element_around_a_match($text, $needle, $kind, $expected) {
        $this->assertSame($expected, frp_watch_element($text, strpos($text, $needle), $kind));
    }

    public function elements() {
        return [
            'link in an image attribute' => ["[av_image src='a.jpg' link='/event/x/']", '/event/x/', 'link', 'Image link'],
            'link in a button attribute' => ["[av_button label='Go' link='/event/x/']", '/event/x/', 'link', 'Button link'],
            'text block after a self-closing button' =>
                ["[av_button link='/a/'][av_textblock]<a href='/event/x/'>x</a>[/av_textblock]", '/event/x/', 'link', 'Text block link'],
            'after a closed element' => ['[av_textblock]a[/av_textblock] <a href="/event/x/">x</a>', '/event/x/', 'link', 'Link'],
            'unknown Avia element' => ["[av_fancy_thing link='/event/x/']", '/event/x/', 'link', 'Fancy thing link'],
            'plain link' => ['<a href="/event/x/">x</a>', '/event/x/', 'link', 'Link'],
            'title in a heading' => ['[av_heading]retreat 2026[/av_heading]', 'retreat', 'title', 'Heading (title)'],
            'plain title' => ['come to retreat 2026', 'retreat', 'title', 'Text (title)'],
        ];
    }
}
