<?php
namespace Zotlabs\Lib;

class DReport {

	private $location;
	private $sender;
	private $recipient;
	private $message_id;
	private $status;
	private $date;

	function __construct($location,$sender,$recipient,$message_id,$status = 'deliver') {
		$this->location   = $location;
		$this->sender     = $sender;
		$this->recipient  = $recipient;
		$this->name       = EMPTY_STR;
		$this->message_id = $message_id;
		$this->status     = $status;
		$this->date       = datetime_convert();
	}

	function update($status) {
		$this->status     = $status;
		$this->date       = datetime_convert();
	}

	function set_name($name) {
		$this->name = $name;
	}

	function addto_update($status) {
		$this->status = $this->status . ' ' . $status;
	}


	function set($arr) {
		$this->location   = $arr['location'];
		$this->sender     = $arr['sender'];
		$this->recipient  = $arr['recipient'];
		$this->name       = $arr['name'];
		$this->message_id = $arr['message_id'];
		$this->status     = $arr['status'];
		$this->date       = $arr['date'];
	}

	function get() {
		return array(
			'location'   => $this->location,
			'sender'     => $this->sender,
			'recipient'  => $this->recipient,
			'name'       => $this->name,
			'message_id' => $this->message_id,
			'status'     => $this->status,
			'date'       => $this->date
		);
	}
}
