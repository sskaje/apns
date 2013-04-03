<?php

/**
 * Class spAPNSProxy
 *
 * @author sskaje
 */
if (!class_exists('SPAPNS_Exception')) {
    require(__DIR__ . '/apns.class.php');
}

class spAPNSProxy
{
    /**
     * @var spAPNSProxyConfig
     */
    protected $config;
    /**
     * @var spAPNSQueue
     */
    protected $q;

    public function __construct(spAPNSProxyConfig $config)
    {
        $this->config = $config;

        $queue_config = array(
            'host'  =>  $config->queue_host,
            'port'  =>  $config->queue_port,
            'timeout'   =>  $config->queue_timeout,
            'key'   =>  $config->queue_key,
        );

        $this->q = new spAPNSQueue($config->queue_handler, $queue_config);
    }

    /**
     * api
     * Perform like an api script
     * push request to queue
     *
     * @throws SPAPNS_Exception
     */
    public function api()
    {
        /*
        provider: string,
        user: string/optional,
        pass: string/optional,
        data: [
            {
                token: string,
                identity: int/optional,
                expiry: int/optional,
                message: {
                    aps:{}
                }
            }
        ]
        */
        try {
            if (!isset($_GET['provider'])) {
                throw new SPAPNS_Exception('provider cannot be empty', 500001);
            }
            $provider = $_GET['provider'];
            $provider_config = $this->config->getProvider($provider);
            if (empty($provider_config)) {
                throw new SPAPNS_Exception('provider not available', 500002);
            }

            if (
                ($provider_config['auth_user'] && (empty($_GET['user']) || $provider_config['auth_user'] != $_GET['user'])) &&
                ($provider_config['auth_pass'] && (empty($_GET['pass']) || $provider_config['auth_pass'] != md5($_GET['pass'])))
            ) {
                throw new SPAPNS_Exception('auth failed', 500003);
            }

            if (empty($_POST['json']) || !($json = json_decode($_POST['json'], true)) || !is_array($json)) {
                throw new SPAPNS_Exception('json cannot be empty', 500004);
            }

            $ret_identities = array();

            foreach ($json as $array) {
                if (!isset($array['token']) || !isset($array['message']) || !isset($array['message']['aps'])) {
                    $ret_identities[] = null;
                    $this->log(LOG_INFO, ' [API] invalid message. provider='.$provider, $array );
                    continue;
                }

                $array['provider'] = $provider;
                if (!isset($array['identity']) || empty($array['identity'])) {
                    $array['identity'] = crc32(uniqid('apns_proxy', true));
                }
                $array['identity'] = (int) $array['identity'];
                $ret_identities[] = $array['identity'];

                if (!isset($array['expiry'])) {
                    $array['expiry'] = 0;
                }
                $array['expiry'] = (int) $array['expiry'];

                $this->q->push($array);
            }

            echo json_encode(array('code'=>0, 'message'=>'success', 'data'=>$ret_identities));
        } catch (Exception $e) {
            echo json_encode(array('code'=>$e->getCode(), 'message'=>$e->getMessage()));
        }
    }

    /**
     * read data from queue and send to apns
     *
     * @param bool $daemon
     * @param int $count
     * @param int $timeout
     */
    public function exec($daemon=false, $count=10000, $timeout=75)
    {
        $c = 0;
        $time0 = microtime(1);

        $providers = $this->config->getAllProviders();

        $provider_apns_objs = array();

        foreach ($providers as $k=>$v) {
            $provider_apns_objs[$k] = new spSimpleAPNS($v['cert_path'], $v['dev_mode']);
        }

        do {
            $val = $this->q->pop();
            if (!isset($val['provider'])) {
                usleep(500000);
                continue;
            }
            if (!isset($provider_apns_objs[$val['provider']])) {
                $this->log(LOG_INFO, ' [DAEMON] connection for '.$val['provider'].' object not found.', $val);
                continue;
            }

            $msgobj = new spAPNSMessage($val['message']);

            $ret = $provider_apns_objs[$val['provider']]->pushOne($msgobj, $val['token'], $val['identity'], $val['expiry']);

            $this->log(
                LOG_INFO,
                '[DAEMON] PUSH: TOKEN=' . $val['token'] . ' IDENTITY='.$val['identity'].' EXPIRE='.$val['expiry'].' MSG=' . $msgobj->build() . ' RET=' . $ret,
                array()
            );

            $error_response = $provider_apns_objs[$val['provider']]->readErrorResponse();
            if ($error_response) {
                $this->log(
                    LOG_ERR,
                    '[DAEMON] ERROR RESPONSE: CODE='.$error_response[0] . ' IDENTITY='.$error_response[1],
                    array()
                );
            }

        } while( $daemon || ($c++ < $count && (microtime(1) - $time0) <= $timeout));

        # an extra 10 seconds for error response packets
        $time0 = microtime(1);
        do {
            foreach ($provider_apns_objs as $v) {
                $error_response = $v->readErrorResponse();
                if ($error_response) {
                    $this->log(
                        LOG_ERR,
                        '[DAEMON] ERROR RESPONSE: CODE='.$error_response[0] . ' IDENTITY='.$error_response[1],
                        array()
                    );
                }
            }
        } while(!$daemon && (microtime(1) - $time0) <= 10);
    }

