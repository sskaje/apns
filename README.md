#APNS

simple apns client class & apns proxy class

Author: sskaje ([http://sskaje.me/](http://sskaje.me/))


##Files
	/class                        Source folder
		/apns.class.php           APNS class
		/proxy.class.php          APNS Proxy class
		/proxy_client.class.php   APNS Proxy client class
	/api.php                      Http api script
	/daemon.php                   Daemon script
	/test                         Test scripts
		/test.php                 APNS test script
		/test_proxy_client.php    APNS Proxy client test script
	/proxy.example.ini            Example configuration file
	/README.md                    this file

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

