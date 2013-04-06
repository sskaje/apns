#APNS

simple apns client class & apns proxy class

Author: sskaje ([http://sskaje.me/](http://sskaje.me/))


##Files
	/class								Source folder
		/apns.inc.php					Init file for APNS 
		/apns.class.php					APNS class
		/message.class.php				APNS Message class
		/proxy.class.php				APNS Proxy class
		/proxy_client.class.php			APNS Proxy client class
		/daemon							Daemon class folder
			/default.daemon.php			Default daemon
			/simple.daemon.php			Simple daemon
	/api.php							Http api script
	/daemon.php							Daemon script
	/test								Test scripts
		/test.php						APNS test script
		/test_proxy_client.php			APNS Proxy client test script
	/proxy.example.ini					Example configuration file
	/README.md							this file

##Dependencies
    php 5.3+                      http://php.net/
    php-openssl
    redis server                  http://redis.io/
    phpredis                      https://github.com/nicolasff/phpredis


##Examples
###clients
```
>curl 'http://apns.rst.im/api.php?provider=example&user=sskaje&pass=zzddff' -d 'json=[{"token":"xxx","message":{"aps":{"alert":{"body":"你好，地球人"}}}}]'
>curl 'http://apns.rst.im/api.php?provider=example&user=sskaje&pass=zzddff' -d @1.json
>cat 1.json 
json=[{"token":"token","message":{"aps":{"alert":{"body":"aaa"}}}},{"token":"token","message":{"aps":{"alert":{"body":"adaa"}}}},{"token":"token","message":{"aps":{"alert":{"body":"aaa"}}}},{"token":"token","message":{"aps":{"alert":{"body":"affaa"}}}}]
```


###server
add following to crontab

```
*/1 * * * * /path/to/php/binary /path/to/daemon.php
``` 		


##Configurations
copy proxy.example.ini to proxy.ini



##For developers
###Create new daemon implementation
add your own daemon configuration like 

```
[daemon:YOUR_DAEMON_NAME]
key1=val1
key2=val2
```
create new file YOUR_DAEMON_NAME.php in class/daemon/
define a new class 

```
spAPNSProxyDaemon_YOUR_DAEMON_NAME extends spAPNSProxyDaemon
{
	protected $daemon_name = 'YOUR_DAEMON_NAME';
	protected $defaults	= array(
		'key1'	=>	default_val1,
		...
	);
	public function daemon()
	{
	    # implement your daemon here
	}
}
```


##\#EOF

