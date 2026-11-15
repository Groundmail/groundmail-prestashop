<?php

use PHPUnit\Framework\TestCase;

final class ApiTest extends TestCase
{
    /**
     * @var array
     */
    private $requests = [];

    private function api(array $response)
    {
        return new GroundmailApi('groundmail-prestashop/test', function ($method, $url, $headers, $body) use ($response) {
            $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

            return $response;
        });
    }

    public function testSend()
    {
        $api = $this->api(['status' => 200, 'body' => '{"id":"msg_123","status":"queued"}']);

        $result = $api->send(['from' => ['email' => 'shop@shop.example'], 'to' => ['ada@example.com'], 'subject' => 'Commande n°1', 'text' => 'Hi'], 'gm_secret');

        $this->assertTrue($result['sent']);
        $this->assertSame('msg_123', $result['id']);
        $this->assertSame([], $result['held_back']);

        $request = $this->requests[0];
        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://api.groundmail.dev/emails', $request['url']);
        $this->assertContains('Authorization: Bearer gm_secret', $request['headers']);
        $this->assertContains('Content-Type: application/json', $request['headers']);
        $this->assertContains('User-Agent: groundmail-prestashop/test', $request['headers']);
        $this->assertSame('Commande n°1', json_decode($request['body'], true)['subject']);
        $this->assertStringContainsString('n°1', $request['body'], 'UTF-8 is sent as is');
    }

    public function testHeldBack()
    {
        $api = $this->api(['status' => 200, 'body' => '{"id":"msg_1","held_back":[{"email":"x@example.com","reason":"suppressed"}]}']);

        $result = $api->send(['to' => ['x@example.com', 'y@example.com']], 'k');

        $this->assertTrue($result['sent']);
        $this->assertSame([['email' => 'x@example.com', 'reason' => 'suppressed']], $result['held_back']);
    }

    public function testApiError()
    {
        $api = $this->api(['status' => 422, 'body' => '{"error":{"message":"The from domain is not verified","code":"domain_not_verified"}}']);

        $result = $api->send(['to' => ['a@example.com']], 'k');

        $this->assertFalse($result['sent']);
        $this->assertSame(422, $result['status']);
        $this->assertSame('The from domain is not verified (domain_not_verified)', $result['error']);
    }

    public function testErrorWithoutBody()
    {
        $api = $this->api(['status' => 502, 'body' => '<html>Bad gateway</html>']);

        $this->assertSame('Groundmail answered with HTTP 502.', $api->send(['to' => ['a@example.com']], 'k')['error']);
    }

    public function testNetworkError()
    {
        $api = $this->api(['status' => 0, 'body' => '', 'error' => 'Could not resolve host']);

        $result = $api->send(['to' => ['a@example.com']], 'k');

        $this->assertFalse($result['sent']);
        $this->assertSame('Could not reach Groundmail: Could not resolve host', $result['error']);
    }

    public function testUnencodableBody()
    {
        $result = $this->api(['status' => 200, 'body' => '{}'])->send(['subject' => "\xB1\x31"], 'k');

        $this->assertFalse($result['sent']);
        $this->assertSame([], $this->requests, 'nothing is sent');
    }

    /**
     * @dataProvider keyChecks
     */
    public function testCheckKey($status, $error, $expected)
    {
        $api = $this->api(['status' => $status, 'body' => '{}', 'error' => $error]);

        $this->assertSame($expected, $api->checkKey('gm_key'));
        $this->assertSame('GET', $this->requests[0]['method']);
        $this->assertSame('https://api.groundmail.dev/emails?limit=1', $this->requests[0]['url']);
    }

    public static function keyChecks()
    {
        return [
            'full access key' => [200, '', 'valid'],
            'send-only key' => [403, '', 'valid'],
            'wrong key' => [401, '', 'invalid'],
            'server error' => [500, '', 'unknown'],
            'network error' => [0, 'timeout', 'unknown'],
        ];
    }
}
