<?php
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
                        isset($array['aps']['alert']['body'])            ? $array['aps']['alert']['body']             : null,
                        isset($array['aps']['alert']['action_loc_key'])    ? $array['aps']['alert']['action_loc_key']    : null,
                        isset($array['aps']['alert']['loc_key'])        ? $array['aps']['alert']['loc_key']            : null,
                        isset($array['aps']['alert']['loc_args'])        ? $array['aps']['alert']['loc_args']        : array(),
                        isset($array['aps']['alert']['launch_image'])    ? $array['aps']['alert']['launch_image']    : null
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
        'body'                =>    '',
        'action-loc-key'    =>    null,
        'loc-key'            =>    '',
        'loc-args'            =>    array(),
        'launch-image'        =>    '',
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
        $this->alert['body']             = strval($body);
        $this->alert['action-loc-key']    = strval($action_loc_key);
        $this->alert['loc-key']            = strval($loc_key);
        $this->alert['loc-args']        = (array) $loc_args;
        $this->alert['launch-image']    = strval($launch_image);
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
    public function payload($token, $identifier=null, $expiry=null)
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

        if (!empty($identifier)) {
            $msg = chr(1).pack('N', (int) $identifier).pack('N', (int) $expiry).pack("n",32).pack('H*',$token).pack("n",strlen($message)).$message;
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
            'aps'    =>    array(
                'alert'        =>    $alert,
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

/**
 * Class spAPNSMessageBundle
 * Process APNS push in batch mode
 *
 */
class spAPNSMessageBundle
{
    protected $pointer     = 0;

    protected $objects     = array();
    protected $tokens      = array();
    protected $identifiers = array();
    protected $expiry      = array();
    protected $pointers    = array();

    public function __construct()
    {
        $this->reset();
    }

    /**
     * Reset internal variables
     */
    public function reset()
    {
        $this->pointer     = 0;

        $this->objects     = array();
        $this->tokens      = array();
        $this->identifiers = array();
        $this->expiry      = array();
        $this->pointers    = array();
    }

    /**
     * Add message to bundle
     *
     * @param spAPNSMessage $message_object
     * @param string $token
     * @param int $identifier
     * @param int $expiry
     */
    public function add(spAPNSMessage $message_object, $token, $identifier=null, $expiry=null)
    {
        $this->objects[$this->pointer]     = $message_object;
        $this->tokens[$this->pointer]      = $token;
        $this->expiry[$this->pointer]      = $expiry;
        # find me back...lol
        $this->identifiers[$this->pointer] = $identifier;
        $this->pointers[$identifier]       = $this->pointer;

        ++$this->pointer;
    }

    /**
     * Build APNS payload
     *
     * @return string
     */
    public function payload()
    {
        $ret = '';
        foreach ($this->objects as $k=>$v) {
            $ret .= $v->payload(
                $this->tokens[$k],
                $this->identifiers[$k],
                $this->expiry[$k]
            );
        }

        return $ret;
    }

    /**
     * Get count of message objects
     *
     * @return int
     */
    public function length()
    {
        return count($this->objects);
    }

    /**
     * Get identifiers
     *
     * @return array
     */
    public function getIdentifiers()
    {
        return $this->identifiers;
    }

    /**
     * Remove all objects before specified identifier
     *
     * @param int $identifier
     * @param bool $skip
     * @return int
     */
    public function trimFrom($identifier, $skip=false)
    {
        $ptr = $this->pointer[$identifier];
        if ($skip) {
            $ptr += 1;
        }
        for ($i=0; $i<$ptr; $i++) {
            if (isset($this->objects[$i])) {
                unset(
                    $this->objects[$i],
                    $this->tokens[$i],
                    $this->expiry[$i],
                    $this->pointers[$this->identifiers[$i]],
                    $this->identifiers[$i]
                );
            }
        }

        return $this->length();
    }
}


# EOF