<?php
namespace Vda\App;

use Vda\Cli\ICommand;
use Vda\Config\IConfig;
use Vda\Config\Loader\IConfigLoader;
use Vda\Config\Loader\PhpUnmodifiableConfigLoader;
use Vda\DependencyInjection\Container;
use Vda\DependencyInjection\DI;
use Vda\Http\Response;
use Vda\Http\Request;
use Vda\Ui\Message\IMessage;
use Vda\Ui\Message\DictionaryMessage as M;

abstract class Application
{
    const FRONTEND_CLI = 0;
    const FRONTEND_WEB = 1;

    /**
     * @var IConfigLoader
     */
    protected $configLoader;
    /**
     * @var IConfig
     */
    protected $config;
    protected $appRoot;
    protected $requestDispatchers;
    protected $exceptionHandlers;

    public function __construct($appRoot, IConfigLoader $loader = null)
    {
        $this->appRoot = $appRoot;
        $this->configLoader = $loader ?: new PhpUnmodifiableConfigLoader(
            array('site.inc', 'default.inc'),
            $appRoot . '/config'
        );
        $this->requestDispatchers = array(
            self::FRONTEND_CLI => array($this, 'dispatchCliRequest'),
            self::FRONTEND_WEB => array($this, 'dispatchWebRequest'),
        );
        $this->exceptionHandlers = array(
            self::FRONTEND_CLI => array($this, 'handleException'),
            self::FRONTEND_WEB => array($this, 'handleException'),
        );
    }

    /**
     * Override default request dispatcher for certain frontend
     *
     * @param integer $frontend
     * @param callable $dispatcher
     * @throws \InvalidArgumentException
     */
    public function setRequestDispatcher($frontend, $dispatcher)
    {
        //FIXME Once 5.3 support dropped replaces this with callable typehint
        if (!is_callable($dispatcher)) {
            throw new \InvalidArgumentException('Dispatcher must be valid callback');
        }

        $this->requestDispatchers[$frontend] = $dispatcher;
    }

    /**
     * Override default exception handler for certain frontend
     *
     * @param integer $frontend
     * @param callable $handler
     * @throws \InvalidArgumentException
     */
    public function setExceptionHandler($frontend, $handler)
    {
        //FIXME Once 5.3 support dropped replaces this with callable typehint
        if (!is_callable($handler)) {
            throw new \InvalidArgumentException('Handler must be valid callback');
        }

        $this->exceptionHandlers[$frontend] = $handler;
    }

    public function run()
    {
        try {
            $this->bootstrap();
            $this->dispatch();
        } catch (\Exception $e) {
            $f = ApplicationContext::get()->getFrontend();

            if (empty($this->exceptionHandlers[$f])) {
                $f = self::FRONTEND_CLI;
            }

            call_user_func($this->exceptionHandlers[$f], $e);
        }
    }

    protected function bootstrap()
    {
        $this->config = $this->configLoader->load();
        $ctx = new ApplicationContext();

        $ctx->setConfig($this->config);

        if (empty($_SERVER['REQUEST_METHOD'])) {
            $ctx->setFrontend(self::FRONTEND_CLI);
        } else {
            $ctx->setFrontend(self::FRONTEND_WEB);
            $ctx->setRequest(Request::createFromGlobals());
        }

        ApplicationContext::push($ctx);

        $this->initDependencyInjector();
        $this->initUiMessages();
    }

    protected function dispatch()
    {
        $f = ApplicationContext::get()->getFrontend();

        if (empty($this->requestDispatchers[$f])) {
            $f = self::FRONTEND_CLI;
        }

        call_user_func($this->requestDispatchers[$f]);
    }

    protected function initDependencyInjector()
    {
        if ($this->config->hasParam('dependency-injection/beans')) {
            DI::init(new Container($this->config->get('dependency-injection/beans')));
        }
    }

    protected function initUiMessages()
    {
        if ($this->config->hasParam('i18n/dict/en')) {
            M::setGlobalDict($this->config->get('i18n/dict/en'));
        } elseif ($this->config->hasParam('i18n/di-lookup/bean-name')) {
            M::setGlobalDict(DI::get($this->config->get('i18n/di-lookup/bean-name')));
        }
    }

