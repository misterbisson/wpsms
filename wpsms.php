<?php
/*
Plugin Name: wpSMS
Plugin URI: http://maisonbisson.com/projects/wpsms/
Description: An easy to use PHP class to send SMS messages. Does nothing on its own, but used by other plugins to <a href="http://maisonbisson.com/projects/wpsms/">do cool stuff</a>.
Version: 1.0.0a 
Author: Casey Bisson
Author URI: http://maisonbisson.com/blog/
*/

class wpSMS {
	/*
	Based on Clickatell SMS API v. 1.6 by Aleksandar Markovic <mikikg@gmail.com>
	Code re-used under the terms of the GPL license
	http://sourceforge.net/projects/sms-api/ SMS-API Sourceforge project page

	get API ID and account at http://www.clickatell.com/ :
	Clickatell documentation at https://www.clickatell.com/developers/api_http.php

	it's worth knowing the Clickatell privacy policy too:
	http://www.clickatell.com/company/privacy.php

	example usage:
	$mysms = new wpSMS( $API_ID, $USERNAME, $PASSWORD );
	$mysms->send( $YOUR_MESSAGE, $TO_PHONE_NUMBER );
	*/

	var $use_ssl = FALSE;
	var $balace_limit = 0;
	var $balance = FALSE;
	var $sending_method = 'fopen';
	var $unicode = FALSE;
	var $curl_use_proxy = FALSE;
	var $curl_proxy = 'http://127.0.0.1:8080';
	var $curl_proxyuserpwd = 'login:secretpass';
	var $session;
	var $error;
	var $callback = 0;
	var $msgstatuscodes = array(
		'001' => 'Message unknown. The message ID is incorrect or reporting is delayed.',
		'002' => 'Message queued. The message could not be delivered and has been queued for attempted redelivery.',
		'003' => 'Delivered to gateway.Delivered to the upstream gateway or network (delivered to the recipient).',
		'004' => 'Received by recipient. Confirmation of receipt on the handset of the recipient.',
		'005' => 'Error with message. There was an error with the message, probably caused by the content of the message itself.',
		'006' => 'User cancelled message delivery. The message was terminated by an internal mechanism.',
		'007' => 'Error delivering message. An error occurred delivering the message to the handset.',
		'008' => 'OK. Message received by gateway.',
		'009' => 'Routing error. The routing gateway or network has had an error routing the message.',
		'010' => 'Message expired. Message has expired before we were able to deliver it to the upstream gateway. No charge applies.',
		'011' => 'Message queued for later delivery. Message has been queued at the gateway for delivery at a later time (delayed delivery).',
		'012' => 'Out of credit. The message cannot be delivered due to a lack of funds in your account. Please re-purchase credits.'
	); // codes come from clickatell docs https://www.clickatell.com/downloads/http/Clickatell_HTTP.pdf

	function wpSMS( $api_id = '', $user = '', $password = '' ) {

		/* authentication details */	
		if(( !$api_id ) || ( !$user ) || ( !$password )){
			$this->error = 'You must specify an api id, username, and password.';
			return( FALSE );
		}
		$this->api_id = $api_id;
		$this->user = $user;
		$this->password = $password;

		/* SSL? */
		if( $this->use_ssl ) {
			$this->base	  = 'http://api.clickatell.com/http';
			$this->base_s = 'https://api.clickatell.com/http';
		} else {
			$this->base	  = 'http://api.clickatell.com/http';
			$this->base_s = $this->base;
		}

		$this->_auth();
	}

	function _auth() {
		$comm = sprintf( '%s/auth?api_id=%s&user=%s&password=%s', $this->base_s, $this->api_id, $this->user, $this->password );
		$this->session = $this->_parse_auth( $this->_execgw( $comm ));
	}

	function getbalance() {
		$comm = sprintf( '%s/getbalance?session_id=%s', $this->base, $this->session );
		$this->balance = $this->_parse_getbalance( $this->_execgw( $comm ));
		return $this->balance;
	}

	/* check the status of a message by the ID returned from the API */
	function querymsg( $msgid ) {
		$comm = sprintf( '%s/querymsg?session_id=%s&apimsgid=%s',
			$this->base,
			$this->session,
			$msgid
		);
		$result = $this->_execgw( $comm );
		if( $this->msgstatuscodes[ substr( $result, stripos( $result, 'Status:' ) + 8 ) ] )
			return( $this->msgstatuscodes[ substr( $result, stripos( $result, 'Status:' ) + 8 ) ]);
		else
			return( $result );
	}

	function send( $text=null, $to=null, $from=null ) {

		/* Check SMS credits balance */
		if( $this->getbalance() < $this->balace_limit ) {
			$this->error = 'You have reach the SMS credit limit!';
			return( FALSE );
		};

		/* Check SMS $text length */
		if( $this->unicode == TRUE ) {
			$this->_chk_mbstring();
			if( mb_strlen( $text ) > 210 ) {
				$this->error = 'Your unicode message is too long! (Current lenght='.mb_strlen ( $text ).')';
				return( FALSE );
			}
			/* Does message need to be concatenate */
			if( mb_strlen( $text ) > 70 ) {
				$concat = '&concat=3';
			} else {
				$concat = '';
			}
		} else {
			if( strlen( $text ) > 459 ) {
				$this->error = 'Your message is too long! (Current lenght='.strlen( $text ).')';
				return( FALSE );
			}
			/* Does message need to be concatenate */
			if( strlen( $text ) > 160 ) {
				$concat = '&concat=3';
			} else {
				$concat = '';
			}
		}

		/* Check $to is not empty */
		if( empty( $to )) {
			$this->error = 'You not specify destination address (TO)!';
			return( FALSE );
		}
		/* $from is optional and not universally supported */

		/* Reformat $to number */
		$cleanup_chr = array( '+', ' ', '(', ')', '\r', '\n', '\r\n');
		$to = str_replace( $cleanup_chr, '', $to );

		/* Mark this for later */
		$this->last_to = $to;
		$this->last_from = $from;
		$this->last_message = $text;

		/* Send SMS now */
		$comm = sprintf( '%s/sendmsg?session_id=%s&to=%s&from=%s&text=%s&callback=%s&unicode=%s%s',
			$this->base,
			$this->session,
			rawurlencode( $to ),
			rawurlencode( $from ),
			$this->encode_message( $text ),
			$this->callback,
			$this->unicode,
			$concat
		);
		return $this->_parse_send( $this->_execgw( $comm ));
	}

