<?php
/**
 * Class spAPNSProxyDaemon_advanced
 * advanced daemon implemention
 *
 * @author sskaje
 */
class spAPNSProxyDaemon_advanced extends spAPNSProxyDaemon
{
    protected $daemon_name = 'advanced';
    protected $defaults = array(
        'run_as_daemon' => 0,
        'loop_limit' => 100000,
        'time_limit' => 75,
        'read_once'  => 100,
        'retry'      => 3,
    );

    public function daemon()
    {
        $c = 0;
        $time0 = microtime(1);

        $providers = $this->proxy->config->getAllProviders();

        $provider_apns_objs = array();

        $bundles = array();
        foreach ($providers as $k=>$v) {
            $provider_apns_objs[$k] = new spSimpleAPNS($v['cert_path'], $v['dev_mode']);
            $provider_apns_objs[$k]->setOption(spSimpleAPNS::OPT_BLOCKING_MODE, 0);
            $provider_apns_objs[$k]->setOption(spSimpleAPNS::OPT_CONNECT_ASYNC, 1);
            $provider_apns_objs[$k]->setOption(spSimpleAPNS::OPT_CONNECT_PERSISTENT, 1);
            $bundles[$k] = new spAPNSMessageBundle();
        }

        do {
            $l = 0;
            do {
                $val = $this->proxy->q->pop();
                if (!isset($val['provider'])) {
                    usleep(10000);
                    continue;
                }
                if (!isset($provider_apns_objs[$val['provider']])) {
                    $this->proxy->log(LOG_INFO, ' [ADVANCED] connection object for '.$val['provider'].' not found.', $val);
                    continue;
                }
                $msgobj = new spAPNSMessage($val['message']);

                $bundles[$val['provider']]->add($msgobj, $val['token'], $val['identifier'], $val['expiry']);

                $this->proxy->log(
                    LOG_INFO,
                    '[ADVANCED] ADD TO BUNDLE: PROVIDER='.$val['provider'].' TOKEN=' . $val['token'] . ' IDENTIFIER='.$val['identifier'].' EXPIRE='.$val['expiry'].' MSG=' . $msgobj->build(),
                    array()
                );

            } while(++$l < $this->config['read_once']);

            foreach ($bundles as $k=>$msgbndl) {
                if ($msgbndl->length()) {
                    $error_identifiers = $provider_apns_objs[$k]->pushBatch($msgbndl, $this->config['retry']);
                    # log
                    foreach ($error_identifiers as $identifier=>$status_code) {
                        $this->proxy->log(
                            LOG_ERR,
                            '[ADVANCED] ERROR RESPONSE: CODE='.$status_code . ' IDENTIFIER='.$identifier,
                            array()
                        );
                    }
                }

                $msgbndl->reset();
            }

        } while( $this->config['run_as_daemon']
            || ($c++ < $this->config['loop_limit'] && (microtime(1) - $time0) <= $this->config['time_limit']));
    }
}

# EOF