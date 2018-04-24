# MySQLSessionHandler
PHP(7.1) SessionHandler [using MySQL for storage] with built-in tracking and security without changing native session mechanics.

Includes automatic session regeneration/expiration and some very minor client authentication to help prevent session hijacking (session settings will still need to be properly configured; http://php.net/manual/en/session.security.php).

## Requirements:
PHP >= v7.1

MySQL Table definition for PHP(64-bit):
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

## Usage:
### Class method for starting a session (handling built-in):
```php
$sesHandler = new MySQLSessionHandler(new mysqli('hostName', 'user', 'password', 'dbn'));

/* Use start method for automatic error handling when authentication errors occur */
$sesHandler->start();
$_SESSION['test'] = 'hello world';
session_write_close();
```

### Native session_start() with handling example:
```php
const REGEN_INTERVAL_SEC = 600;

$sesHandler = new MySQLSessionHandler(new mysqli('hostName', 'user', 'password', 'dbn'));

/* Example using native session_start with manual controls - NOTE: this example does exactly what MySQLSessionHandler::start() does */
try{
  session_start();
  if( $sesHandler->getInitTime < ($sesHandler->getCurReqTime() - REGEN_INTERVAL_SEC) ){
    /* Preserve session date while marking session expired */
    $sesHandler->setExpire(true);
    session_regenerate_id(false);
  }
}catch(SessionAuthException $e){
  session_id(null);
  session_start();
}

$_SESSION['test'] = 'hello world';
session_write_close();
```

## Discuss:
1.) Handler can be slow because of database bottleneck (database access accounts for approximately 80-90% of execution time). This handler works great for low traffic sites (< 100 requests/sec) and the added benefits should be obvious. High traffic sites should use another solution such as memcached. The security mechanisms in this class can easily be adapted to use other storage methods.

## Author(s):
Phillip Weber
