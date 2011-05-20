<?php

/*
 * This file is part of the afBenchmarkPlugin package.
 * 
 * (c) 2011 AppFlower ApS.
 * 
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * 
 * This class simulates a browser session via Curl.
 * It works with SSL or "normal" hosts, can perform POST or GET requests and returns the response body and headers.
 * It simulates XmlHttpRequest via headers.
 * 
 * @author Tamas Geschitz <tamas@appflower.com>
 * @see    http://www.appflower.com
 *
 */
class afCurlRequest {
	
	private 
		$status_data,
		$handle,
		$config,
		$response,
		$headers = array();
		
	
	const 
		NSLOOKUP = CURLINFO_NAMELOOKUP_TIME,
		CONNECT = CURLINFO_CONNECT_TIME,
		TRANSFER = -1,
		TRANSFER_START = CURLINFO_STARTTRANSFER_TIME ,
		TOTAL = CURLINFO_TOTAL_TIME ;
		

		
	/**
	 * 
	 * Contructor.. Initializes curl and config
	 * @param stdClass $config The configuration. Should reflect the contents of app.yml.
	 * 
	 * @throws sfCommandException
	 */
	public function __construct(stdClass $config = null)
  	{
  		
  		$root = ProjectConfiguration::getActive()->getPluginConfiguration("afBenchmarkPlugin")->getRootDir();
  		
  		if(!$this->handle = curl_init()) {
  			throw new sfCommandException("Couldn't initialize browser!");
  		}  else if(!file_exists($root."/config/http.ini")) {
  			throw new sfCommandException("Couldn't find http.ini in config dir!");
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
		curl_setopt($this->handle, CURLOPT_HEADERFUNCTION, array(&$this,"processHeaders"));
		curl_setopt($this->handle, CURLOPT_SSL_VERIFYHOST, 0);
		
		if(!$config) {
			$this->config->time_unit = "s";
			$this->config->size_unit = "B";
		} else {
			$this->config = $config;
		}
		
		curl_setopt($this->handle, CURLOPT_REFERER, $this->config->url); 
		
		
  	}
  	
  	/**
  	 * 
  	 * Callback function to process HTTP response headers (one at a time).
  	 * 
  	 * @param mixed $handle The curl resource
  	 * @param string $header The HTTP header.
  	 */
  	private function processHeaders($handle,$header) {
  	
  		if(strstr($header,":")) {
  			$k = strtok($header, ":");
  			$v = strtok(":");
  		} else {
  			$k = "status";
  			$v = $header;
  		}
  		
  		if(trim($header)) {
  			$this->headers[$k] = trim($v);
  		}
  		
  		return strlen($header);
  	}
  	
	/**
	 * 
	 * Returns the total request time in the selected time unit or as a number.
	 * 
	 * @param bool $numeric Whether to return time string or a number
	 * @return mixed
	 */
	private function getResponseTime($numeric = false,$type) {
		
		
		$value = curl_getinfo($this->handle,$type);
		
		switch($this->config->time_unit) {
	  		case "s":
	  			$res = sprintf("%1.2f",$value);
	  			break;
	  		case "ms":
	  			$res = round($value*1000);
	  			break;
	  	}
	  	
	  	return ($numeric) ? $res : $res.$this->config->time_unit;
		
	
	}
  	
  	/**
  	 * 
  	 * Preforms a HTTP request. 
  	 * 
  	 * @throws sfCommandException
  	 */
  	private function request() {
  		
  		$this->response = curl_exec($this->handle);
  		if(($err = curl_error($this->handle))) {
			throw new sfCommandException("An error has occured: ".$err);
		}
  	
  	}

  	/**
  	 * 
  	 * Returns all HTTP headers received in response. This is an array of headername => value pairs
  	 * @return Array
  	 */
	public function getHeaders() {
		
		return $this->headers;
		
	}

    public function getXDebugTokenHeaderValue()
    {
        return $this->headers['X-Debug-Token'];
    }
	
	/**
	 * 
	 * Sets parameters for GET request.
	 * 
	 * @param string $url The URL.
	 * @throws sfCommandException
	 */
	public function get($url) {
		
		if(!trim($url)) {
			throw new sfCommandException("The URL parameter is mandatory!");
		}
		
		curl_setopt($this->handle, CURLOPT_URL, $url);
		$this->request();
		
	}
  	
	/**
	 * 
	 * Sets parameters for POST request.
	 * 
	 * @param string $url The URL
	 * @param string $data The post body
	 * 
	 * @throws sfCommandException
	 */
	public function post($url,$data) {
		
		if(empty($data)) {
			throw new sfCommandException("At least 1 post parameter must be defined!");
		}
		
		curl_setopt($this->handle, CURLOPT_URL, $url);
		curl_setopt($this->handle, CURLOPT_POST, true);
		curl_setopt($this->handle, CURLOPT_POSTFIELDS, $data);
		$this->request();
	
	}
	
	/**
	 * 
	 * Returns HTTP status code
	 * 
	 * @return string
	 */
	public function getStatusCode() {
		
		return curl_getinfo($this->handle,CURLINFO_HTTP_CODE);
	
	}
	
	/**
	 * 
	 * Returns HTTP status message (via http.ini in config)
	 * 
	 * @return string
	 */
	public function getStatusMessage($code = null) {
		
		return $this->status_data[($code) ? $code : curl_getinfo($this->handle,CURLINFO_HTTP_CODE)];
	
	}
	
	
	/**
	 * 
	 * Returns content-type header or NULL if no valid content was sent by server
	 * 
	 * @return string or null
	 */
	public function contentSent() {
		
		return curl_getinfo($this->handle,CURLINFO_CONTENT_TYPE);
	
	}

	/**
	 * 
	 * Validates response. AJAX requests are only valid if JSON was returned.
	 * 
	 * @param bool $ajax Determine if it's an AJAX request
	 * @return bool
	 */
	public function isValidRequest($ajax = false) {
		
		$response = array(0=>"false",1=>"true");
		
		if(!$ajax) {
			return $response[$this->contentSent() && $this->getStatusCode() === 200 && $this->getResponseSize(true) !== 0];
		} else {
			return $response[$this->contentSent() && $this->getStatusCode() === 200 && is_object(json_decode($this->getResponseBody()))];
		}
		
	}
	
	/**
	 * 
	 * Returns Curl error message or empty string if there was no error.
	 * 
	 * @return string
	 */
	public function getError() {
		
		return curl_error($this->handle);
	
	} 
	
	
	/**
	 * 
	 * Destroys the Curl resource and returns a new instance.
	 * 
	 * @return afCurlRequest
	 */
	public function restart() {
		
		$this->shutdown();
		return new afCurlRequest($this->config);
	
	} 
	
	/**
	 * 
	 * Returns the response body size in the selected size unit or as a number.
	 * 
	 * @param bool $numeric Whether to return size string or a number
	 * @return mixed
	 */
	public function getResponseSize($numeric = false) {
	
		
		$value = curl_getinfo($this->handle,CURLINFO_SIZE_DOWNLOAD);
		
		switch($this->config->size_unit) {
			case "B":
				$res = $value;
				break;
			case "KB":
				$res = sprintf("%1.2f",$value / 1024);
				break;
		}
	  	
	  	return ($numeric) ? $res : $res.$this->config->size_unit;
		
	
	}
	
	/**
	 *  Returns average dowload speed in Bytes or Kbytes.
	 *  
	 */
	public function getDownloadSpeed() {
		
		$v = curl_getinfo($this->handle,CURLINFO_SPEED_DOWNLOAD);
		return ($this->config->size_unit == "B") ? $v : $v/1024; 
	
	}
	
	
	/**
	 * Returns the sum of name resolving (DNS) and connection times.
	 * 
	 * 
	 * @param boolean $numeric Whether or not just a number should be returned
	 */
	public function getConnectTime($numeric = false) {
		
		$v = $this->getResponseTime(true,CURLINFO_CONNECT_TIME) + $this->getResponseTime(true,CURLINFO_NAMELOOKUP_TIME);
	
		return ($numeric) ? $v : $v.$this->config->time_unit;
		
	}
	
	public function getTransferTime($numeric = false) {
		
		$res = ($this->getResponseTime(true,CURLINFO_TOTAL_TIME) -  $this->getResponseTime(true,CURLINFO_STARTTRANSFER_TIME));
		return ($numeric) ? $res : $res.$this->config->time_unit;
	
	}
	
	public function getServerTime($numeric = false) {
		
		return $this->getResponseTime($numeric,CURLINFO_STARTTRANSFER_TIME);
	
	}
	
	public function getTotalTime($numeric = false) {
		
		return $this->getResponseTime($numeric,CURLINFO_TOTAL_TIME);
	
	}
	
	
	/**
	 * Sends AJAX header to let the server know this is a XmlHttpRequest
	 * 
	 */
	public function ajaxOn() {
		
		curl_setopt($this->handle, CURLOPT_HTTPHEADER, array("X_REQUESTED_WITH: XMLHttpRequest")); 
	
	}
	
	/**
	 * 
	 * Removes AJAX header, normal request will be performed.
	 * 
	 */
	public function ajaxOff() {
		
		curl_setopt($this->handle, CURLOPT_HTTPHEADER, array("X_REQUESTED_WITH: 0")); 
	
	}
	
	
	/**
	 * 
	 * Destroys Curl resource.
	 * 
	 */
	public function shutdown() {
		
		curl_close($this->handle);
	
	}
	
	/**
	 * 
	 * Returns response body as a string
	 * 
	 * @return string
	 */
	public function getResponseBody() {
		return $this->response;
	}
    
	/**
	 * Checkes if there was AF redirection to login page
	 */
	public function checkForAuthenticationError() {
		$responseData = json_decode($this->getResponseBody(), true);
        
        if ($responseData) {
            if (@$responseData['redirect'] == '/login') {
                throw new Exception("You we're redirected to login page - check your credentials settings");
            }
        }
	}
}