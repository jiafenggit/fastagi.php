<?php

require dirname( __FILE__ ).'/traits/verbose.php';

abstract class _FASTAGI {
	use verbose;

	const ST_INIT = 0x01;	// new connection => read chanvars
	const ST_RECV = 0x02;	// writing command done => read responce
	const ST_SEND = 0x04;	// command is set to buffer => write buffer


	protected $sock = null;
	protected $flag = false;	// reindex is nessesary
	protected $vvvv = -1;		// verbosity level

	protected $vars = [];		// status, buffer, chanvars, event version
	protected $conn;

	final public function __construct( $host, $port ) {
		$this->vinit();
		$sock = @stream_socket_server( 'tcp://'.$host.':'.$port, $errno, $errstr );
		if ( ! $sock ) {
			echo $errstr.PHP_EOL;
			exit -1;
		}
		$this->connect( $sock );
		$this->sock = &$this->conn[0];
		$this->init();
		$this->worker();
	}

	final protected function worker() {
		$e = $w = null;
		while ( 1 ) {
			if ( $this->flag ) $this->reindex();
			$r = $this->conn;
			$this->say( 4, '.' );

			if ( ! stream_select( $r, $w, $e, null ) ) break;
			
			// check incoming connection
			if ( isset( $r[0] ) ) {
				$this->connect( stream_socket_accept( $this->sock, -1) );
				unset( $r[0] );
			}

			// processing sockets
			foreach ( $r as $k => $sock ) {
				$a = &$this->vars[$k];
				$buf = fread( $sock, 1024 );
				if ( ! $buf ) {
					$this->say( 0, '#'.$k.' Read error' );
					$this->disconnect( $k );
					continue;
				}
				$a['buffer'] .= $buf;
				if ( $a['status'] == self::ST_INIT && ( strpos( $a['buffer'], "\n\n" ) !== false ) ) {
					$a['chanvars'] = $this->parse( $a['buffer'] );
					$this->say( 2, '#'.$k.' Argument parsing done, executing callback...' );
					$cmd = $this->action( $a['version']++, [ 200, 0 ], $a['chanvars'] );
					$a['buffer'] = ( $cmd === null ) ? '' : $cmd ;
					$a['status'] = self::ST_SEND;
					$this->say( 2, '#'.$k.' Write buffer is set: '.$a['buffer'] );
				} elseif ( $a['status'] == self::ST_RECV && ( strpos( $a['buffer'], "\n" ) !== false ) ) {
					if ( $a['buffer'] == "HANGUP\n" ) {
						$this->say( 2, '#'.$k.' Caller hangs up' );
						$this->disconnect( $k );
						continue;
					} elseif ( preg_match( '/^(\d+)\s(?:result=)?(.*?)\s+/', $a['buffer'], $r ) ) {
						$this->say( 2, '#'.$k.' Responce recieved: '.$r[1].': '.$r[2].', executing callback...' );
						$cmd = $this->action( $a['version']++, [ $r[1], $r[2] ], $a['chanvars'] );
						$a['buffer'] = ( $cmd === null ) ? '' : $cmd ;
						$a['status'] = self::ST_SEND;
						$this->say( 2, '#'.$k.' Write buffer is set: '.$a['buffer'] );
					} else {
						$this->say( 2, '#'.$k.' Undefined response: "'.trim( $a['buffer'] ).'"' );
						$this->disconnect( $k );
						continue;
					}
				}
				if ( $a['status'] == self::ST_SEND ) {
					if ( ! $a['buffer'] ) {
						$this->say( 2, '#'.$k.' Nothing to write' );
						$this->disconnect( $k );
						continue;
					}
					$buf = fwrite( $sock, $a['buffer']."\n" );
					if ( ! $buf ) {
						$this->say( 0, 'Write error' );
						$this->disconnect( $k );
						continue;
					}
					$a['buffer'] = substr( $a['buffer'], $buf - 1 );
					if ( ! $a['buffer'] ) {
						$a['status'] = self::ST_RECV;
						$this->say( 2, '#'.$k.' Writing done, waiting for responce' );
					}
				}
			}
		}
	}

	final protected function connect( $pt ) {
		$i = count( $this->conn );
		if ( $i > 0 ) $this->say( 1, '#'.$i.' Accepting connection' );
		$this->conn[$i] = $pt;
		$this->vars[$i] = [ 'status' => self::ST_INIT, 'buffer' => '', 'chanvars' => [], 'version' => 0 ];
	}

	final protected function disconnect( $i ) {
		$this->say( 1, '#'.$i.' Closing connection' );
		fclose( $this->conn[$i] );
		unset( $this->conn[$i] );
		unset( $this->vars[$i] );
		$this->flag = true;
	}
	
	final protected function reindex() {
		$this->say( 1, 'Reindexing' );
		$this->conn = array_values( $this->conn );
		$this->vars = array_values( $this->vars );
		$this->flag = false;
	}

	final protected function parse( $s ) {
		$fff = [];
		$tmp = explode( "\n", $s );
		foreach ( $tmp as $v ) {
			if ( preg_match( '/^agi_(.*?): (.*)$/', $v, $r ) ) {
				$fff[$r[1]] = $r[2];
			}
		}
		return $fff;
	}
	
	final protected function message( $level, $string ) {
		if ( $level < 0 ||  $level > $this->vvvv ) return false;
		switch ( $level ) {
			case 0:
				$string = '[error]   '.$string;
				break;
			case 1:
				$string = '[notice]  '.$string;
				break;
			case 2:
				$string = '[debug]   '.$string;
				break;
			case 3:
				$string = '[extra]   '.$string;
				break;
		}
		echo $string.PHP_EOL;
	}
	
	function init(){}
	abstract function action( $v, $status, &$chanvars );
}
