<?php
class SessionAuthException extends RuntimeException{
	public function __construct($message = 'Unknown reason', $code = 0, Exception $previous = null){
		parent::__construct('Session could not be authenticated: ' . $message, $code, $previous);
	}
}

class MySQLSessionHandler implements SessionHandlerInterface{	
	private $link;
	private $cOptions = [
		'regen_interval' => 600,
		'idle_timeout' => 3600];
	
	private $curReqTime;
	private $reqSignature;
	
	private $sesInitTime;
	private $lastReqTime;
	private $sesExpireTime;
	private $numWrites;
	
	private $isNew = false;
	private $doExpire = false;
	
	public function __construct(mysqli $link, bool $autoInit = true, array $cOptions = []){
		$this->link = $link;
		$this->curReqTime = $_SERVER['REQUEST_TIME'];
		$this->reqSignature = md5($_SERVER['HTTP_USER_AGENT']);
		
		if( $autoInit ){
			session_set_save_handler($this);
		}
		
		/* TODO: $cOptions from args - possibly load from ini or from memory for ease of use/speed */
		
		return;
	}
	
	/* TODO: discuss get/set for $this->cOptions */
	/* TODO: get/set methods for custom client request signatures in a more meaningful way */
	
	public function getInitTime(){
		return $this->sesInitTime;
	}
	
	public function getCurReqTime(){
		return $this->curReqTime;
	}
	
	public function getLastRequestTime(){
		return $this->lastReqTime;
	}
	
	public function getExpireTime(){
		return $this->sesExpireTime;
	}
	
	public function getNumWrites(){
		return $this->numWrites;
	}
	
	public function setExpire(bool $doExpire = true){
		$this->doExpire = $doExpire;
		return;
	}
	
	public function isMarkedExpire(){
		return $this->doExpire;
	}
	
	public function start(array $options = []){
		try{
			@session_start($options);
			if( isset($this->cOptions['regen_interval']) && $this->sesInitTime < $this->curReqTime - $this->cOptions['regen_interval'] ){
				$this->doExpire = true;
				session_regenerate_id(false);
			}
		}catch(SessionAuthException $e){
			/* Unable to authenticate session - setting sid to null will create a new session without destroying the old (possibly hijacked) */
			session_id(null);
			session_start($options);
		}
	}
	
	public function open($savePath, $sessionName) : bool{
		/* mysqli->ping() returns null if connection has been closed */
		return @$this->link->ping() ?: false;
	}
	
	public function create_sid() : string{
		$checkCollision = session_status() == PHP_SESSION_ACTIVE;
		
		$sid_len = ini_get('session.sid_length');
		$sid_bpc = ini_get('session.sid_bits_per_character');
		$bytes_needed = ceil($sid_len * $sid_bpc / 8);
		$mask = (1 << $sid_bpc) - 1;
		$out = '';
		
		$hexconvtab = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ,-';
		
		$attempts = 0;
		$maxAttempts = 5;
		/* DISCUSS: adding maxAttempts to cOptions to give more control - keeping in mind that very rarely if ever will a collision occur */
		do{
			if( $attempts >= $maxAttempts ){
				throw new Exception('Could not generate non-colliding sid after ' . $maxAttempts . ' attempts');
			}
			$random_input_bytes = random_bytes($bytes_needed);
			
			$p = 0;
			$q = strlen($random_input_bytes);
			$w = 0;
			$have = 0;
			
			$chars_remaining = $sid_len;
			while( $chars_remaining-- ){
				if( $have < $sid_bpc ){
					if( $p < $q ) {
						$byte = ord($random_input_bytes[$p++]);
						$w |= ($byte << $have);
						$have += 8;
					}else{
						break;
					}
				}
				$out .= $hexconvtab[$w & $mask];
				$w >>= $sid_bpc;
				$have -= $sid_bpc;
			}
			$attempts++;
		}while( $checkCollision && $this->sessionExists($out) );
		
		$this->isNew = true;
		
		return $out;
	}
	
	public function validateId(string $sid) : bool{
		/* Validate ID is called after create_sid, create_sid already checks for collision */
		return $this->isNew ?: $this->sessionExists($sid);
	}
	
