<?php

namespace Applab\Ooreedo\Tests;

use Applab\Ooreedo\Messenger;
use Applab\Ooreedo\Soap\Models\SoapUser;
use Applab\Ooreedo\Soap\Requests\GetSmsStatusRequest;
use Applab\Ooreedo\Soap\Requests\SendSMSRequest;
use Applab\Ooreedo\Soap\Services\Message;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * HTTP protocols only, all responses mocked with Guzzle: no request reaches Ooredoo.
 * The SOAP protocols need the live WSDL, so they are not covered here.
 */
class MessageTest extends TestCase {
    private const SEND_RESULT = '<?xml version="1.0" encoding="utf-8"?>
        <SendResult xmlns="http://pmmsoapmessenger.com/">
          <RejectedNumbers><string>97411111111</string><string>97422222222</string></RejectedNumbers>
          <TransactionID>TX-1</TransactionID>
          <NetPoints>42</NetPoints>
        </SendResult>';

    private const SMS_STATUS = '<?xml version="1.0" encoding="utf-8"?>
        <SmsStatus xmlns="http://pmmsoapmessenger.com/">
          <Statistics><Stats><Sent>2</Sent><Delivered>1</Delivered></Stats></Statistics>
          <Details><List><Row>97411111111</Row></List></Details>
          <NetPoints>40</NetPoints>
        </SmsStatus>';

    /** @var RequestInterface[] requests the code sent, in order */
    private $sent = [];

    /** Guzzle client that answers with $responses in order and records every request in $this->sent. */
    private function http(Response ...$responses) {
        $this->sent = [];
        $stack      = HandlerStack::create(new MockHandler($responses));
        $history    = [];
        $stack->push(Middleware::history($history));
        $this->sent = &$history;
        return new Client(['handler' => $stack]);
    }

    private function message(Response ...$responses) {
        return new Message(new SoapUser('CID', 'user', 'secret', 'en'), $this->http(...$responses));
    }

    private function sendRequest() {
        $request                 = new SendSMSRequest();
        $request->originator     = 'AppLab';
        $request->smsText        = 'Hello';
        $request->recipientPhone = '97411111111';
        return $request;
    }

    /** @return array the fields of the n-th sent request, from the form body (POST) or query string (GET) */
    private function sentFields($n = 0) {
        $request = $this->sent[$n]['request'];
        parse_str($request->getMethod() === 'GET' ? $request->getUri()->getQuery() : (string) $request->getBody(), $fields);
        return $fields;
    }

    public function test_send_sms_over_post_parses_response() {
        $response = $this->message(new Response(200, [], self::SEND_RESULT))->sendSms($this->sendRequest(), 'post');

        $this->assertSame('TX-1', $response->TransactionID);
        $this->assertSame('42', $response->NetPoints);
        $this->assertSame(['97411111111', '97422222222'], $response->RejectedNumbers->string);
        $this->assertTrue(isset($response->TransactionID));
        $this->assertNotEmpty($response->TransactionID);
        $this->assertNull($response->Missing ?? null);

        $request = $this->sent[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/bms/Soap/Messenger.asmx/HTTP_SendSms', $request->getUri()->getPath());
        $this->assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        $fields = $this->sentFields();
        $this->assertSame('CID', $fields['customerID']);
        $this->assertSame('secret', $fields['userPassword']);
        $this->assertSame('97411111111', $fields['recipientPhone']);
        $this->assertSame('Latin', $fields['messageType']);
        $this->assertSame('false', $fields['flash']);
    }

    public function test_no_rejected_numbers_gives_empty_object_like_soap() {
        $xml      = '<SendResult xmlns="http://pmmsoapmessenger.com/"><Result>OK</Result><RejectedNumbers /><TransactionID>TX-2</TransactionID></SendResult>';
        $response = $this->message(new Response(200, [], $xml))->sendSms($this->sendRequest(), 'post');

        $this->assertEquals(new \stdClass(), $response->RejectedNumbers);
        $this->assertSame('OK', $response->Result);
    }

    public function test_send_sms_over_get_puts_fields_in_query_string() {
        $response = $this->message(new Response(200, [], self::SEND_RESULT))->sendSms($this->sendRequest(), 'GET');

        $this->assertSame('TX-1', $response->TransactionID);
        $this->assertSame('GET', $this->sent[0]['request']->getMethod());
        $this->assertSame('Hello', $this->sentFields()['smsText']);
        $this->assertSame('CID', $this->sentFields()['customerID']);
    }

    public function test_get_sms_status_over_post_matches_soap_shape() {
        $response = $this->message(new Response(200, [], self::SMS_STATUS))->getSmsStatus(new GetSmsStatusRequest('TX-1'), 'post');

        $this->assertSame(['Sent' => '2', 'Delivered' => '1'], $response->getStatistics()->toArray());
        $this->assertSame(['Row' => '97411111111'], $response->getDetails()->toArray());
        $this->assertSame('40', $response->NetPoints);
        $this->assertSame('/bms/Soap/Messenger.asmx/HTTP_GetSmsStatus', $this->sent[0]['request']->getUri()->getPath());
        $this->assertSame('TX-1', $this->sentFields()['transactionID']);
        $this->assertSame('true', $this->sentFields()['detailed']);
    }

    public function test_messenger_passes_protocol_and_detailed_flag() {
        $http      = $this->http(new Response(200, [], self::SEND_RESULT), new Response(200, [], self::SMS_STATUS));
        $messenger = new Messenger('CID', 'user', 'secret', 'AppLab', 'en', 'get', $http);

        $this->assertSame('TX-1', $messenger->sendSMS('Hello', '97411111111')->TransactionID);
        $messenger->getSMSStatus('TX-1', false);

        $this->assertSame('GET', $this->sent[1]['request']->getMethod());
        $this->assertSame('false', $this->sentFields(1)['detailed']);
    }

    public function test_unknown_protocol_throws() {
        $this->expectException(InvalidArgumentException::class);
        $this->message()->sendSms($this->sendRequest(), 'ftp');
    }

    public function test_missing_user_throws() {
        $this->expectException(RuntimeException::class);
        (new Message(null, $this->http()))->sendSms($this->sendRequest(), 'post');
    }

    public function test_http_error_is_thrown() {
        $this->expectException(ServerException::class);
        $this->message(new Response(500, [], 'Server error'))->sendSms($this->sendRequest(), 'post');
    }
}
