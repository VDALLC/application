<?php
namespace Vda\App;

class ClientException extends \RuntimeException
{
    private $clientMessage;

    public function __construct($message, $clientMessage, \Exception $cause = null, $code = 0)
    {
        parent::__construct($message, $code, $cause);

        $this->clientMessage = $clientMessage;
    }

    public function getClientMessage()
    {
        return $this->clientMessage;
    }

    public function setClientMessage($clientMessage)
    {
        $this->clientMessage = $clientMessage;
    }
}
