<?php
namespace Vda\App;

use Vda\Config\IConfig;
use Vda\Http\Request;

class ApplicationContext
{
    private static $contextStack = array();

    /**
     * Application config
     * @var IConfig
     */
    private $config;
    private $frontend;
    private $request;

    /**
     * @return IConfig
     */
    public function getConfig()
    {
        return $this->config;
    }

    public function setConfig(IConfig $config)
    {
        $this->config = $config;
    }

    /**
     * Get frontend type, i.e. Application::FRONTEND_WEB
     */
    public function getFrontend()
    {
        return $this->frontend;
    }

    public function setFrontend($frontend)
    {
        $this->frontend = $frontend;
    }

    public function getRequest()
    {
        return $this->request;
    }

    public function setRequest(Request $request)
    {
        $this->request = $request;
    }

    /**
     * Push new context to the context stack
     *
     * @param ApplicationContext $ctx
     */
    public static function push(ApplicationContext $ctx)
    {
        array_push(self::$contextStack, $ctx);
    }

    /**
     * Pop current context one level up
     *
     * @return self previous context
     */
    public static function pop()
    {
        return array_pop(self::$contextStack);
    }

    /**
     * Get current application context
     *
     * @return self
     */
    public static function get()
    {
        return end(self::$contextStack);
    }
}
