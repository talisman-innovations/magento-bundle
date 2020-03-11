<?php

/**
 * @project Magento Bridge for Symfony 2.
 *
 * @author  Sébastien MALOT <sebastien@malot.fr>
 * @license MIT
 * @url     <https://github.com/smalot/magento-bundle>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Smalot\MagentoBundle\Adapter;

use Exception;
use Psr\Log\LoggerInterface;
use Smalot\Magento\ActionInterface;
use Smalot\Magento\MultiCallQueueInterface;
use Smalot\Magento\RemoteAdapter as BaseRemoteAdapter;
use Smalot\Magento\RemoteAdapterException;
use Smalot\MagentoBundle\Event\MultiCallTransportEvent;
use Smalot\MagentoBundle\Event\SecurityEvent;
use Smalot\MagentoBundle\Event\SingleCallTransportEvent;
use Smalot\MagentoBundle\MagentoException;
use Smalot\MagentoBundle\MagentoEvents;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use ConnectorSupport\Curl\Logger;

/**
 * Class RemoteAdapter
 *
 * @package Smalot\MagentoBundle\Adapter
 */
class RemoteAdapter extends BaseRemoteAdapter
{
    /**
     * @var string
     */
    protected $connection;

    /**
     * @var EventDispatcherInterface
     */
    protected $dispatcher;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @param string $connection
     * @param string $path
     * @param string $apiUser
     * @param string $apiKey
     * @param array $options
     * @param bool $autoLogin
     */
    public function __construct($connection, $path, $apiUser, $apiKey, $options = array(), $autoLogin = true)
    {
        $this->connection = $connection;

        parent::__construct($path, $apiUser, $apiKey, $options, $autoLogin);
    }

    /**
     * @param EventDispatcherInterface $dispatcher
     *
     * @return RemoteAdapter
     */
    public function setDispatcher(EventDispatcherInterface $dispatcher)
    {
        $this->dispatcher = $dispatcher;

        return $this;
    }

    /**
     * @param LoggerInterface $logger
     *
     * @return RemoteAdapter
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = new Logger($logger);

        return $this;
    }

    /**
     * @param string $apiUser
     * @param string $apiKey
     *
     * @return bool
     * @throws Exception
     */
    public function login($apiUser = null, $apiKey = null)
    {
        $apiUser = (null === $apiUser ? $this->apiUser : $apiUser);
        $apiKey = (null === $apiKey ? $this->apiKey : $apiKey);

        $event = new SecurityEvent($this, $apiUser, $apiKey);
        $this->dispatcher->dispatch($event, MagentoEvents::PRE_LOGIN);

        // Retrieve ApiUser and ApiKey from SecurityEvent to allow override mechanism.
        $apiUser = $event->getApiUser();
        $apiKey = $event->getApiKey();

        $this->sessionId = $this->soapClient->login($apiUser, $apiKey);

        $event = new SecurityEvent($this, $apiUser, $apiKey, $this->sessionId);
        $this->dispatcher->dispatch($event, MagentoEvents::POST_LOGIN);

        return isset($this->sessionId);
    }

    /**
     * @return bool
     */
    public function logout()
    {
        $event = new SecurityEvent($this, null, null, $this->sessionId);
        $this->dispatcher->dispatch($event, MagentoEvents::PRE_LOGOUT);

        if ($this->sessionId === null) {
            return false;
        }

        $this->soapClient->endSession($this->sessionId);

        $event = new SecurityEvent($this, null, null, $this->sessionId);
        $this->dispatcher->dispatch($event, MagentoEvents::POST_LOGOUT);

        $this->sessionId = null;
        return true;
    }

    /**
     * @param ActionInterface $action
     * @param bool $throwsException
     *
     * @return array|null
     * @throws MagentoException
     */
    public function call(ActionInterface $action, $throwsException = true)
    {
        try {
            if (is_null($this->sessionId) && $this->autoLogin) {
                $this->login();
            }

            if (is_null($this->sessionId)) {
                throw new MagentoException('Not connected.');
            }

            $event = new SingleCallTransportEvent($this, $action);
            $this->dispatcher->dispatch($event, MagentoEvents::PRE_SINGLE_CALL);
            $action = $event->getAction();

            $result = $this->soapClient->call($this->sessionId, $action->getMethod(), $action->getArguments());
            $this->logCall($action);

            $event = new SingleCallTransportEvent($this, $action, $result);
            $this->dispatcher->dispatch($event, MagentoEvents::POST_SINGLE_CALL);
            $result = $event->getResult();

            return $result;

        } catch (MagentoException $e) {
            if ($throwsException) {
                throw $e;
            }

            return null;
        }
    }

    /**
     * @param MultiCallQueueInterface $queue
     * @param bool $throwsException
     *
     * @return array
     * @throws MagentoException
     * @throws RemoteAdapterException
     */
    public function multiCall(MultiCallQueueInterface $queue, $throwsException = false)
    {
        try {
            $this->checkSecurity();

            $event = new MultiCallTransportEvent($this, $queue);
            $this->dispatcher->dispatch($event, MagentoEvents::PRE_MULTI_CALL);
            $queue = $event->getQueue();

            $actions = $this->getActions($queue);

            $results = $this->soapClient->multiCall($this->sessionId, $actions);

            $event = new MultiCallTransportEvent($this, $queue, $results);
            $this->dispatcher->dispatch($event, MagentoEvents::POST_MULTI_CALL);
            $queue = $event->getQueue();
            $results = $event->getResults();

            $this->handleCallbacks($queue, $results);

            return $results;

        } catch (MagentoException $e) {
            return array();
        }
    }

    /**
     * @param ActionInterface $action
     */
    protected function logCall(ActionInterface $action)
    {
        $bodySent = $this->soapClient->__getLastRequest();
        $headersSent = $this->soapClient->__getLastRequestHeaders();
        $headers = explode("\r\n", $headersSent);
        $temp = explode(" ", array_shift($headers));
        $method = $temp[0];
        $url = $temp[1];
        $sent = $this->parseHeaders($headers);

        if (array_key_exists('Host', $sent)) {
            $url = $sent['Host'] . $url;
        }

        $bodyRecv = $this->soapClient->__getLastResponse();
        $headersRecv = $this->soapClient->__getLastResponseHeaders();
        $headers = explode("\r\n", $headersRecv);
        $temp = explode(" ", array_shift($headers));
        $http_code = $temp[1];
        $recv = $this->parseHeaders($headers);

        $this->logger->log($method, $url, null, $sent, $bodySent, $http_code, $recv, $bodyRecv);
    }

    /**
     * @param array $headers
     * @return array
     */
    protected function parseHeaders(array $headers)
    {
        $parsedHeaders = array();
        foreach ($headers as $header) {
            $temp = explode(':', $header, 2);
            if (count($temp) > 1) {
                $parsedHeaders[trim($temp[0])] = trim($temp[1]);
            }
        }

        return $parsedHeaders;
    }

}