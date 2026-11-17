<?php


namespace Applab\Ooreedo\Soap\Services;


use Applab\Ooreedo\Soap\Client;
use Applab\Ooreedo\Soap\Models\SoapUser;
use Applab\Ooreedo\Soap\Requests\GetSmsStatusRequest;
use Applab\Ooreedo\Soap\Requests\SendSMSRequest;
use Applab\Ooreedo\Soap\Responses\GetSmsStatusResponse;
use Applab\Ooreedo\Soap\Responses\SendSMSResponse;
use Exception;
use GuzzleHttp\Client as HttpClient;
use InvalidArgumentException;
use RuntimeException;
use SoapFault;

class Message {
    private const CLASSMAP = ['SendSms'              => SendSMSRequest::class,
                              'SendSmsResponse'      => SendSMSResponse::class,
                              'GetSmsStatus'         => GetSmsStatusRequest::class,
                              'GetSmsStatusResponse' => GetSmsStatusResponse::class];

    private const HTTP_BASE_URL = 'https://messaging.ooredoo.qa/bms/Soap/Messenger.asmx/';

    private $soapUser = null;
    private $clients  = [];
    private $http;

    /**
     * Message constructor.
     *
     * @param SoapUser|null   $soapUser
     * @param HttpClient|null $http Guzzle client for the post/get protocols (pass one to mock in tests)
     */
    public function __construct(SoapUser $soapUser = null, HttpClient $http = null) {
        $this->soapUser = $soapUser;
        $this->http     = $http ?: new HttpClient(['timeout' => 15]);
    }

    /**
     * Calls an ASMX HTTP operation (HTTP_SendSms, HTTP_GetSmsStatus). Guzzle throws on a 4xx/5xx reply.
     *
     * @param string $operation
     * @param array  $params
     * @param string $httpMethod 'post' (form body) or 'get' (query string, password ends up in URL/logs)
     *
     * @return \SimpleXMLElement
     */
    private function httpCall($operation, array $params, $httpMethod) {
        $get      = strtolower($httpMethod) === 'get';
        $response = $this->http->request($get ? 'GET' : 'POST', self::HTTP_BASE_URL . $operation, [$get ? 'query' : 'form_params' => $params]);
        return simplexml_load_string((string) $response->getBody());
    }

    /**
     * SOAP client built on first use, so the HTTP protocols never touch the WSDL.
     *
     * @param string $protocol 'soap11' or 'soap12'
     *
     * @return Client
     * @throws SoapFault
     */
    private function soapClient($protocol) {
        if(!isset($this->clients[$protocol])) {
            $this->clients[$protocol] = new Client(['classmap'     => self::CLASSMAP,
                                                    'soap_version' => $protocol === 'soap11' ? SOAP_1_1 : SOAP_1_2]);
        }
        return $this->clients[$protocol];
    }

    /**
     * @param SendSMSRequest $sendSMSRequest
     * @param string         $protocol 'soap11' (default, as before) | 'soap12' | 'post' | 'get' (HTTP endpoints)
     *
     * @return SendSMSResponse
     * @throws Exception
     */
    public function sendSms(SendSMSRequest $sendSMSRequest, $protocol = 'soap11') {
        try {
            if(empty($sendSMSRequest->user) && empty($this->soapUser)) {
                throw new RuntimeException("Soap User Details not set");
            }
            if(empty($sendSMSRequest->user) && !empty($this->soapUser)) {
                $sendSMSRequest->user = $this->soapUser;
            }
            switch(strtolower($protocol)) {
                case 'post':
                case 'get':
                    return $this->sendSmsHttp($sendSMSRequest, $protocol);
                case 'soap11':
                case 'soap12':
                    return $this->soapClient(strtolower($protocol))->__soapCall("SendSms", ["SendSms" => $sendSMSRequest]);
                default:
                    throw new InvalidArgumentException("Unknown protocol '$protocol', use post, get, soap11 or soap12");
            }
        } catch(Exception $exception) {
            throw $exception;
        }
    }

