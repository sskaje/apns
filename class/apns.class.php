<?php

/**
 * Class spSimpleAPNS
 *
 * @author sskaje
 */
class spSimpleAPNS {
	protected $servers = array(
		'sandbox'	=>	array(
			'gateway'	=>	'ssl://gateway.sandbox.push.apple.com:2195',
			'feedback'	=>	'ssl://feedback.sandbox.push.apple.com:2196',
		),
		'product'	=>	array(
			'gateway'	=>	'ssl://gateway.push.apple.com:2195',
			'feedback'	=>	'ssl://feedback.push.apple.com:2196',
		),
	);

	protected $dev_mode = false;
	protected $cert_path;

	public function __construct($cert_path, $dev_mode=false)
	{
		$this->cert_path = $cert_path;
		$this->dev_mode  = $dev_mode;
	}
	public function __destruct()
	{
		$this->close();
	}

	protected $fp = array();
	
	protected function get_host($key)
	{
		return $this->servers[$this->dev_mode ? 'sandbox' : 'product'][$key] . '/?rnd='.md5($this->cert_path);
	}

	protected function connect($key)
	{
		# Create Context
		$ctx = stream_context_create();
		stream_context_set_option($ctx, 'ssl', 'local_cert', $this->cert_path);

		#
		# Push
		$fp = stream_socket_client($this->get_host($key), $errno, $error, 100, (STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_PERSISTENT), $ctx);
		stream_set_blocking($fp, 0);
		stream_set_write_buffer($fp, 0);

		if (!$fp) {
			return false;
		}

		$this->fp[$key] = & $fp;

		return $fp;
	}

	public function push(spAPNSMessage $messageobj, $tokens)
	{
		$tokens = (array) $tokens;
		$message = '';
        if (isset($tokens['token'])) {
            $tokens = array($tokens);
        }

		foreach ($tokens as $t) {
            $identity = null;
            $expiry = null;

            if (is_array($t)) {
                if (!isset($t['token'])) {
                    continue;
                }
                if (isset($t['identity'])) {
                    $identity = $t['identity'];
                }
                if (isset($t['expiry'])) {
                    $expiry = $t['expiry'];
                }
            } else {
                $token = $t;
            }
			$payload = $messageobj->payload($token, $identity, $expiry);
			if (!$payload) {
				continue;
			}
			$message .= $payload;
		}

		if (empty($message)) {
			return false;
		}

		$fwrite = fwrite($this->connect('gateway'), $message);
		return $fwrite;
	}

	public function pushOne(spAPNSMessage $messageobj, $token, $identity=null, $expiry=null) 
	{
		$message = $messageobj->payload($token, $identity, $expiry);

        if (empty($message)) {
            return false;
        }

        $fwrite = fwrite($this->connect('gateway'), $message);
        return $fwrite;

	}

	public function readErrorResponse()
	{
		$read = fread($this->connect('gateway'), 6);
        if (!$read) {
            return null;
        }
		$code = ord($read[1]);
		$identity = unpack('N', substr($read, 2));
		return array($code, $identity);
	}

	public function feedback($callback, $daemon=false, $loop_count=100000)
	{
		$c = 0;
		while (($daemon || ++$c < $loop_count) && ($feedback = fread($this->connect('feedback'), 38))) {
			$arr = unpack("H*", $feedback);

			$rawhex = trim(implode("", $arr));
			$token = substr($rawhex, 12, 64);
			if(!empty($token)){
				# feedback failure callback
				if (is_callable($callback)) {
					call_user_func($callback, $token);
				}
			} else {
				usleep(300000);
			}
		}
	}

	public function close($key=null)
	{
		if (!$key || !isset($this->fp[$key])) {
			foreach ($this->fp as & $v) {
				fclose($v);
			}
		} else {
			fclose($this->fp[$key]);
		}
	}
}


/**
 * Push Message
 *
 * @author sskaje
 */
class spAPNSMessage
{
    const TIMEOUT = 86400;

