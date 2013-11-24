<?php
/**
 * Entry file for APNs class
 *
 * @author sskaje
 */

require(__DIR__ . '/apns.class.php');
require(__DIR__ . '/message.class.php');
require(__DIR__ . '/proxy.class.php');
require(__DIR__ . '/proxy_client.class.php');

if (!function_exists('apns_json_encode')) {
    function apns_replace_unicode($in) {
        return json_decode('"' . $in .'"');
    }

    function apns_json_encode($in)
    {
        $s = preg_replace("#(\\\u[0-9a-f]{4})+#ie", "apns_replace_unicode('$0')", json_encode($in));
        return $s;
    }
}


/**
 * Class spAPNSUtils
 *
 * @author sskaje
 */
class spAPNSUtils
{
    static public function CheckToken($token)
    {
        $token = str_replace(' ', '', $token);
        return preg_match('#^[0-9a-f]{64}$#i', $token);
    }

    static public function Punch($msg)
    {
        fwrite(
            STDERR,
            "[".microtime(1)."] " . trim($msg) . "\n"
        );
    }
}

/**
 * Class SPAPNS_Exception
 *
 * @author sskaje
 */
class SPAPNS_Exception extends Exception{}



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
    protected $q;

    public function __construct($handler, $config)
    {
        if ($handler == SP_APNSQUEUE_HANDLER_REDIS) {
            $handlerClass = 'spAPNSQueueRedis';
        } else {
            throw new SPAPNS_Exception('Bad queue handler', 200001);
        }

        $this->key = $config['key'];

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
    protected $block_read = false;
    protected $block_read_timeout = 5;

    public function __construct($config)
    {
        if (!class_exists('redis')) {
            throw new SPAPNS_Exception('Please enable redis extension.', 201001);
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

        if (isset($config['block_read'])) {
            $this->block_read = $config['block_read'] ? 1 : 0;
        }

        if (isset($config['block_read_timeout'])) {
            $this->block_read_timeout = (int) $config['block_read_timeout'];
        }
    }

    public function __destruct()
    {
        $this->redis->close();
    }

    public function push($key, $val)
    {
        return $this->redis->lpush($key, serialize($val));
    }

    public function pop($key)
    {
        if ($this->block_read) {
            $ret = $this->redis->brpop($key, $this->block_read_timeout);
            if (is_array($ret)) {
                $ret = unserialize($ret[1]);
            } else {
                $ret = unserialize($ret);
            }
        } else {
            $ret = $this->redis->rpop($key);
            $ret = unserialize($ret);
        }
        return $ret;
    }

}


if (!defined('LOG_INFO')) {
    define('LOG_EMERG',   0);
    define('LOG_ALERT',   1);
    define('LOG_CRIT',    2);
    define('LOG_ERR',     3);
    define('LOG_WARNING', 4);
    define('LOG_NOTICE',  5);
    define('LOG_INFO',    6);
    define('LOG_DEBUG',   7);
}

# EOF