	function encode_message( $text ) {
		if( $this->unicode != TRUE ) {
			//standard encoding
			return rawurlencode( $text );
		} else {
			//unicode encoding
			$uni_text_len = mb_strlen( $text, 'UTF-8' );
			$out_text = '';

			//encode each character in text
			for( $i=0; $i<$uni_text_len; $i++ ) {
				$out_text .= $this->uniord( mb_substr( $text, $i, 1, 'UTF-8' ));
			}

			return $out_text;
		}
	}

	function uniord( $c ) {
		$ud = 0;
		if( ord( $c{0} )>=0 && ord( $c{0} )<=127 )
			$ud = ord( $c{0} );
		if( ord( $c{0} )>=192 && ord( $c{0} )<=223 )
			$ud = ( ord( $c{0} )-192 )*64 + ( ord( $c{1} )-128 );
		if( ord( $c{0} )>=224 && ord( $c{0} )<=239 )
			$ud = ( ord( $c{0} )-224 )*4096 + ( ord( $c{1} )-128 )*64 + ( ord( $c{2} )-128 );
		if( ord( $c{0} )>=240 && ord( $c{0} )<=247 )
			$ud = ( ord( $c{0} )-240 )*262144 + ( ord( $c{1} )-128 )*4096 + ( ord( $c{2} )-128 )*64 + ( ord( $c{3} )-128 );
		if( ord( $c{0} )>=248 && ord( $c{0} )<=251 )
			$ud = ( ord( $c{0} )-248 )*16777216 + ( ord( $c{1} )-128 )*262144 + ( ord( $c{2} )-128 )*4096 + ( ord( $c{3} )-128 )*64 + ( ord( $c{4} )-128 );
		if( ord( $c{0} )>=252 && ord( $c{0} )<=253 )
			$ud = ( ord( $c{0} )-252 )*1073741824 + ( ord( $c{1} )-128 )*16777216 + ( ord( $c{2} )-128 )*262144 + ( ord( $c{3} )-128 )*4096 + ( ord( $c{4} )-128 )*64 + ( ord( $c{5} )-128 );
		if( ord( $c{0} )>=254 && ord( $c{0} )<=255 ) //error
			$ud = FALSE;
		return sprintf( '%04x', $ud );
	}

	function token_pay( $token ) {
		$comm = sprintf( '%s/http/token_pay?session_id=%s&token=%s',
		$this->base,
		$this->session,
		$token );

		return $this->_execgw( $comm );
	}

	function _execgw( $command ) {
		if( $this->sending_method == 'curl' )
			return $this->_curl( $command );
		if( $this->sending_method == 'fopen' )
			return $this->_fopen( $command );
		$this->error = 'Unsupported sending method!';
		return( FALSE );
	}

	function _curl( $command ) {
		$this->_chk_curl();
		$ch = curl_init( $command );
		curl_setopt( $ch, CURLOPT_HEADER, 0 );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER,1 );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER,0 );
		if( $this->curl_use_proxy ) {
			curl_setopt( $ch, CURLOPT_PROXY, $this->curl_proxy );
			curl_setopt( $ch, CURLOPT_PROXYUSERPWD, $this->curl_proxyuserpwd );
		}
		$result=curl_exec( $ch );
		curl_close( $ch );
		return $result;
	}

	function _fopen( $command ) {
		$result = '';
		$handler = @fopen( $command, 'r' );
		if( $handler ) {
			while( $line = @fgets( $handler,1024 )) {
				$result .= $line;
			}
			fclose( $handler );
			return $result;
		} else {
			$this->error = 'Error while executing fopen sending method!<br>Please check does PHP have OpenSSL support and is PHP version is greater than 4.3.0.';
			return( FALSE );
		}
	}

	function _parse_auth( $result ) {
		$session = substr( $result, 4 );
		$code = substr( $result, 0, 2 );
		if( $code!='OK' ) {
			$this->error = "Error in SMS authorization! ($result)";
			return( FALSE );
		}
		return $session;
	}

	function _parse_send( $result ) {
		if( 'ID' <> substr( $result, 0, 2 )) {
			$this->error = "Error sending SMS! ($result)";
			$this->last_status = 'ERROR';
			return( FALSE );
		} else {
			$this->last_id = trim( substr( $result, 3 ));
			$this->last_status = 'OK';
			return( 'OK' );
		}
	}

	function _parse_getbalance( $result ) {
		$result = substr( $result, 8 );
		return (int ) $result;
	}

	function _chk_curl() {
		if( !extension_loaded( 'curl' )) {
			$this->error = 'This SMS API class can not work without CURL PHP module! Try using fopen sending method.';
			return( FALSE );
		}
	}

	function _chk_mbstring() {
		if( !extension_loaded( 'mbstring' )) {
			$this->error = 'Error. This SMS API class is setup to use Multibyte String Functions module - mbstring, but module not found. Please try to set unicode=false in class or install mbstring module into PHP.';
			return( FALSE );
		}
	}
}
?>
