<?php
/**
 * Simple implementation of daemon
 * blocking write on short connection
 * no error response processed
 *
 * @author sskaje
 */
class spAPNSProxyDaemon_simple extends spAPNSProxyDaemon
{
    protected $daemon_name = 'short';
    protected $defaults = array(
        'run_as_daemon' => 0,
        'loop_limit' => 100000,
        'time_limit' => 75,
        'non_blocking' => 1,
    );

    public function daemon()
    {
        $c = 0;
        $time0 = microtime(1);

        $providers = $this->proxy->config->getAllProviders();

        do {
            $val = $this->proxy->q->pop();
            if (!isset($val['provider'])) {
                usleep(100000);
                continue;
            }
            if (!isset($providers[$val['provider']])) {
                $this->proxy->log(LOG_INFO, ' [SIMPLE] config for '.$val['provider'].' not found.', $val);
                continue;
            }

            $retry = 0;
RETRY:
            $apnsobj = new spSimpleAPNS(
                $providers[$val['provider']]['cert_path'],
                $providers[$val['provider']]['dev_mode']
            );
            $apnsobj->setOption(spSimpleAPNS::OPT_BLOCKING_MODE, $this->config['non_blocking']);
            $apnsobj->setOption(spSimpleAPNS::OPT_CONNECT_ASYNC, 1);
            $apnsobj->setOption(spSimpleAPNS::OPT_CONNECT_PERSISTENT, 0);
            $connection = null;

            $msgobj = new spAPNSMessage($val['message']);
            $ret = $apnsobj->pushOne($msgobj, $val['token'], $val['identifier'], $val['expiry'], $connection);

            $this->proxy->log(
                LOG_INFO,
                '[SIMPLE] PUSH: PROVIDER='.$val['provider'].' TOKEN=' . $val['token'] . ' IDENTIFIER='.$val['identifier'].' EXPIRE='.$val['expiry'].' MSG=' . $msgobj->build() . ' RET=' . $ret . ' RETRY='.$retry,
                array()
            );

            # unset
            unset($apnsobj);

            # retry
            ++$retry;
            if ($ret === false && $retry<=3) {
                goto RETRY;
            }

        } while( $this->config['run_as_daemon'] ||
            ($c++ < $this->config['loop_limit'] && (microtime(1) - $time0) <= $this->config['time_limit']));
    }
}

# EOF