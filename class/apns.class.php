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
    protected $cert_passphrase = '';

	public function __construct($cert_path, $cert_passphrase='', $dev_mode=false)
	{
		$this->cert_path = $cert_path;
		$this->cert_passphrase = $cert_passphrase;
        $this->dev_mode  = $dev_mode;


		# set default options
		$this->setOption(self::OPT_CONNECT_ASYNC, 1);
		$this->setOption(self::OPT_CONNECT_PERSISTENT, 1);
		$this->setOption(self::OPT_BLOCKING_MODE, 0);
	}

	public function __destruct()
	{
		$this->close();
	}

	/**
	 * Connect asynchronously
	 */
	const OPT_CONNECT_ASYNC = 1;
	/**
	 * Connect persistently
	 */
	const OPT_CONNECT_PERSISTENT = 2;
	/**
	 * Blocking/non-blocking mode
	 */
	const OPT_BLOCKING_MODE = 3;
	/**
	 * Options array
	 *
	 * @var array
	 */
	protected $options = array();

	/**
	 * Set options
	 *
	 * @param int $opt_key
	 * @param mixed $value
	 */
	public function setOption($opt_key, $value)
	{
		switch ($opt_key) {

			case self::OPT_CONNECT_ASYNC:
				if ($value) {
					# turn on async flag and turn off sync
					$this->connect_flag |= STREAM_CLIENT_ASYNC_CONNECT;
					$this->connect_flag &= ~STREAM_CLIENT_CONNECT;
					$this->options[$opt_key] = 1;
				} else {
					# turn on sync flag and turn off async
					$this->connect_flag |= STREAM_CLIENT_CONNECT;
					$this->connect_flag &= ~STREAM_CLIENT_ASYNC_CONNECT;
					$this->options[$opt_key] = 0;
				}

				break;

			case self::OPT_CONNECT_PERSISTENT:

				if ($value) {
					# turn on persistent flag
					$this->connect_flag |= STREAM_CLIENT_PERSISTENT;
					$this->options[$opt_key] = 1;
				} else {
					# turn off persistent flag
					$this->connect_flag &= ~STREAM_CLIENT_PERSISTENT;
					$this->options[$opt_key] = 0;
				}

				break;

			case self::OPT_BLOCKING_MODE:

				if ($value) {
					$this->options[$opt_key] = 1;
				} else {
					$this->options[$opt_key] = 0;
				}

				break;
		}
	}

	/**
	 * Get all options
	 *
	 * @return array
	 */
	public function getOptions()
	{
		return $this->options;
	}

	/**
	 * Get option by key
	 *
	 * @param int $key
	 * @return mixed
	 */
	public function getOption($key)
	{
		return isset($this->options[$key]) ? $this->options[$key] : null;
	}


	protected $connect_flag;

	protected $fp = array();

    /**
     * Get connection host
     *
     * @param string $key
     * @return string
     */
    protected function get_host($key)
	{
		return $this->servers[$this->dev_mode ? 'sandbox' : 'product'][$key] . '/?rnd='.md5($this->cert_path);
	}

    /**
     * Create connection
     *
     * @param $key
     * @return bool|resource
     */
    protected function connect($key)
	{
		# Create Context
		$ctx = stream_context_create();
		stream_context_set_option($ctx, 'ssl', 'local_cert', $this->cert_path);
        if (!empty($this->cert_passphrase)) {
            stream_context_set_option($ctx, 'ssl', 'passphrase', $this->cert_passphrase);
        }

		#
		# Push
		$fp = stream_socket_client(
			$this->get_host($key),
			$errno,
			$error,
			100,
			$this->connect_flag,
			$ctx
		);
        if (!$fp) {
            throw new SPAPNS_Exception('Connection failed. key='.$key, 100002);
        }
		# blocking
		stream_set_blocking($fp, $this->options[self::OPT_BLOCKING_MODE]);
		
		stream_set_write_buffer($fp, 0);

		if (!$fp) {
			throw new SPAPNS_Exception('Failed to create connection ' . $key, 100001);
		}

		$this->fp[$key] = & $fp;

		return $fp;
	}

    /**
     * Performs socket read
     *
     * @param string $key
     * @param int $length
     * @param resource $connection
     * @return string
     */
    protected function read($key, $length, &$connection=null)
    {
        if (!isset($connection) || !is_resource($connection)) {
            $connection = $this->connect($key);
        }
        $result = fread($connection, $length);
        return $result;
    }

    /**
     * Performs socket write
     *
     * @param string $key
     * @param data $data
     * @param resource $connection
     * @return int
     */
    protected function write($key, $data, &$connection=null)
    {
        if (!isset($connection) || !is_resource($connection)) {
            $connection = $this->connect($key);
        }
        $result = fwrite($connection, $data);
        return $result;
    }

    /**
     * Push one message to many tokens
     *
     * @param spAPNSMessage $messageobj
     * @param array|string $tokens
     * @param resource & $connection
     * @return bool|int
     */
    public function push(spAPNSMessage $messageobj, $tokens, &$connection=null)
	{
		$tokens = (array) $tokens;
		$message = '';
		if (isset($tokens['token'])) {
			$tokens = array($tokens);
		}

		foreach ($tokens as $t) {
			$identifier = null;
			$expiry = null;

			if (is_array($t)) {
				if (!isset($t['token'])) {
					continue;
				}
				if (isset($t['identifier'])) {
					$identifier = $t['identifier'];
				}
				if (isset($t['expiry'])) {
					$expiry = $t['expiry'];
				}
			} else {
				$token = $t;
			}
			$payload = $messageobj->payload($token, $identifier, $expiry);
			if (!$payload) {
				continue;
			}
			$message .= $payload;
		}

		if (empty($message)) {
			return false;
		}

		$fwrite = $this->write('gateway', $message, $connection);
		return $fwrite;
	}

    /**
     * Push one message
     *
     * @param spAPNSMessage $messageobj
     * @param $token
     * @param null $identifier
     * @param null $expiry
     * @param resource & $connection
     * @return bool|int
     */
    public function pushOne(spAPNSMessage $messageobj, $token, $identifier=null, $expiry=null, &$connection=null)
	{
		$message = $messageobj->payload($token, $identifier, $expiry);

		if (empty($message)) {
			return false;
		}

        $fwrite = $this->write('gateway', $message, $connection);
		return $fwrite;
	}

    const BATCH_SELECT_TIMEOUT = 10;

    /**
     * Push more than one message
     *
     * @param spAPNSMessageBundle $bundleobj
     * @param int $retry_count
     * @param null $connection
     * @return array
     */
    public function pushBatch(spAPNSMessageBundle $bundleobj, $retry_count=1, &$connection=null)
    {
        if (!$bundleobj->length()) {
            return array();
        }

        $error_identifiers = array();
        $identifiers = $bundleobj->getIdentifiers();
        $retry_counter = array_fill_keys($identifiers, 0);

        do {
            if ($bundleobj->length()) {

                $message = $bundleobj->payload();

                $r = 0;
                do {
                    $fwrite = $this->write('gateway', $message, $connection);
                } while(!$fwrite && ++$r < 10);

                if (!$fwrite) {
                    # error ?
                }

                $tv_sec = self::BATCH_SELECT_TIMEOUT;
                $tv_usec = null;
                $r = array($connection);
                $we = null;
                $numChanged = stream_select($r, $we, $we, $tv_sec, $tv_usec);		//参数为引用，所以得先命名参数

                if($numChanged > 0) {
                    $error = $this->readErrorResponse($connection);

                    if (is_array($error) && isset($error['identifier']) && isset($error['command']) && $error['command'] == 8) {
                        if ($retry_counter[$error['identifier']] < $retry_count) {
                            $bundleobj->trimFrom($error['identifier'], false);
                            ++ $retry_counter[$error['identifier']];
                        } else {
                            $bundleobj->trimFrom($error['identifier'], true);
                            $error_identifiers[$error['identifier']] = $error['command'];
                        }
                        continue;
                    }
                }
            }

            break;
        } while(1);

        return $error_identifiers;
    }


    /**
     * Read error response
     *
     * @param resource & $connection
     * @return array|null
     */
    public function readErrorResponse(&$connection=null)
	{
		$read = $this->read('gateway', 6, $connection);
		if (!$read) {
			return null;
		}
        return unpack('Ccommand/Ccode/Nidentifier', $read);
	}

    /**
     * Feedback service
     *
     * @param callback $callback
     * @param bool $daemon
     * @param int $loop_count
     */
    public function feedback($callback, $daemon=false, $loop_count=100000)
	{
		$c = 0;
        $fp = $this->connect('feedback');
		while (($daemon || ++$c < $loop_count) && ($feedback = fread($fp, 38))) {
            $fb_array = unpack('Ntimestamp/ntokenLength/H*token', $feedback);

			if(!empty($fb_array['token'])){
				# feedback failure callback
				if (is_callable($callback)) {
					call_user_func($callback, $fb_array['token'], $fb_array);
				}
			} else {
				usleep(300000);
			}
		}
	}

    /**
     * Close connection
     *
     * @param string|null $key
     */
    public function close($key=null)
	{
		if (!$key || !isset($this->fp[$key])) {
			foreach ($this->fp as $k=>$v) {
				fclose($this->fp[$k]);
                unset($this->fp[$k]);
			}
		} else {
			fclose($this->fp[$key]);
            unset($this->fp[$key]);
		}
	}
}

# EOF