	private function sessionExists(string $sid) : bool{
		$sid = $this->link->escape_string($sid);
		
		$result = $this->link->query('SELECT 1 FROM `sessions` WHERE `session_id` = \'' . $sid . '\';');
		
		if( !$result ){
			throw new Exception('Could not determine if session exists: query failed');
		}
		
		return $result->num_rows;
	}
	
	public function read($sid) : string{
		if( $this->isNew ){
			/* New session created from self */
			$this->sesInitTime = $this->curReqTime;
			$this->lastReqTime = null;
			$this->sesExpireTime = null;
			$this->numWrites = 0;
			$out = '';
		}elseif( ($result = $this->querySession($sid)) ){
			/* Existing session - validate now */
			if( $result['request_signature'] && $this->reqSignature !== $result['request_signature'] ){
				throw new SessionAuthException('Client request signature mismatch');
			}elseif( $result['expire_unixtime'] && $result['expire_unixtime'] < $this->curReqTime ){
				throw new SessionAuthException('Session is expired');
			}
			/* Valid session did not throw */
			$this->sesInitTime = $result['init_unixtime'];
			$this->lastReqTime = $result['last_request_unixtime'];
			$this->sesExpireTime = $result['expire_unixtime'];
			$this->numWrites = $result['writes'];
			$out = $result['data'];
		}else{
			/* New session initialized elsewhere - potentially unsafe, but still no collision */
			trigger_error('Potentially unsafe read from uninitialized session: see "session.use_strict_mode"', E_USER_WARNING);
			$this->isNew = true;
			$this->sesInitTime = $this->curReqTime;
			$this->lastReqTime = null;
			$this->sesExpireTime = null;
			$this->numWrites = 0;
			$out = '';
		}
		
		return $out;
	}
	
	private function querySession(string $sid) : ?array{
		$sid = $this->link->escape_string($sid);
		
		$result = $this->link->query('SELECT * FROM sessions WHERE session_id = \'' . $sid . '\';');
		
		if( !$result ){
			throw new Exception('Failed to import session: query failed');
		}
		
		return $result->num_rows ? $result->fetch_assoc() : null;
	}
	
	public function write($sid, $data) : bool{
		/* Determine expire unixtime */
		if( $this->doExpire ){
			$expireTime = 0;
		}elseif( is_int($this->cOptions['idle_timeout']) ){
			$expireTime = $this->curReqTime + $this->cOptions['idle_timeout'];
		}else{
			$expireTime = 'null';
		}
		
		$sid = $this->link->escape_string($sid);
		$reqSignature = $this->link->escape_string($this->reqSignature);
		$data = $this->link->escape_string($data);
		
		if( $this->isNew ){
			$this->link->query(
				'INSERT INTO sessions (session_id, init_unixtime, last_request_unixtime, expire_unixtime, request_signature, writes, data) '.
				'VALUES(\'' . $sid . '\', ' . $this->curReqTime . ', init_unixtime, ' . $expireTime . ', \'' . $reqSignature . '\', 1, \'' . $data . '\');');
		}else{
			$this->link->query(
				'UPDATE sessions '.
				'SET last_request_unixtime = ' . $this->curReqTime . ', expire_unixtime = ' . $expireTime . ', request_signature = \'' . $reqSignature . '\', writes = writes + 1, data = \'' . $data . '\' '.
				'WHERE session_id = \'' . $sid . '\';');
		}
		
		return $this->link->affected_rows > 0;
	}
	
	public function gc($maxLifetime) : bool{
		return $this->link->query('DELETE FROM sessions WHERE expire_unixtime <= ' . $this->curReqTime . ';');
	}
	
	public function close() : bool{
		$sesInitTime = null;
		$lastReqTime = null;
		$sesExpireTime = null;
		$numWrites = null;
		$this->isNew = false;
		$this->doExpire = false;
		/* Keep connection open for use - in case new session_start() or in the case of session_regenerate_id() */
		return true;
	}
	
	public function destroy($sid) : bool{
		$sid = $this->link->escape_string($sid);
		
		$this->link->query('DELETE FROM sessions WHERE session_id = \'' . $sid . '\';');
		
		return $this->link->affected_rows > 0;
	}
	
	public function __destruct(){
		/* This will not be called in the case of Exception - resource handle will persist until PHP GC happens */
		@$this->link->close();
	}
}

?>
