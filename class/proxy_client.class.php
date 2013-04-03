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
    protected $server_url;
    protected $provider;
    protected $user;
    protected $pass;

    /**
     * @param string $server_url
     * @param string $provider
     * @param string $user
     * @param string $pass
     */
    public function __construct($server_url, $provider, $user, $pass)
    {
        $this->server_url = $server_url;
        $this->provider = $provider;
        $this->user = $user;
        $this->pass = $pass;
    }

    /**
     * push one
     * @param string $token
     * @param mixed $message
     * @param int $identity
     * @param int $expiry
     * @return mixed
     * @throws SPAPNS_Exception
     */
    public function pushOne($token, $message, $identity=null, $expiry=null)
    {
        /*
            {
                token: string,
                identity: int/optional,
                expiry: int/optional,
                message: {
                    aps:{}
                }
            }
         */
        if (!spAPNSUtils::CheckToken($token)) {
            throw new SPAPNS_Exception('推送token不合法', 300001);
        }
        $msgobj = new spAPNSMessage($message);

        return $this->do_push(array(
            array(
                'token'     =>  $token,
                'identity'  =>  (int) $identity,
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
                throw new SPAPNS_Exception('推送token不合法', 300101);
            }
            if (!isset($v['message'])) {
                throw new SPAPNS_Exception('推送消息不合法', 300102);
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
        $post = 'json=' . json_encode($array);
        $api_url =  $this->server_url . '?provider=' . $this->provider . '&user=' . $this->user . '&pass=' . $this->pass;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        $result = curl_exec($ch);
        curl_close($ch);

        return json_decode($result, true);
    }
}

# EOF