    protected function log($level, $msg, $var=null)
    {
        $logfiles = array(
            LOG_ERR     =>  'err.log',
            LOG_INFO    =>  'info.log',

        );
        file_put_contents(
            $this->config->log_path . '/' . $logfiles[$level],
            date('Y-m-d H:i:s ').trim($msg) . ($var != null ? var_export($var, 1) : '') . "\n\n",
            FILE_APPEND
        );
    }

}

/**
 * Class spAPNSProxyConfig
 *
 * @author sskaje
 */
class spAPNSProxyConfig
{
    protected $global;
    protected $provider;

    protected $global_defaults = array(
        'log_path'      =>  null,
        'queue_handler' =>  'redis',
        'queue_host'    =>  '127.0.0.1',
        'queue_port'    =>  6379,
        'queue_timeout' =>  2,
        'queue_key'     =>  'apns_proxy_queue',
    );

    protected $provider_defaults = array(
        'cert_path' =>  null,
        'dev_mode'  =>  0,
        'auth_user' =>  '',
        'auth_pass' =>  '',
    );

    public function __construct($ini_data, $is_file=true)
    {
        if ($is_file) {
            $ini_array = parse_ini_file($ini_data, true);
        } else {
            $ini_array = parse_ini_string($ini_data, true);
        }

        $this->global = $this->parse_defaults($ini_array['global'], $this->global_defaults);

        foreach ($ini_array as $k=>$v) {
            if (strpos($k, 'apns:') == 0) {
                $config = $this->parse_defaults($v, $this->provider_defaults);

                if ($config != false) {
                    $key = substr($k, 5);
                    $this->provider[$key] = $config;
                }
            }
        }
    }
    protected function parse_defaults($config, $defaults)
    {
        $valid_provider_config = true;

        $ret_config = array();

        foreach ($defaults as $_k=>$_v) {
            if ($_v === null) {
                if (!isset($config[$_k]) || empty($config[$_k])) {
                    $valid_provider_config = false;

                    # write to stderr ?

                    break;
                }
            } else {
                if (!isset($config[$_k])) {
                    $config[$_k] = $_v;
                }
            }

            $ret_config[$_k] = $config[$_k];
        }

        if ($valid_provider_config) {
            return $ret_config;
        } else {
            return false;
        }
    }

    public function getProvider($provider)
    {
        return isset($this->provider[$provider]) ? $this->provider[$provider] : null;
    }
    public function getAllProviders()
    {
        return $this->provider;
    }

    public function __get($key)
    {
        return isset($this->global[$key]) ? $this->global[$key] : null;
    }
    public function __set($key, $val)
    {
        # pass
    }
    public function __isset($key)
    {
        return isset($this->global[$key]);
    }
    public function __unset($key)
    {
        # pass
    }
}

define('SP_APNSQUEUE_HANDLER_REDIS', 'redis');
/**
 * Class spAPNSQueue
 *
 * @author sskaje
 */
class spAPNSQueue
{
    /**
     * @var ifAPNSQueue
     */
    static protected $q;

    public function __construct($handler, $config)
    {
        if ($handler == SP_APNSQUEUE_HANDLER_REDIS) {
            $handlerClass = 'spAPNSQueueRedis';
        } else {
            throw new SPAPNS_Exception('Bad queue handler', 100001);
        }

        $this->q = new $handlerClass($config);
    }

    protected $key = 'apns_proxy_queue';

    public function push($val)
    {
        return $this->q->push($this->key, $val);
    }

    public function pop()
    {
        return $this->q->pop($this->key);
    }
}

/**
 * Class ifAPNSQueue
 */
interface ifAPNSQueue
{
    public function __construct($config);
    public function push($key, $val);
    public function pop($key);
}

/**
 * Class spAPNSQueueRedis
 */
class spAPNSQueueRedis implements ifAPNSQueue
{
    protected $redis;

    public function __construct($config)
    {
        if (!class_exists('redis')) {
            throw new SPAPNS_Exception('Please enable redis extension.', 101001);
        }

        $this->redis = new Redis;
        if (!isset($config['host']) || empty($config['host'])) {
            $config['host'] = '127.0.0.1';
        }
        # unix socket file
        if ($config['host'][0] == '/') {
            $this->redis->connect($config['host']);
        } else {
            $this->redis->connect(
                $config['host'],
                isset($config['port']) ? $config['port'] : 6379,
                isset($config['timeout']) ? $config['timeout'] : 3
            );
        }
    }

    public function push($key, $val)
    {
        return $this->redis->lpush($key, serialize($val));
    }

    public function pop($key)
    {
        return unserialize($this->redis->rpop($key));
    }
}


if (!defined('LOG_INFO')) {
    define ('LOG_EMERG', 0);
    define ('LOG_ALERT', 1);
    define ('LOG_CRIT', 2);
    define ('LOG_ERR', 3);
    define ('LOG_WARNING', 4);
    define ('LOG_NOTICE', 5);
    define ('LOG_INFO', 6);
    define ('LOG_DEBUG', 7);

}
# EOF