<?php

namespace Zotlabs\Zot6;

use Zotlabs\Lib\Config;
use Zotlabs\Lib\Libzot;
use Zotlabs\Web\HTTPSig;

class Receiver {

	protected $data;
	protected $encrypted;
	protected $error;
	protected $messagetype;
	protected $sender;
	protected $site_id;
	protected $validated;
	protected $recipients;
	protected $response;
	protected $handler;
	protected $prvkey;
	protected $rawdata;

	function __construct($handler, $localdata = null) {

		$this->error       = false;
		$this->validated   = false;
		$this->messagetype = '';
		$this->response    = [ 'success' => false ];
		$this->handler     = $handler;
		$this->data        = null;
		$this->rawdata     = null;
		$this->site_id     = null;
		$this->prvkey      = Config::get('system','prvkey');

		if($localdata) {
			$this->rawdata = $localdata;
		}
		else {
			$this->rawdata = file_get_contents('php://input');

			// All access to the zot endpoint must use http signatures

			if (! $this->Valid_Httpsig()) {
				logger('signature failed');
				http_status_exit(400);
			}
		}

		logger('received raw: ' . print_r($this->rawdata,true), LOGGER_DATA);


		if ($this->rawdata) {
			$this->data = json_decode($this->rawdata,true);
		}
		else {
			$this->error = true;
			$this->response['message'] = 'no data';
		}

		logger('received_json: ' . json_encode($this->data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), LOGGER_DATA);


		logger('received: ' . print_r($this->data,true), LOGGER_DATA);

		if ($this->data && is_array($this->data)) {
			$this->encrypted = ((array_key_exists('encrypted',$this->data) && intval($this->data['encrypted'])) ? true : false);

			if ($this->encrypted && $this->prvkey) {
				$uncrypted = crypto_unencapsulate($this->data,$this->prvkey);
				if ($uncrypted) {
					$this->data = json_decode($uncrypted,true);
				}
				else {
					$this->error = true;
					$this->response['message'] = 'no data';
				}
			}
		}
	}


	function run() {

		if ($this->error) {
			// make timing attacks on the decryption engine a bit more difficult
			usleep(mt_rand(10000,100000));
			return($this->response); 
		}

		if ($this->data) {
			if (array_key_exists('type',$this->data))
				$this->messagetype = $this->data['type'];

			if (! $this->messagetype) {
				$this->error = true;
				$this->response['message'] = 'no datatype';
			}

			$this->sender     = ((array_key_exists('sender',$this->data))     ? $this->data['sender'] : null);
			$this->recipients = ((array_key_exists('recipients',$this->data)) ? $this->data['recipients'] : null);
			$this->site_id    = ((array_key_exists('site_id',$this->data))    ? $this->data['site_id'] : null);
		}

		if ($this->sender) {
			$result = $this->ValidateSender();
			if(! $result) {
				return $this->response;
			}
		}

		return $this->Dispatch();
	}

	function ValidateSender() {

		$hub = Libzot::valid_hub($this->sender,$this->site_id);

		if (! $hub) {
           	$this->response['message'] = 'Hub not available.';
			return false;
		}

		Libzot::update_hub_connected($hub,$this->site_id);

		$this->validated = true;
		$this->hub = $hub;
		return true;
    }


	function Valid_Httpsig() {

		$result = false;

		$verified = HTTPSig::verify($this->rawdata);
		if($verified && $verified['header_signed'] && $verified['header_valid']) {
			$result = true;
			$this->portable_id = $verified['portable_id'];

			// It is OK to not have signed content - not all messages provide content.
			// But if it is signed, it has to be valid

			if (($verified['content_signed']) && (! $verified['content_valid'])) {
					$result = false;
			}
		}
		return $result;
	}	
		
	function Dispatch() {

		if (! $this->validated) {
			$this->response['message'] = 'Sender not valid';
			return($this->response); 
		}

		switch ($this->messagetype) {

			case 'request':
				$this->response = $this->handler->Request($this->data,$this->hub);
				break;

			case 'purge':
				$this->response = $this->handler->Purge($this->sender,$this->recipients,$this->hub);
				break;

			case 'refresh':
				$this->response = $this->handler->Refresh($this->sender,$this->recipients,$this->hub);
				break;

			case 'rekey':
				$this->response = $this->handler->Rekey($this->sender, $this->data,$this->hub);
				break;

			case 'notify':
			default:
				$this->response = $this->handler->Notify($this->data,$this->hub);
				break;

		}

		if ($this->encrypted) {
			$this->EncryptResponse();
		}

		return($this->response); 
	}

	function EncryptResponse() {
		$algorithm = self::best_algorithm($this->hub['site_crypto']);
		if($algorithm) {
			$this->response = crypto_encapsulate(json_encode($this->response),$this->hub['hubloc_sitekey'], $algorithm);
		}
	}

}



