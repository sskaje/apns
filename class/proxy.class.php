<?php

/**
 * Class spAPNSProxy
 *
 * @author sskaje
 */

class spAPNSProxy
{
    /**
     * @var spAPNSProxyConfig
     */
    public $config;
    /**
     * @var spAPNSQueue
     */
    public $q;

    public function __construct(spAPNSProxyConfig $config)
    {
        $this->config = $config;

        $queue_config = array(
            'host'  =>  $config->queue_host,
            'port'  =>  $config->queue_port,
            'timeout'   =>  $config->queue_timeout,
            'key'   =>  $config->queue_key,
            'block_read'    =>  $config->queue_block_read,
            'block_read_timeout'    =>  $config->queue_block_read_timeout,
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
                identifier: int/optional,
                expiry: int/optional,
                message: {
                    aps:{}
                }
            }
        ]
        */
        try {
            if (!isset($_GET['provider'])) {
                throw new SPAPNS_Exception('provider cannot be empty', 300001);
            }
            $provider = $_GET['provider'];
            $provider_config = $this->config->getProvider($provider);
            if (empty($provider_config)) {
                throw new SPAPNS_Exception('provider not available', 300002);
            }

            if (
                ($provider_config['auth_user'] && (empty($_GET['user']) || $provider_config['auth_user'] != $_GET['user'])) &&
                ($provider_config['auth_pass'] && (empty($_GET['pass']) || $provider_config['auth_pass'] != md5($_GET['pass'])))
            ) {
                throw new SPAPNS_Exception('auth failed', 300003);
            }

            if (empty($_POST['json']) || !($json = json_decode($_POST['json'], true)) || !is_array($json)) {
                throw new SPAPNS_Exception('json cannot be empty', 300004);
            }

            $ret_identities = array();

            foreach ($json as $array) {
                if (!isset($array['token']) || !isset($array['message']) || !isset($array['message']['aps']) || !spAPNSUtils::CheckToken($array['token'])) {
                    $ret_identities[] = null;
                    $this->log(LOG_INFO, ' [API] invalid message. provider='.$provider, $array);
                    continue;
                }

                $array['provider'] = $provider;
                if (!isset($array['identifier']) || empty($array['identifier'])) {
                    $array['identifier'] = crc32(uniqid('apns_proxy', true));
                }
                $array['identifier'] = (int) $array['identifier'];
                $ret_identities[] = $array['identifier'];

                if (!isset($array['expiry'])) {
                    $array['expiry'] = 86400 * 3;
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
     * Run as daemon
     */
    public function daemon()
    {
        $class_name = 'spAPNSProxyDaemon_' . $this->config->daemon;
        $class_file = __DIR__ . '/daemon/' . $this->config->daemon . '.daemon.php';

        if (!class_exists($class_name)) {
            if (!is_file($class_file)) {
                throw new SPAPNS_Exception("Daemon file '{$class_file}' not exists", 301001);
            }
            # load class
            require($class_file);

            if (!class_exists($class_name)) {
                throw new SPAPNS_Exception("Daemon class '{$class_name}' not found", 301002);
            }
        }

        $daemon = new $class_name($this);
        $daemon->daemon();
    }

    /**
     * log
     *
     * @param $level
     * @param $msg
     * @param null $var
     */
    public function log($level, $msg, $var=null)
    {
        $logfiles = array(
            LOG_ERR     =>  'err.log',
            LOG_INFO    =>  'info.log',
            LOG_EMERG   =>  'emerg.log',
        );
        file_put_contents(
            $this->config->log_path . '/' . $logfiles[$level],
            date('Y-m-d H:i:s ').trim($msg) . ($var != null ? var_export($var, 1) : '') . "\n",
            FILE_APPEND
        );
    }
}

/**
 * Class spAPNSProxyDaemon
 * Abstract class for daemons
 *
 */
abstract class spAPNSProxyDaemon
{
    /**
     * @var spAPNSProxy
     */
    protected $proxy;
    /**
     * daemon name
     *
     * @var string
     */
    protected $daemon_name = '';
    /**
     * defaults
     *
     * @var array
     */
    protected $defaults = array();

    public function __construct(spAPNSProxy $proxy)
    {
        $this->proxy = $proxy;

        $this->config = spAPNSProxyConfig::parseDefaults(
            $this->proxy->config->getDaemon($this->daemon_name),
            $this->defaults
        );
    }

    abstract public function daemon();

}

/**
 * Class spAPNSProxyConfig
 *
 * @author sskaje
 */
class spAPNSProxyConfig
{
    protected $global = array();
    protected $provider = array();
    protected $daemon = array();

    static protected $global_defaults = array(
        'log_path'      =>  null,
        'queue_handler' =>  'redis',
        'queue_host'    =>  '127.0.0.1',
        'queue_port'    =>  6379,
        'queue_timeout' =>  2,
        'queue_key'     =>  'apns_proxy_queue',
        'queue_block_read'  =>  0,
        'queue_block_read_timeout'  =>  5,

        'daemon'        =>  'default',

    );

    static protected $provider_defaults = array(
        'cert_path' =>  null,
        'cert_pass' => '',
        'dev_mode'  =>  0,
        'auth_user' =>  '',
        'auth_pass' =>  '',
    );

    public function __construct($ini_data, $is_file=true)
    {
        if ($is_file) {
            if (!is_file($ini_data)) {
                throw new SPAPNS_Exception("Configuration file {$ini_data} not found", 500001);
            }
            $ini_array = parse_ini_file($ini_data, true);
        } else {
            $ini_array = parse_ini_string($ini_data, true);
        }

        $this->global = self::parseDefaults($ini_array['global'], self::$global_defaults);


        foreach ($ini_array as $k=>$v) {
            if (strpos($k, 'apns:') === 0) {
                $config = self::parseDefaults($v, self::$provider_defaults);

                if ($config != false) {
                    $key = substr($k, 5);
                    $this->provider[$key] = $config;
                }
            } else if (strpos($k, 'daemon:') === 0) {
                $this->daemon[substr($k, 7)] = $v;
            }
        }
    }

    /**
     * Parse default config
     *
     * @param $config
     * @param $defaults
     * @return array|bool
     */
    static public function parseDefaults($config, $defaults)
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

    /**
     * Get daemon config by daemon name
     *
     * @param string $daemon
     * @return null
     */
    public function getDaemon($daemon)
    {
        return isset($this->daemon[$daemon]) ? $this->daemon[$daemon] : null;
    }

    /**
     * Get all daemon config
     *
     * @return array
     */
    public function getAllDaemons()
    {
        return $this->daemon;
    }
    /**
     * Get provider config by provider name
     *
     * @param string $provider
     * @return null
     */
    public function getProvider($provider)
    {
        return isset($this->provider[$provider]) ? $this->provider[$provider] : null;
    }

    /**
     * Get all provider config
     *
     * @return array
     */
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


# EOF