    /**
     * Handle request.
     *
     * Override this using vda\mvc.
     *
     */
    abstract protected function dispatchWebRequest();

    protected function dispatchCliRequest()
    {
        $commands = array(
            pathinfo($_SERVER['argv'][0], PATHINFO_BASENAME),
            pathinfo($_SERVER['argv'][0], PATHINFO_FILENAME),
        );

        $commands = array_map(function($command) {
            // remove underscores because they are namespace separators
            $command = str_replace('_', '', $command);
            // move digits to end of string, because class name derived from this string
            return preg_replace('~^([^a-z_]+)(.+)$~i', '$2$1', $command);
        }, $commands);

        $map = $this->config->getArray('cli/command-map');
        $ns = $this->config->getArray('cli/class-lookup/namespace', array('\Cli\Command'));
        $classPrefix = $this->config->get('cli/class-lookup/prefix', '');
        $classSuffix = $this->config->get('cli/class-lookup/suffix', 'Command');
        $isDiEnabled = $this->config->getBool('cli/di-lookup/enable');
        $diPrefix = $this->config->get('cli/di-lookup/prefix', '');
        $diSuffix = $this->config->get('cli/di-lookup/suffix', '');
        $command = null;

        foreach ($commands as $c) {
            if (array_key_exists($c, $map)) {
                $command = is_callable($map[$c]) ? $map[$c] : new $map[$c]();
            } elseif ($isDiEnabled && DI::hasBean($diPrefix . $c . $diSuffix)) {
                $command = DI::get($diPrefix . $c . $diSuffix);
            } else {
                $c = str_replace(' ', '', ucwords(str_replace('-', ' ', $c)));

                foreach ($ns as $namespace) {
                    $class = $namespace . '\\' . $classPrefix . $c . $classSuffix;
                    if (class_exists($class)) {
                        $command = new $class();
                        break;
                    }
                }
            }

            if (is_null($command)) {
                continue;
            } elseif (is_callable($command)) {
                return call_user_func($command, $_SERVER['argv']);
            } elseif ($command instanceof ICommand) {
                return $command->execute($_SERVER['argv']);
            } else {
                throw new ClientException(
                    "Invalid handler '{$command}' for command {$c}",
                    new M('error.cli.invalid-command', array('command' => $c)),
                    null,
                    126 //not executable
                );
            }
        }

        throw new ClientException(
            "Command not found: " . basename($_SERVER['argv'][0]),
            new M("error.cli.command-not-found", array('command' => basename($_SERVER['argv'][0]))),
            null,
            127 //not found
        );
    }

    protected function handleException(\Exception $e)
    {
        switch (ApplicationContext::get()->getFrontend()) {
            case self::FRONTEND_WEB:
                header(Response::getStatusLine(
                    Response::isValidStatus($e->getCode()) ? $e->getCode() : 500
                ));

                echo $this->getClientMessage($e), "<br/>\n";

                if ($this->config->getBool('debug/enable')) {
                    echo nl2br($this->renderException($e));
                }

                exit();
            default:
                echo $this->getClientMessage($e), "\n";

                if ($this->config->getBool('debug/enable')) {
                    echo $this->renderException($e);
                }

                exit($e->getCode() ?: 1);
        }
    }

    private function getClientMessage(\Exception $e)
    {
        if ($e instanceof ClientException) {
            $msg = $e->getClientMessage();
        } else {
            $msg = 'error.unexpected-error';
        }

        if (!($msg instanceof IMessage)) {
            $msg = new M($msg);
        }

        return $msg;
    }

    private function renderException(\Exception $e)
    {
        $result = '';
        $prefix = 'Exception';

        while (!is_null($e)) {
            $result .= "{$prefix} '{$e->getMessage()}' thrown from {$e->getFile()}@{$e->getLine()}\n";
            $result .= "Stacktrace: {$e->getTraceAsString()}\n";
            $prefix = 'Caused by';
            $e = $e->getPrevious();
        }

        return $result;
    }
}
