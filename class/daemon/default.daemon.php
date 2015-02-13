<?php
/**
 * Class spAPNSProxyDaemon_default
 * default daemon implemention
 *
 * @author sskaje
 */
class spAPNSProxyDaemon_default extends spAPNSProxyDaemon
{
    protected $daemon_name = 'default';
    protected $defaults = array(
        'run_as_daemon' => 0,
        'loop_limit' => 100000,
        'time_limit' => 75,
    );

    public function daemon()
    {
        $c = 0;
        $time0 = microtime(1);

        $providers = $this->proxy->config->getAllProviders();

        $provider_apns_objs = array();

        foreach ($providers as $k=>$v) {
            $provider_apns_objs[$k] = new spSimpleAPNS($v['cert_path'], $v['cert_pass'], $v['dev_mode']);
            $provider_apns_objs[$k]->setOption(spSimpleAPNS::OPT_BLOCKING_MODE, 0);
            $provider_apns_objs[$k]->setOption(spSimpleAPNS::OPT_CONNECT_ASYNC, 1);
            $provider_apns_objs[$k]->setOption(spSimpleAPNS::OPT_CONNECT_PERSISTENT, 1);
        }

        do {
            $val = $this->proxy->q->pop();
            if (!isset($val['provider'])) {
                usleep(10000);
                continue;
            }
            if (!isset($provider_apns_objs[$val['provider']])) {
                $this->proxy->log(LOG_INFO, ' [DAEMON] connection object for '.$val['provider'].' not found.', $val);
                continue;
            }

            $msgobj = new spAPNSMessage($val['message']);

            $retry = 0;
            do {
                # non-blocking push
                try {
                    $ret = $provider_apns_objs[$val['provider']]->pushOne($msgobj, $val['token'], $val['identifier'], $val['expiry']);
                } catch (Exception $e) {
                    $this->proxy->log(
                        LOG_EMERG,
                        '[DAEMON] Exception: Code='.$e->getCode() . ' Message='.$e->getMessage() . " Provider=" . $val['provider'],
                        array()
                    );
                    # make ret looks like true
                    $ret = 'ERROR';
                }

                # reconnect on false
                if (!$ret) {
                    $this->proxy->log(
                        LOG_INFO,
                        '[DAEMON] PUSH Failed: PROVIDER='.$val['provider'].' TOKEN=' . $val['token'] . ' IDENTIFIER='.$val['identifier'].' EXPIRE='.$val['expiry'].' MSG=' . $msgobj->build() . ' RET=' . $ret . ' RETRY='.$retry,
                        array()
                    );

                    # close connection
                    $provider_apns_objs[$val['provider']]->close();
                } else {
                    $this->proxy->log(
                        LOG_INFO,
                        '[DAEMON] PUSH: PROVIDER='.$val['provider'].' TOKEN=' . $val['token'] . ' IDENTIFIER='.$val['identifier'].' EXPIRE='.$val['expiry'].' MSG=' . $msgobj->build() . ' RET=' . $ret . ' RETRY='.$retry,
                        array()
                    );

                    break;
                }

                $retry++;
            } while($retry <= 3);

			try {
            	# non-blocking read
            	$error_response = $provider_apns_objs[$val['provider']]->readErrorResponse();
				if ($error_response) {
					$this->proxy->log(
						LOG_ERR,
						'[DAEMON] ERROR RESPONSE: CODE='.$error_response['code'] . ' IDENTIFIER='.$error_response['identifier'],
						array()
					);
					$provider_apns_objs[$val['provider']]->close();
				}
			} catch (Exception $e) {
				$this->proxy->log(
					LOG_EMERG,
					'[DAEMON] Exception: Code='.$e->getCode() . ' Message='.$e->getMessage() . " Provider=" . $val['provider'],
					array()
				);
			}
            
        } while( $this->config['run_as_daemon']
            || ($c++ < $this->config['loop_limit'] && (microtime(1) - $time0) <= $this->config['time_limit']));

        # an extra 10 seconds for error response packets
        $time0 = microtime(1);
        do {
            foreach ($provider_apns_objs as $k=>$v) {
                try{
                    $error_response = $v->readErrorResponse();
                } catch (Exception $e) {
                    $this->proxy->log(
                        LOG_EMERG,
                        '[DAEMON] Exception: Code='.$e->getCode() . ' Message='.$e->getMessage() . " Provider=" . $k,
                        array()
                    );
                }
                if ($error_response) {
                    $this->proxy->log(
                        LOG_ERR,
                        '[DAEMON] ERROR RESPONSE: CODE='.$error_response['code'] . ' IDENTIFIER='.$error_response['identifier'],
                        array()
                    );
                }
            }
            usleep(100000);
        } while(!$this->config['run_as_daemon'] && (microtime(1) - $time0) <= 10);
    }
}

# EOF
