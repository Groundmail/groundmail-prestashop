<?php

use PHPUnit\Framework\TestCase;

/**
 * The message PrestaShop 8 (SwiftMailer) or 9 (Symfony Mime) builds, mapped
 * to a POST /emails body.
 */
final class MessageTest extends TestCase
{
    public const PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

    public static function engines()
    {
        return [
            'PrestaShop 8 (SwiftMailer)' => [false],
            'PrestaShop 9 (Symfony Mime)' => [true],
        ];
    }

    private function requireEngine($symfony)
    {
        if ($symfony && PHP_VERSION_ID < 80100) {
            $this->markTestSkipped('PrestaShop 9 needs PHP 8.1 or later.');
        }
    }

    /**
     * An order confirmation as Mail::send() builds it: HTML and text, the
     * shop logo inline, the invoice PDF attached, a BCC and a Reply-To.
     *
     * @dataProvider engines
     */
    public function testOrderConfirmation($symfony)
    {
        $this->requireEngine($symfony);
        $logo = sys_get_temp_dir() . '/groundmail-test-logo-' . getmypid() . '.png';
        file_put_contents($logo, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));

        $native = new GroundmailNativeMessage($symfony);
        $native->addTo('ada@example.com', 'Ada Lovelace');
        $native->addBcc('orders@shop.example');
        $native->setSubject('Order confirmation');
        $native->setReplyTo('shop@shop.example', '');
        $cid = $native->embedLogo($logo);
        $native->setContent('<p>Thanks Ada</p><img src="' . $cid . '">', 'Thanks Ada');
        $native->attach(self::PDF, 'FA000001.pdf', 'application/pdf');
        $native->setFrom('shop@shop.example', 'My Shop');

        $body = GroundmailMessage::fromNative($native->message());
        unlink($logo);

        $this->assertSame(['email' => 'shop@shop.example', 'name' => 'My Shop'], $body['from']);
        $this->assertSame(['Ada Lovelace <ada@example.com>'], $body['to']);
        $this->assertSame(['orders@shop.example'], $body['bcc']);
        $this->assertArrayNotHasKey('cc', $body);
        $this->assertSame(['shop@shop.example'], $body['reply_to']);
        $this->assertSame('Order confirmation', $body['subject']);
        $this->assertStringStartsWith('<p>Thanks Ada</p>', $body['html']);
        $this->assertSame('Thanks Ada', $body['text']);
        $this->assertArrayNotHasKey('headers', $body, 'Message-ID, Date, MIME-Version and friends are left to the API');

        $this->assertCount(2, $body['attachments']);
        $byName = [];
        foreach ($body['attachments'] as $attachment) {
            $byName[$attachment['content_type']] = $attachment;
        }

        $pdf = $byName['application/pdf'];
        $this->assertSame('FA000001.pdf', $pdf['name']);
        $this->assertSame(self::PDF, base64_decode($pdf['data']));
        $this->assertArrayNotHasKey('content_id', $pdf);

