<?php

use Brain\Monkey\Functions;

/** frp_watch_parse_emails, frp_watch_recipients and sending to several addresses. */
class RecipientsTest extends FrpTestCase {
    public function test_parses_a_list() {
        $this->assertSame([['a@example.com', 'b@example.org', 'c@example.net'], []],
                          frp_watch_parse_emails(" a@example.com, b@example.org;c@example.net\n"));
    }

    public function test_drops_repeats_and_reports_entries_that_are_not_addresses() {
        $this->assertSame([['a@example.com'], ['not-an-email']],
                          frp_watch_parse_emails('a@example.com, not-an-email, A@Example.com'));
    }

    public function test_empty() {
        $this->assertSame([[], []], frp_watch_parse_emails(''));
        $this->assertSame([[], []], frp_watch_parse_emails(false));
    }

    public function test_recipients_fall_back_to_the_admin_email() {
        $this->options = ['admin_email' => 'admin@example.com'];
        $this->assertSame(['admin@example.com'], frp_watch_recipients());
        $this->options['frp_watch_mail_to'] = 'a@example.com, b@example.org';
        $this->assertSame(['a@example.com', 'b@example.org'], frp_watch_recipients());
    }

    public function test_resend_gets_every_recipient() {
        $this->options = ['frp_watch_resend_key' => 're_test'];
        $sent = null;
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('wp_remote_post')->alias(function ($url, $args) use (&$sent) {
            $sent = json_decode($args['body'], true);
            return [];
        });
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);

        $this->assertTrue(frp_watch_mail(['a@example.com', 'b@example.org'], 'Hi', 'Text'));
        $this->assertSame(['a@example.com', 'b@example.org'], $sent['to']);
    }

    public function test_wp_mail_gets_every_recipient() {
        $to = null;
        Functions\when('wp_mail')->alias(function ($recipients) use (&$to) {
            $to = $recipients;
            return true;
        });
        $this->assertTrue(frp_watch_mail(['a@example.com', 'b@example.org'], 'Hi', 'Text'));
        $this->assertSame(['a@example.com', 'b@example.org'], $to);
    }
}
