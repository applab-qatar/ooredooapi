<?php


namespace Applab\Ooreedo\Soap;

use SoapClient;
use SoapFault;


class Client extends SoapClient {
    private $wsdl    = "https://messaging.ooredoo.qa/bms/soap/Messenger.asmx?wsdl";
    // The WSDL advertises http://, but Ooredoo only accepts HTTPS (port 80 is refused), so override the endpoint.
    private $options = ['trace' => true, 'exceptions' => true, 'location' => 'https://messaging.ooredoo.qa/bms/Soap/Messenger.asmx'];

    /**
     * Client constructor.
     *
     * @param array|null $options
     *
     * @throws SoapFault
     */
    public function __construct(array $options = []) {
        parent::__construct($this->wsdl, array_merge($this->options, $options));
    }
}
