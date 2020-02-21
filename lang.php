<?php
class Langs {
	public $data;
	public $language;
	private $input;
	
	public function __construct(string $file, $default_language = 'en') {
		$this->input = $file;
		$json = file_get_contents($file);
		$this->data = json_decode($json);
		$this->language = $default_language;
	}
	
	public function __get($key) {
		$language = $this->language;
		return $this->data->$language->$key ?? $key;
	}
	
	public function __call($key, $replacements = []) {
		$language = $this->language;
		$string = $this->data->$language->$key ?? $key;
		return vsprintf($string, $replacements);
	}
	
	public function __isset($key) {
		$language = $this->language;
		return isset($this->data->$language->$key);
	}
	
	public function __set($key, $value) {
		$language = $this->language;
		$this->data->$language->$key = $value;
	}
	
	public function save($output = null) {
		if (!$output) {
			$output = $this->input;
		}
		return file_put_contents($output, json_encode($this->data));
	}
}