<?php

/** frp_watch_unused: leftovers in Avia settings that the page never shows. */
class UnusedTest extends FrpTestCase {
    const URL = 'https://www.familyrenewalproject.com/event/lcw-marian-paint-by-number/';

    private function unused($text, $needle) {
        return frp_watch_unused($text, strpos($text, $needle));
    }

    /** The empty image on /families/ (Oct 2026): no image, and the URL only in link_dynamic. */
    public function test_families_empty_image_is_unused() {
        $text = file_get_contents(__DIR__ . '/fixtures-families.txt');
        $this->assertTrue($this->unused($text, self::URL));
    }

    public function test_link_dynamic_without_link_is_unused() {
        $text = "[av_image src='a.jpg' attachment='12' link='' link_dynamic='" . self::URL . "'][/av_image]";
        $this->assertTrue($this->unused($text, self::URL));
    }

    public function test_link_dynamic_with_link_set_counts() {
        $text = "[av_image src='a.jpg' link='manually,/x/' link_dynamic='" . self::URL . "'][/av_image]";
        $this->assertFalse($this->unused($text, self::URL));
    }

    public function test_empty_image_with_a_link_is_unused() {
        $text = "[av_image src='' attachment='' src_dynamic='' link='manually," . self::URL . "'][/av_image]";
        $this->assertTrue($this->unused($text, self::URL));
    }

    public function test_dynamic_image_counts() {
        $text = "[av_image src='' attachment='' src_dynamic='{featured}' link='manually," . self::URL . "'][/av_image]";
        $this->assertFalse($this->unused($text, self::URL));
    }

    public function test_image_with_a_source_counts() {
        $this->assertFalse($this->unused("[av_image src='a.jpg' link='tribe_events,100']", 'tribe_events,100'));
    }

    public function test_double_quoted_settings_are_read() {
        $text = '[av_button label="Go" link="" link_dynamic="' . self::URL . '"]';
        $this->assertTrue($this->unused($text, self::URL));
    }

    public function test_link_in_html_counts() {
        $text = "[av_image src='' attachment=''][/av_image] <a href=\"" . self::URL . '">Go</a>';
        $this->assertFalse($this->unused($text, self::URL));
    }

    public function test_link_in_element_content_counts() {
        $text = "[av_textblock link_dynamic='']<a href=\"" . self::URL . '">Go</a>[/av_textblock]';
        $this->assertFalse($this->unused($text, self::URL));
    }
}
