<p align="center"><a href="https://applab.qa" target="_blank"><img src="https://applab.qa/wp-content/uploads/2020/11/page-logo.svg" width="400"></a></p>

<p align="center"></p>

# ooredooapi
PHP package for sending SMS through the Ooredoo Qatar Messenger web service (`messaging.ooredoo.qa`).

## Requirements
- PHP 7.2.5+ with Guzzle 7 (already installed in any Laravel app); no framework needed
- `ext-xml`; `ext-soap` for the SOAP protocols (including the default)

`composer.json` allows `^7.2.5|^8.0`. When PHP 9 is released, run `composer test` on it and then add `|^9.0`. Don't add it before it is tested: a major version can remove features the package uses.

## Usage

```php
use Applab\Ooreedo\Messenger;

$messenger = new Messenger($customerID, $userName, $password, $originator);

// Send an SMS
$response = $messenger->sendSMS('Hello from AppLab', '974XXXXXXXX');
$response->TransactionID;
$response->NetPoints;

// Arabic text
$messenger->sendSMS('مرحبا', '974XXXXXXXX', null, true);

// Scheduled delivery
$messenger->sendSMS('Reminder', '974XXXXXXXX', '2026-12-01 09:00');

// Check delivery status
$status = $messenger->getSMSStatus($response->TransactionID);
$status->getStatistics()->toArray();
$status->getDetails()->toArray();   // only when detailed = true (default)
$status->NetPoints;

// Statistics only
$messenger->getSMSStatus($response->TransactionID, false);
```

## Protocols

Ooredoo exposes the same operations over four bindings. Pick one with the last constructor argument. The default is `soap11`, which is what earlier versions always used, so existing installs behave the same until they opt in:

| Protocol | Transport |
|---|---|
| `soap11` | SOAP 1.1 (default) |
| `soap12` | SOAP 1.2 |
| `post` | HTTP POST form, no ext-soap or WSDL needed |
| `get` | HTTP GET query string. Fallback only: the password ends up in the URL and server logs |

```php
$messenger = new Messenger($customerID, $userName, $password, $originator, 'en', 'post');
```

The WSDL is only downloaded when a SOAP protocol is used, so `post`/`get` keep working if SOAP is blocked.

## Responses

`sendSMS()` returns `SendSMSResponse`; read fields directly (`->TransactionID`, `->NetPoints`).
Over HTTP, `->RejectedNumbers->string` is always an array; over SOAP it is a plain string when a single number is rejected.

`getSMSStatus()` returns `GetSmsStatusResponse` with `getStatistics()`, `getDetails()` (both `XmlElement`, with `toArray()`/`toJson()`) and `->NetPoints`.

## Lower-level usage

```php
use Applab\Ooreedo\Soap\Models\SoapUser;
use Applab\Ooreedo\Soap\Requests\SendSMSRequest;
use Applab\Ooreedo\Soap\Services\Message;

$message = new Message(new SoapUser($customerID, $userName, $password, 'en'));

$request                 = new SendSMSRequest();
$request->originator     = $originator;
$request->smsText        = 'Hello';
$request->recipientPhone = '974XXXXXXXX';
$request->flash          = true;

$message->sendSms($request, 'soap12');
```

## Tests

```bash
composer test
```

Covers the `post`/`get` protocols with mocked responses; nothing is sent to Ooredoo. The SOAP protocols need the live WSDL and are not covered.