        $image = $byName['image/png'];
        $this->assertNotEmpty($image['content_id']);
        $this->assertStringContainsString('cid:' . $image['content_id'], $body['html'], 'the HTML points at the inline logo');
    }

    /**
     * @dataProvider engines
     */
    public function testHtmlOnly($symfony)
    {
        $this->requireEngine($symfony);
        $native = new GroundmailNativeMessage($symfony);
        $native->addTo('ada@example.com', '');
        $native->setSubject('Password reset');
        $native->setContent('<p>Reset</p>', null);
        $native->setFrom('shop@shop.example', null);

        $body = GroundmailMessage::fromNative($native->message());

        $this->assertSame(['email' => 'shop@shop.example'], $body['from']);
        $this->assertSame(['ada@example.com'], $body['to']);
        $this->assertSame('<p>Reset</p>', $body['html']);
        $this->assertArrayNotHasKey('text', $body);
        $this->assertArrayNotHasKey('attachments', $body);
    }

    /**
     * @dataProvider engines
     */
    public function testTextOnly($symfony)
    {
        $this->requireEngine($symfony);
        $native = new GroundmailNativeMessage($symfony);
        $native->addTo('ada@example.com', 'Ada');
        $native->setSubject('Shipped');
        $native->setContent(null, 'On its way');
        $native->setFrom('shop@shop.example', 'Shop');

        $body = GroundmailMessage::fromNative($native->message());

        $this->assertSame('On its way', $body['text']);
        $this->assertArrayNotHasKey('html', $body);
    }

    /**
     * Several recipients, names that need quoting, and a module adding CC
     * and its own headers in actionMailAlterMessageBeforeSend.
     */
    public function testSwiftChangedByAModule()
    {
        $native = new GroundmailNativeMessage(false);
        $native->addTo('ada@example.com', 'Lovelace, Ada');
        $native->addTo('bob@example.com', '');
        $native->setSubject("Two\nlines");
        $native->setContent('<b>x</b>', 'x');
        $native->setFrom('shop@shop.example', 'Shop');

        $message = $native->message();
        $message->addCc('accounting@shop.example', 'Accounting');
        $message->getHeaders()->addTextHeader('X-Order-Reference', 'XKBKNABJK');
        $message->getHeaders()->addTextHeader('X-SES-Configuration-Set', 'nope');
        $message->getHeaders()->addTextHeader('X-Mailer', 'nope');

        $body = GroundmailMessage::fromNative($message);

        $this->assertSame(['"Lovelace, Ada" <ada@example.com>', 'bob@example.com'], $body['to']);
        $this->assertSame(['Accounting <accounting@shop.example>'], $body['cc']);
        $this->assertSame('Two lines', $body['subject']);
        $this->assertSame(['X-Order-Reference' => 'XKBKNABJK'], $body['headers']);
    }

    public function testSymfonyChangedByAModule()
    {
        $this->requireEngine(true);
        $native = new GroundmailNativeMessage(true);
        $native->addTo('ada@example.com', 'Ada');
        $native->setSubject('Hello');
        $native->setContent('<b>x</b>', 'x');
        $native->setFrom('shop@shop.example', 'Shop');

        $message = $native->message();
        $message->addCc(new Symfony\Component\Mime\Address('accounting@shop.example', 'Accounting'));
        $message->getHeaders()->addTextHeader('X-Order-Reference', 'XKBKNABJK');
        $message->getHeaders()->addTextHeader('X-Mailer', 'Some module');
        $message->getHeaders()->addTextHeader('Auto-Submitted', 'auto-generated');

        $body = GroundmailMessage::fromNative($message);

        $this->assertSame(['Accounting <accounting@shop.example>'], $body['cc']);
        $this->assertSame(['X-Order-Reference' => 'XKBKNABJK', 'Auto-Submitted' => 'auto-generated'], $body['headers']);
    }

    public function testUnsupportedMessage()
    {
        $this->expectException(InvalidArgumentException::class);
        GroundmailMessage::fromNative(new stdClass());
    }

    public function testSenderOverrideKeepsRepliesWithTheShop()
    {
        $body = [
            'from' => ['email' => 'shop@gmail.example', 'name' => 'My Shop'],
            'to' => ['ada@example.com'],
        ];

        $body = GroundmailMessage::overrideSender($body, 'orders@myshop.example', '');

        $this->assertSame(['email' => 'orders@myshop.example', 'name' => 'My Shop'], $body['from']);
        $this->assertSame(['My Shop <shop@gmail.example>'], $body['reply_to']);
    }

    public function testSenderOverrideLeavesAnExistingReplyTo()
    {
        $body = [
            'from' => ['email' => 'shop@gmail.example'],
            'to' => ['shop@gmail.example'],
            'reply_to' => ['customer@example.com'],
        ];

        $body = GroundmailMessage::overrideSender($body, 'orders@myshop.example', 'My Shop');

        $this->assertSame(['email' => 'orders@myshop.example', 'name' => 'My Shop'], $body['from']);
        $this->assertSame(['customer@example.com'], $body['reply_to'], 'a contact form reply still goes to the customer');
    }

    public function testNoOverride()
    {
        $body = ['from' => ['email' => 'shop@shop.example', 'name' => 'Shop'], 'to' => ['a@example.com']];

        $this->assertSame($body, GroundmailMessage::overrideSender($body, '', ''));
        $this->assertSame($body, GroundmailMessage::overrideSender($body, 'SHOP@shop.example', ''));
    }

    public function testRecipients()
    {
        $this->assertSame(
            ['a@example.com', 'B <b@example.com>', 'c@example.com'],
            GroundmailMessage::recipients(['to' => ['a@example.com'], 'cc' => ['B <b@example.com>'], 'bcc' => ['c@example.com']])
        );
    }

    public function testCustomHeaders()
    {
        $this->assertSame(
            ['X-Ok' => 'value', 'List-Unsubscribe' => '<mailto:u@example.com>'],
            GroundmailMessage::customHeaders([
                ['X-Ok', "value\r\n"],
                ['Bad Name', 'x'],
                ['Content-Type', 'text/html'],
                ['message-id', '<x@y>'],
                ['X-Empty', ''],
                ['List-Unsubscribe', '<mailto:u@example.com>'],
            ])
        );
    }
}
