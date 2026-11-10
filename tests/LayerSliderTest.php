<?php

/** LayerSlider sliders on a tracked page are read from LayerSlider's table. */
class LayerSliderTest extends FrpTestCase {
    /** @var array slider id => ['name', 'slug', 'data' => array] */
    private $sliders = [];

    protected function setUp(): void {
        parent::setUp();
        $sliders = &$this->sliders;
        $GLOBALS['wpdb'] = new class($sliders) {
            public $prefix = 'wp_';
            private $sliders;
            public function __construct(&$sliders) {
                $this->sliders = &$sliders;
            }
            public function suppress_errors($on = true) {
                return false;
            }
            public function prepare($sql, ...$args) {
                return [$sql, $args];
            }
            public function get_row($q) {
                [, [$id, $slug]] = $q;
                foreach ($this->sliders as $sid => $s) {
                    if ($sid === $id || $s['slug'] === $slug) {
                        return (object) ['name' => $s['name'], 'data' => json_encode($s['data'])];
                    }
                }
                return null;
            }
        };
    }

    protected function tearDown(): void {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    private function slider($id, $slides, $name = 'Home', $slug = 'home') {
        $this->sliders[$id] = ['name' => $name, 'slug' => $slug, 'data' => ['properties' => [], 'layers' => $slides]];
    }

    private function slide($link, $layers = [], $props = []) {
        return ['properties' => ['layer_link' => $link] + $props, 'sublayers' => $layers];
    }

    public function test_finds_slide_link_from_shortcode() {
        $this->slider(3, [$this->slide(''), $this->slide('https://www.familyrenewalproject.com/event/retreat/')]);
        $this->addPage(1, 'home', '[layerslider id="3"]');
        $hits = frp_watch_find([], [1 => '/']);
        $this->assertSame(['LayerSlider "Home", slide 2: Link'], $hits['retreat']['/']['how']);
    }

    public function test_avia_element_and_slug() {
        $this->slider(3, [$this->slide('/event/retreat/')]);
        $this->addPage(1, 'home', "[av_layerslider id='home' av_uid='av-1']");
        $this->assertArrayHasKey('retreat', frp_watch_find([], [1 => '/']));
    }

    public function test_block_editor_block() {
        $this->slider(3, [$this->slide('/event/retreat/')]);
        $this->addPage(1, 'home', '<!-- wp:kreatura/layerslider {"id":"3"} /-->');
        $this->assertArrayHasKey('retreat', frp_watch_find([], [1 => '/']));
    }

    public function test_layer_link_by_post_id_and_title() {
        $layer = ['linkId' => '100', 'linkType' => 'post', 'html' => '<h2>Prayer Night</h2>'];
        $this->slider(3, [$this->slide('', [$layer])]);
        $this->addPage(1, 'home', '[layerslider id="3"]');
        $hits = frp_watch_find(['prayer-night' => frp_watch_title_re('Prayer Night')], [1 => '/']);
        $this->assertArrayHasKey('lcw-marian-paint-by-number', $hits);
        $this->assertSame(['LayerSlider "Home", slide 1: Text (title)'], $hits['prayer-night']['/']['how']);
    }

    public function test_skips_hidden_slides_and_layers() {
        $this->slider(3, [
            $this->slide('/event/hidden-slide/', [], ['skip' => true]),
            $this->slide('', [['url' => '/event/hidden-layer/', 'skip' => 'true']]),
        ]);
        $this->addPage(1, 'home', '[layerslider id="3"]');
        $this->assertSame([], frp_watch_find([], [1 => '/']));
    }

    public function test_missing_slider_is_ignored() {
        $this->addPage(1, 'home', '[layerslider id="9"]');
        $this->assertSame([], frp_watch_find([], [1 => '/']));
    }
}
