<?php


namespace Applab\Ooreedo\Soap\Responses;


use Applab\Ooreedo\Soap\Models\SendSmsResult;
use RuntimeException;

class SendSMSResponse {
    public $SendSmsResult;

    public function __get($name) {
        return $this->SendSmsResult->{$name};
    }

    /** Lets isset()/empty()/?? see the result fields that __get exposes. */
    public function __isset($name) {
        return isset($this->SendSmsResult->{$name});
    }
}
