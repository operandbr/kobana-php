<?php

namespace BoletoSimples;

use CommerceGuys\Guzzle\Oauth2\Oauth2Subscriber;
use GuzzleHttp\Client;

class BaseResource
{
    /**
     * The GuzzleHttp\Client object.
     */
    public static $client;

    /**
     * Default options used on Guzzle requests.
     */
    public static $default_options;

    /**
     * Array with all errors returned from last request.
     */
    public $response_errors = [];

    /**
     * The attributes of the current object, accessed via the anonymous get/set methods.
     */
    private $_attributes = [];

    /**
     * Constructor method.
     *
     * @param mixed $attributes
     */
    public function __construct($attributes = [])
    {
        $this->_attributes = $attributes;
        self::configure();
    }

    /**
     * Getter for internal object data.
     *
     * @param mixed $k
     */
    public function __get($k)
    {
        if (isset($this->_attributes[$k])) {
            return $this->_attributes[$k];
        }
    }

    /**
     * Setter for internal object data.
     *
     * @param mixed $k
     * @param mixed $v
     */
    public function __set($k, $v)
    {
        $this->_attributes[$k] = $v;
    }

    public static function __callStatic($name, $arguments)
    {
        self::configure();

        return call_user_func_array('self::_'.$name, $arguments);
    }

    public function attributes()
    {
        return $this->_attributes;
    }

    public function isNew()
    {
        return !isset($this->_attributes['id']) || null == $this->_attributes['id'];
    }

    public function isPersisted()
    {
        return !$this->isNew();
    }

    public function path($action = null)
    {
        $class = get_called_class();
        $path = $this->isNew() ? $class::element_name_plural() : $class::element_name_plural().'/'.$this->_attributes['id'];
        if ($action) {
            $path .= '/'.$action;
        }

        return $path;
    }

    public function save()
    {
        $action = $this->isNew() ? 'create' : 'update';

        return $this->_request($action);
    }

    public function parseResponse($response)
    {
        $status = $response->getStatusCode();
        if ($status >= 200 && $status <= 299) {
            if ($response->json()) {
                $this->_attributes = $response->json();
            }

            return true;
        }
        if (isset($response->json()['errors'])) {
            $this->response_errors = $response->json()['errors'];
        }

        return false;
    }

    public static function methodFor($action)
    {
        $methods = [
            'create' => 'POST',
            'update' => 'PUT',
            'find' => 'GET',
            'destroy' => 'DELETE',
            'new' => 'GET',
        ];

        return $methods[$action];
    }

    public static function element_name()
    {
        return Util::underscorize(get_called_class());
    }

    public static function element_name_plural()
    {
        return Util::pluralize(self::element_name());
    }

    /**
     * Configure the GuzzleHttp\Client with default options.
     */
    public static function configure()
    {
        $config = \BoletoSimples::$configuration;

        $oauth2 = new Oauth2Subscriber();
        $oauth2->setAccessToken($config->access_token);

        self::$client = new Client([
            'base_url' => $config->baseUri(),
            'defaults' => [
                'headers' => [
                    'User-Agent' => $config->userAgent(),
                ],
                'auth' => 'oauth2',
                'subscribers' => [$oauth2],
            ],
            'verify' => false,
        ]);

        self::$default_options = ['headers' => ['Content-Type' => 'application/json'], 'exceptions' => false];
    }

    private function _request($action)
    {
        $class = get_called_class();
        $method = self::methodFor($action);
        $path = $this->path();
        $options = [];
        if ('create' == $action || 'update' == $action) {
            $attributes = [$class::element_name() => $this->_attributes];
            $options = ['json' => $attributes];
        }

        $response = self::sendRequest($method, $path, $options);

        return $this->parseResponse($response);
    }

    private static function _find($id)
    {
        if (!$id) {
            throw new \Exception("Couldn't find ".get_called_class().' without an ID.');
        }
        $class = get_called_class();
        $object = new $class(['id' => $id]);
        $object->_request('find');

        return $object;
    }

    private static function _create($attributes = [])
    {
        $class = get_called_class();
        $object = new $class($attributes);
        $object->save();

        return $object;
    }

    private static function _all($params = [])
    {
        $class = get_called_class();
        $response = self::sendRequest('GET', $class::element_name_plural(), ['query' => $params]);
        $collection = [];
        foreach ($response->json() as $attributes) {
            $collection[] = new $class($attributes);
        }

        return $collection;
    }

    private static function _sendRequest($method, $path, $options = [])
    {
        $options = array_merge(self::$default_options, $options);
        $request = self::$client->createRequest($method, $path, $options);
        $response = self::$client->send($request);
        \BoletoSimples::$last_request = new LastRequest($request, $response);
        if ($response->getStatusCode() >= 400 && $response->getStatusCode() <= 599) {
            new ResponseError($response);
        }

        return $response;
    }
}
