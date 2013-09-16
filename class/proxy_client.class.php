<?php

if (!class_exists('SPAPNS_Exception')) {
    require(__DIR__ . '/apns.class.php');
}
/**
 * Class spAPNSProxyClient
 *
 * @author sskaje
 */
class spAPNSProxyClient
{
    public $keep_conn = true;
    static private $connections = array();


    protected $server_url;
    protected $provider;
    protected $user;
    protected $pass;
    protected $server_ip;

    /**
     * @param string $server_url
     * @param string $provider
     * @param string $user
     * @param string $pass
     */
    public function __construct($server_url, $provider, $user, $pass, $server_ip='')
    {
        $this->server_url = $server_url;
        $this->provider = $provider;
        $this->user = $user;
        $this->pass = $pass;
        $this->server_ip = $server_ip;
    }

    public function __destruct()
    {
        if (0 && $this->keep_conn && !empty($this->connections)) {
            foreach ($this->connections as $ch) {
                curl_close($ch);
            }
        }
    }

    /**
     * push one
     * @param string $token
     * @param mixed $message
     * @param int $identifier
     * @param int $expiry
     * @return mixed
     * @throws SPAPNS_Exception
     */
    public function pushOne($token, $message, $identifier=null, $expiry=null)
    {
        /*
            {
                token: string,
                identifier: int/optional,
                expiry: int/optional,
                message: {
                    aps:{}
                }
            }
         */
        if (!spAPNSUtils::CheckToken($token)) {
            throw new SPAPNS_Exception('推送token不合法', 400001);
        }
        $msgobj = new spAPNSMessage($message);

        return $this->do_push(array(
            array(
                'token'     =>  $token,
                'identifier'  =>  (int) $identifier,
                'expiry'    =>  (int) $expiry,
                'message'   =>  (array) $msgobj->build(false),
            )
        ));
    }

    /**
     * batch push
     *
     * @param array $array
     * @return mixed
     * @throws SPAPNS_Exception
     */
    public function push(array $array)
    {
        $post_array = array();
        foreach ($array as $v) {
            if (!isset($v['token']) || !spAPNSUtils::CheckToken($v['token'])) {
                throw new SPAPNS_Exception('推送token不合法', 400101);
            }
            if (!isset($v['message'])) {
                throw new SPAPNS_Exception('推送消息不合法', 400102);
            }

            $msgobj = new spAPNSMessage($v['message']);
            $v['message'] = $msgobj->build(false);

            $post_array[] = $v;
        }

        return $this->do_push($post_array);
    }

    /**
     * execute http post
     *
     * @param array $array
     * @return mixed
     */
    protected function do_push(array $array)
    {
        if ($this->keep_conn) {
            if (!empty($this->server_ip)) {
                $conn_key = $this->server_ip;
            } else {
                $conn_key = parse_url($this->server_url, PHP_URL_HOST);
            }

            if (!isset(self::$connections[$conn_key])) {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                self::$connections[$conn_key] = $ch;
            }

            $ch = self::$connections[$conn_key];
        } else {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        }

        $post = 'json=' . json_encode($array);
        if (!empty($this->server_ip)) {
            $host = parse_url($this->server_url, PHP_URL_HOST);
            $url = str_replace($host, $this->server_ip, $this->server_url);

            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Host: ' . $host));
        } else {
            $url = $this->server_url;
        }

        $api_url =  $url . '?provider=' . $this->provider . '&user=' . $this->user . '&pass=' . $this->pass;
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        $result = curl_exec($ch);

        # DO NOT CLOSE cURL resource if connection is kept on
        if (!$this->keep_conn) {
            curl_close($ch);
        }
        return json_decode($result, true);
    }
}

# EOF