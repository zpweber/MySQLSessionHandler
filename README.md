# MySQLSessionHandler
PHP(7.1) SessionHandler interface using MySQLi

## Requirements
PHP >= v7.1

### MySQL Table definition for PHP(64-bit)...
```sql
CREATE TABLE `sessions` (
  `session_id` char(48) NOT NULL,
  `init_unixtime` bigint(20) unsigned NOT NULL,
  `last_request_unixtime` bigint(20) unsigned NOT NULL,
  `expire_unixtime` bigint(20) unsigned DEFAULT NULL,
  `request_signature` varchar(96) DEFAULT NULL,
  `writes` int(4) unsigned DEFAULT NULL,
  `data` text,
  PRIMARY KEY (`session_id`),
  UNIQUE KEY `session_id_UNIQUE` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
```

## Usage
```php
$sesHandler = new MySQLSessionHandler(new mysqli(HOSTNAME, USERNAME, PASSWORD, DBN));

/* TEST */
ini_set('session.use_strict_mode', 0);
$sesHandler->start();

echo 'Init time: ' . $sesHandler->getInitTime() . '<br/>';
echo 'Last Req. time: ' . $sesHandler->getLastRequestTime() . '<br/>';
echo 'Expire time: ' . $sesHandler->getExpireTime() . '<br/>';
echo 'Writes: ' . $sesHandler->getNumWrites() . '<br/>';

if( !isset($_SESSION['test'] ){
  $_SESSION['test'] = 0;
}else{
  $_SESSION['test'] += 1;
}

echo 'TEST: ' . $_SESSION['test'] . '<br/>';

session_write_close();
```
## Authors
Phillip Weber

## Contributors
