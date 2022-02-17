<?php


namespace Applab\Ooreedo\Soap\Requests;

class SendSMSRequest {
    public $user           = null;
    public $originator     = null;
    public $smsText        = null;
    public $recipientPhone = null;
    public $messageType    = "Latin";
    public $defDate        = null;
    public $blink          = false;
    public $flash          = false;
    public $Private        = false;

    public function __construct($arabic = false) {
        if($arabic) {
            $this->messageType = "ArabicWithLatinNumbers";
        }
    }
}