	public function __construct($message=null)
	{
		if (!empty($message)) {
			if (is_string($message)) {
				$array = json_decode($message, true);
			} else {
				$array = $message;
			}
			if (!empty($array)) {
				if (isset($array['aps']['alert'])) {
					$this->setAlert(
						isset($array['aps']['alert']['body'])			? $array['aps']['alert']['body'] 			: null,
						isset($array['aps']['alert']['action_loc_key'])	? $array['aps']['alert']['action_loc_key']	: null,
						isset($array['aps']['alert']['loc_key'])		? $array['aps']['alert']['loc_key']			: null,
						isset($array['aps']['alert']['loc_args'])		? $array['aps']['alert']['loc_args']		: array(),
						isset($array['aps']['alert']['launch_image'])	? $array['aps']['alert']['launch_image']	: null
					);
				}
				if (isset($array['aps']['badge'])) {
					$this->setBadge($array['aps']['badge']);
				}
				if (isset($array['aps']['sound'])) {
					$this->setSound($array['aps']['sound']);
				}

				unset($array['aps']);
				foreach ($array as $k=>$v) {
					$this->addCustom($k, $v);
				}
			} else {
                $this->setALert($message);
            }
		}
	}

	protected $alert = array(
		'body'				=>	'',
		'action-loc-key'	=>	null,
		'loc-key'			=>	'',
		'loc-args'			=>	array(),
		'launch-image'		=>	'',
	);
	protected $badge = null;
	protected $sound = '';
	/**
	 * Set Alert field
	 * Read 'Local and Push Notification Programming Guide' by Apple Inc.
	 *
	 * @param string $body
	 * @param string $action_loc_key
	 * @param string $loc_key
	 * @param array  $loc_args
	 * @param string $launch_image
	 * @return spAPNSMessage
	 */
	public function setAlert($body, $action_loc_key=null, $loc_key='', array $loc_args=array(), $launch_image='')
	{
		$this->alert['body'] 			= strval($body);
		$this->alert['action-loc-key']	= strval($action_loc_key);
		$this->alert['loc-key']			= strval($loc_key);
		$this->alert['loc-args']		= (array) $loc_args;
		$this->alert['launch-image']	= strval($launch_image);
		return $this;
	}
	/**
	 * Set Badge field
	 *
	 * @param int $badge
	 * @return spAPNSMessage
	 */
	public function setBadge($badge)
	{
		$this->badge = (int) $badge;
		return $this;
	}
	/**
	 * Set Sound field
	 *
	 * @param string $sound
	 * @return spAPNSMessage
	 */
	public function setSound($sound)
	{
		$this->sound = strval($sound);
		return $this;
	}
	/**
	 * Build Payload
	 * @param string $token
	 * @return boolean|string
	 */
	public function payload($token, $identity=null, $expiry=null)
	{
		$token = str_replace(' ', '', $token);
		if (!spAPNSUtils::CheckToken($token)) {
			return false;
		}

		$message = $this->build(true);
        $current_time = time();
        if (empty($expiry) || ($expiry < $current_time && $expiry > 86400 * 180)) {
            $expiry = $current_time + self::TIMEOUT;
        } else if ($expiry <= 86400 * 180) {
            $expiry = $current_time + $expiry;
        }

		if (!empty($identity)) {
			$msg = chr(1).pack('N', (int) $identity).pack('N', (int) $expiry).pack("n",32).pack('H*',$token).pack("n",strlen($message)).$message;
		} else {
			$msg = chr(0).pack("n",32).pack('H*',$token).pack("n",strlen($message)).$message;
		}
		return $msg;
	}

	protected $custommsg = array();
	/**
	 * Add Custom Message
	 *
	 * @param string $key
	 * @param mixed $data
	 * @return spAPNSMessage
	*/
	public function addCustom($key, $data)
	{
		$this->custommsg[$key] = $data;
		return $this;
	}
	/**
	 * Build Message
     *
     * @param bool $return_json_string
     * @return string|array
	 */
	public function build($return_json_string=true)
	{
		$alert = $this->alert;
		foreach ($alert as $k=>$v) {
			if (empty($v)) {
				unset($alert[$k]);
			}
		}

		$ret = array(
			'aps'	=>	array(
				'alert'		=>	$alert,
			),
		);
		if ($this->badge !== null) {
			$ret['aps']['badge'] = (int) $this->badge;
		}
		if ($this->sound) {
			$ret['aps']['sound'] = $this->sound;
		}

		# Append Custom Message
		foreach ($this->custommsg as $k=>$v) {
			if ($k == 'aps') {
				continue;
			}
			$ret[$k] = $v;
		}

        if ($return_json_string) {
            return apns_json_encode($ret);
        } else {
            return $ret;
        }
	}
}

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
}

/**
 * Class SPAPNS_Exception
 *
 * @author sskaje
 */
class SPAPNS_Exception extends Exception{}


# EOF