    /**
     * Same as sendSms but over Ooredoo's HTTP endpoint instead of SOAP. Returns the same SendSMSResponse shape.
     *
     * @param SendSMSRequest $sendSMSRequest
     * @param string         $httpMethod 'post' (form body) or 'get' (query string, password ends up in URL/logs)
     *
     * @return SendSMSResponse
     */
    private function sendSmsHttp(SendSMSRequest $sendSMSRequest, $httpMethod = 'post') {
        $user   = $sendSMSRequest->user;
        $bool   = function($v) { return $v ? 'true' : 'false'; };
        $params = [
            'customerID'     => $user->CustomerID,
            'userName'       => $user->Name,
            'userPassword'   => $user->Password,
            'originator'     => $sendSMSRequest->originator,
            'smsText'        => $sendSMSRequest->smsText,
            'recipientPhone' => $sendSMSRequest->recipientPhone,
            'messageType'    => $sendSMSRequest->messageType,
            'defDate'        => (string) $sendSMSRequest->defDate,
            'blink'          => $bool($sendSMSRequest->blink),
            'flash'          => $bool($sendSMSRequest->flash),
            'Private'        => $bool($sendSMSRequest->Private),
        ];
        $xml = $this->httpCall('HTTP_SendSms', $params, $httpMethod);

        // Same shape as the SOAP response: $resp->TransactionID, $resp->NetPoints, $resp->RejectedNumbers->string
        // RejectedNumbers is an empty object when nothing is rejected, like SOAP; otherwise ->string is always
        // an array here (SOAP gives a plain string when there is only one).
        $result = new \stdClass();
        foreach($xml->children() as $name => $value) {
            if($name === 'RejectedNumbers' && !count($value->children())) {
                $result->{$name} = new \stdClass();
            } elseif(count($value->children())) {
                $result->{$name}         = new \stdClass();
                $result->{$name}->string = array_map('strval', iterator_to_array($value->children(), false));
            } else {
                $result->{$name} = (string) $value;
            }
        }
        $sendSmsResponse                = new SendSMSResponse();
        $sendSmsResponse->SendSmsResult = $result;
        return $sendSmsResponse;
    }
    /**
     * @param GetSmsStatusRequest $getSmsStatusRequest
     *
     * @param string              $protocol 'soap11' (default, as before) | 'soap12' | 'post' | 'get' (HTTP endpoints)
     *
     * @return GetSmsStatusResponse
     * @throws Exception
     */
    public function getSmsStatus(GetSmsStatusRequest $getSmsStatusRequest, $protocol = 'soap11') {
        try {
            if(empty($getSmsStatusRequest->user) && empty($this->soapUser)) {
                throw new RuntimeException("Soap User Details not set");
            }
            if(empty($getSmsStatusRequest->user) && !empty($this->soapUser)) {
                $getSmsStatusRequest->user = $this->soapUser;
            }
            switch(strtolower($protocol)) {
                case 'post':
                case 'get':
                    return $this->getSmsStatusHttp($getSmsStatusRequest, $protocol);
                case 'soap11':
                case 'soap12':
                    return $this->soapClient(strtolower($protocol))->__soapCall("GetSmsStatus", ["GetSmsStatus" => $getSmsStatusRequest]);
                default:
                    throw new InvalidArgumentException("Unknown protocol '$protocol', use post, get, soap11 or soap12");
            }
        } catch(Exception $exception) {
            throw $exception;
        }
    }

    /**
     * Same as getSmsStatus but over Ooredoo's HTTP endpoint instead of SOAP. Returns the same GetSmsStatusResponse shape.
     *
     * @param GetSmsStatusRequest $getSmsStatusRequest
     * @param string              $httpMethod 'post' (form body) or 'get' (query string, password ends up in URL/logs)
     *
     * @return GetSmsStatusResponse
     */
    private function getSmsStatusHttp(GetSmsStatusRequest $getSmsStatusRequest, $httpMethod = 'post') {
        $user   = $getSmsStatusRequest->user;
        $params = [
            'customerID'    => $user->CustomerID,
            'userName'      => $user->Name,
            'userPassword'  => $user->Password,
            'transactionID' => $getSmsStatusRequest->transactionID,
            'detailed'      => $getSmsStatusRequest->detailed ? 'true' : 'false',
        ];
        $xml = $this->httpCall('HTTP_GetSmsStatus', $params, $httpMethod);

        // Same shape as the SOAP response: Statistics->any / Details->any hold the inner XML, NetPoints is a string.
        $result = new \stdClass();
        foreach($xml->children() as $name => $value) {
            $inner = $value->children();
            if(count($inner)) {
                $result->{$name}      = new \stdClass();
                $result->{$name}->any = $inner[0]->asXML();
            } else {
                $result->{$name} = (string) $value;
            }
        }
        $getSmsStatusResponse                     = new GetSmsStatusResponse();
        $getSmsStatusResponse->GetSmsStatusResult = $result;
        return $getSmsStatusResponse;
    }
}
