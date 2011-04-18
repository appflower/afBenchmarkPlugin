<?php

class afCurlRequest {
	
	protected 
		$status_data,
		$handle,
		$config,
		$response;
		
		
	public function __construct(stdClass $config = null)
  	{
  		
  		$root = ProjectConfiguration::getActive()->getPluginConfiguration("afBenchmarkPlugin")->getRootDir();
  		
  		if(!$this->handle = curl_init()) {
  			throw new Exception("Couldn't initialize browser!");
  		}
  
  		$this->status_data = parse_ini_file($root."/config/http.ini");
  		
  		curl_setopt($this->handle, CURLOPT_RETURNTRANSFER, true);
  		curl_setopt($this->handle, CURLOPT_SSL_VERIFYPEER, FALSE);
		curl_setopt($this->handle, CURLOPT_COOKIESESSION, true);
		curl_setopt($this->handle, CURLOPT_HEADER, 0);
		curl_setopt($this->handle, CURLOPT_COOKIEFILE, $root."/data/cookiefile");
		curl_setopt($this->handle, CURLOPT_COOKIEJAR, $root."/data/cookiefile");
		curl_setopt($this->handle, CURLOPT_COOKIE, session_name() . '=' . session_id());
		curl_setopt($this->handle, CURLOPT_FOLLOWLOCATION, 1); 
		curl_setopt($this->handle, CURLOPT_USERAGENT, "AF Benchmark"); 
		curl_setopt($this->handle, CURLOPT_CONNECTTIMEOUT, 30);
		
		if(!$config) {
			$this->config->time_unit = "s";
			$this->config->size_unit = "B";
		} else {
			$this->config = $config;
		}
		
		curl_setopt($this->handle, CURLOPT_REFERER, $this->config->url); 
		
		
  	}

	public function get($url) {
		
		if(!trim($url)) {
			throw new Exception("The URL parameter is mandatory!");
		}
		
		curl_setopt($this->handle, CURLOPT_URL, $url);
		$this->response = curl_exec($this->handle);
		
	}
  	
	
	public function getFoo($url) {
		
		curl_setopt($this->handle, CURLOPT_COOKIESESSION, TRUE);
		
	}
	
	public function post($url,$data) {
		
		if(empty($data)) {
			throw new Exception("At least 1 post parameter must be defined!");
		}
		
		curl_setopt($this->handle, CURLOPT_URL, $url);
		curl_setopt($this->handle, CURLOPT_POST, true);
		curl_setopt($this->handle, CURLOPT_POSTFIELDS, $data);
		$this->response = curl_exec($this->handle);
	
	}
	
	public function getStatusCode() {
		
		return curl_getinfo($this->handle,CURLINFO_HTTP_CODE);
	
	}
	
	public function getStatusMessage() {
		
		return $this->status_data[curl_getinfo($this->handle,CURLINFO_HTTP_CODE)];
	
	}
	
	
	public function isValid() {
		
		return curl_getinfo($this->handle,CURLINFO_CONTENT_TYPE);
	
	} 
	
	public function getResponseSize() {
	
		
		$value = curl_getinfo($this->handle,CURLINFO_SIZE_DOWNLOAD);
		
		switch($this->config->size_unit) {
			case "B":
				$res = $value;
				break;
			case "KB":
				$res = sprintf("%1.2f",$value / 1024);
				break;
		}
	  	
	  	return $res.$this->config->size_unit;
		
	
	}
	
	public function getResponseTime() {
		
		
		$value = curl_getinfo($this->handle,CURLINFO_TOTAL_TIME);
		
		switch($this->config->time_unit) {
	  		case "s":
	  			$res = sprintf("%1.2f",$value);
	  			break;
	  		case "ms":
	  			$res = round($value*1000);
	  			break;
	  	}
	  	
	  	return $res.$this->config->time_unit;
		
	
	}
	
	public function ajaxOn() {
		
		curl_setopt($this->handle, CURLOPT_HTTPHEADER, array("X_REQUESTED_WITH: XMLHttpRequest")); 
	
	}
	
	public function ajaxOff() {
		
		curl_setopt($this->handle, CURLOPT_HTTPHEADER, array("X_REQUESTED_WITH: 0")); 
	
	}
	
	
	public function shutdown() {
		
		curl_close($this->handle);
	
	}
	
	public function getResponse() {
		return $this;
	}
	
	public function getResponseBody() {
		return $this->response;
	}
	
	
}