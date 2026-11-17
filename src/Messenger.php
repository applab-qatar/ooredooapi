<?php


namespace Applab\Ooreedo;


use Applab\Ooreedo\Soap\Models\SoapUser;
use Applab\Ooreedo\Soap\Requests\GetSmsStatusRequest;
use Applab\Ooreedo\Soap\Requests\SendSMSRequest;
use Applab\Ooreedo\Soap\Services\Message;
use Exception;
use SoapFault;

class Messenger {
    private $soapUser = null;
    private $originator;
    private $protocol;
    private $http;

    /**
     * Messenger constructor.
     *
     * @param        $customerID
     * @param        $name
     * @param        $password
     * @param        $originator
     * @param string $language
     * @param string $protocol 'soap11' (default, as before) | 'soap12' | 'post' | 'get'
     * @param \GuzzleHttp\Client|null $http Guzzle client for post/get (pass one to mock in tests)
     */
    public function __construct($customerID, $name, $password, $originator, $language = 'en', $protocol = 'soap11', $http = null) {
        $this->soapUser   = new SoapUser($customerID, $name, $password, $language);
        $this->originator = $originator;
        $this->protocol   = $protocol;
        $this->http       = $http;
    }

    /**
     * @param       $smsText
     * @param       $recipientPhone
     * @param null  $deliveryTime
     * @param false $arabic
     *
     * @return mixed
     * @throws Exception|SoapFault
     */
    public function sendSMS($smsText, $recipientPhone, $deliveryTime = null, $arabic = false) {
        try {
            $message                        = new Message($this->soapUser, $this->http);
            $sendSmsRequest                 = new SendSMSRequest($arabic);
            $sendSmsRequest->smsText        = $smsText;
            $sendSmsRequest->originator     = $this->originator;
            $sendSmsRequest->recipientPhone = $recipientPhone;
            $sendSmsRequest->defDate        = $deliveryTime;
            return $message->sendSms($sendSmsRequest, $this->protocol);
        } catch(Exception $exception) {
            throw $exception;
        }
    }

    /**
     * @param      $transactionID
     * @param bool $detailed
     *
     * @return mixed
     * @throws Exception|SoapFault
     */
    public function getSMSStatus($transactionID, $detailed = true) {
        try {
            $message             = new Message($this->soapUser, $this->http);
            $getSmsStatusRequest = new GetSmsStatusRequest($transactionID);
            $getSmsStatusRequest->detailed = $detailed;
            return $message->getSmsStatus($getSmsStatusRequest, $this->protocol);
        } catch(Exception $exception) {
            throw $exception;
        }
    }
}
