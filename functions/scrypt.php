<?php
class SCrypt {
	protected $key = '';
	protected $iv = '';
	
	public $encrypt_method = "AES-256-CBC";
	
	public function __construct($secret_key, $secret_iv) {
		$this->key = hash('sha256', $secret_key);
		$this->iv = substr(hash('sha256', $secret_iv), 0, 16);
	}
	
	public function encrypt($string) {
		$text = openssl_encrypt($string, $this->encrypt_method, $this->key, 0, $this->iv);
		$text = base64_encode($text);
		return $text;
	}
	
	public function decrypt($string) {
		$text = base64_decode($string);
		$text = openssl_decrypt($text, $this->encrypt_method, $this->key, 0, $this->iv);
		return $text;
	}
}