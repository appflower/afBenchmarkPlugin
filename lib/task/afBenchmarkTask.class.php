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
 * @author   Tamas Geschitz <tamas@appflower.com>
 * @see      http://www.appflower.com
 */

/**
 * This task runs one or more widgets and generates benchmarking results, optionally with profiling details.
 */
class afBenchmarkTask extends sfBaseTask
{
	
  private 
  	$widgets = array(),
  	$context,
	$profiledata,
	$browser,
	$totals = array("totaltime" => 0),
  	$stamp,
  	$processed = array(),
  	$config,
  	$maxwidth = 60;
	
  /**
   * Configures task.
   */
  public function configure()
  {
  	
  	$this->addArguments(array(
  	  new sfCommandArgument('widget', sfCommandArgument::OPTIONAL, 'The URI of the widget to execute','*'),
  	));
    
  	$this->addOptions(array(
  	  new sfCommandOption('params', null, sfCommandOption::PARAMETER_OPTIONAL, 'Optional request parameters', ''),
  	  new sfCommandOption('profiling', null, sfCommandOption::PARAMETER_OPTIONAL, 'Whether to collect profiling data', ''),
  	  new sfCommandOption('username', null, sfCommandOption::PARAMETER_OPTIONAL, 'User login', ''),
  	  new sfCommandOption('password', null, sfCommandOption::PARAMETER_OPTIONAL, 'User password', ''),
      new sfCommandOption('url', null, sfCommandOption::PARAMETER_OPTIONAL, 'The URL of the AF appliance', ''),
      new sfCommandOption('time_unit', null, sfCommandOption::PARAMETER_OPTIONAL, 'Show execution times as..', ''),
      new sfCommandOption('size_unit', null, sfCommandOption::PARAMETER_OPTIONAL, 'Show response sizes as..', ''),
      new sfCommandOption('use_cache', null, sfCommandOption::PARAMETER_OPTIONAL, 'Whether to use SF cache', ''),
      new sfCommandOption('application', null, sfCommandOption::PARAMETER_OPTIONAL, 'The SF application to use', 'frontend'),
      new sfCommandOption('env', null, sfCommandOption::PARAMETER_OPTIONAL, 'The SF environment to use', 'prod'),
      new sfCommandOption('csrf', null, sfCommandOption::PARAMETER_OPTIONAL, 'Whether there is a CSRF filter', ''),
      new sfCommandOption('layouts', null, sfCommandOption::PARAMETER_OPTIONAL, 'Whether to process layouts', ''),
    ));
    
    $this->namespace = 'appflower';
    $this->name = 'benchmark';
    $this->briefDescription = 'The task provides profiling information for one or more widgets';

    $this->detailedDescription = <<<EOF
This task executes the widget and prints profiling information about it.

* The widget parameter should be a valid SF internal URI. If the URI points to a layout view, all associated widgets will be profiled. 
It is also possible to use the wildcard "*" in which case the whole application (all widgets and layouts) will be profiled.

* The verbosity parameter controls the amount of information collected about each processed widget. The "totals" setting will print
total execution time only. The "details" setting will print times for all processing phases, while the "sql" setting will show you
the same as "details", but adds a list of the SQL queries performed along with their execution time. 

* The params argument allows you to supply one or more parameters for the widget. These will be passed as request parameters.
  The expected value is a query string.
  
* The application and env options allow you to specify which SF application and environment to use, respectively.

Examples:

[symfony appflower:benchmark users/edit --params=foo=bar&bar=1]

[symfony appflower:benchmark * --application=my]

[symfony appflower:benchmark * --verbosity=details]
   
EOF;
  	
  }

  /**
   * Displays the list of existing job queues.
   * 
   * @param   array   $arguments    (optional)
   * @param   array   $options      (optional)
   * 
   */
  public function execute($arguments = array(), $options = array())
  { 
  	
  	// Init timer..
  	
  	$this->stamp = $this->time();
  	
  	$time_units = array("s","ms");
  	$size_units = array("B","KB");
  	
  	// Read config..
  	
  	$this->config = new stdClass();
  	foreach(sfConfig::getAll() as $prop => $value) {
  		if(strstr($prop,"app_afBenchmarkPlugin")) {
	  		$prop = str_replace("app_afBenchmarkPlugin_", "", $prop);
	  		$value = ($options[$prop]) ? $options[$prop] : $value;
	  		$this->config->$prop = $value;
  		}
  	}
  	
  	$this->config->limit = ($this->config->time_unit == "ms") ? 1000 : 1;
  	
  	if(!in_array($this->config->time_unit,$time_units)) {
  		throw new sfCommandException(sprintf("The value '%s' is invalid, one of '%s' is expected!",$this->config->time_unit,implode(",", $time_units)));
  	} else if(!in_array($this->config->size_unit,$size_units)) {
  		throw new sfCommandException(sprintf("The value '%s' is invalid, one of '%s' is expected!",$this->config->size_unit,implode(",", $size_units)));
  	} else if(!$this->config->url) {
  		throw new sfCommandException("The URL parameter is not defined!");
  	} 
  	
  	// Init context..
  	
  	$config = ProjectConfiguration::getApplicationConfiguration($this->config->application, $this->config->env, false);
  	$rootdir = $config->getRootDir();
  	$this->moduledir = $rootdir."/apps/".$this->config->application."/modules";
  	$this->layoutdir = $rootdir."/apps/".$this->config->application."/config/pages";
  	$this->context = sfContext::createInstance($config);
  	
  	unset($config);
  	
  	// Collect widgets and layouts to execute
  	
  	if($arguments["widget"] != "*" && !preg_match("/[a-z0-9]+\/[a-z0-9+]|\*/", $arguments["widget"])) {
  		throw new sfCommandException(sprintf("The value '%s' is invalid, internal URI or wildcard is expected!",$arguments["widget"]));
  	} 
  	
  	$module = strtok($arguments["widget"],"/");
  	$layout = ($module == "pages");
  	if($module != "*" && !$layout && !file_exists($this->moduledir."/".$module)) {
  		throw new sfCommandException(sprintf("The '%s' module doesn't exist!",$module));
  	}
  	
  	if($arguments["widget"] != "*" && !strstr($arguments["widget"],"*")) {
  		$widget = strtok("/");
	  	if(!$layout && !file_exists($this->moduledir."/".$module."/config/".$widget.".xml")) {
	  		throw new sfCommandException(sprintf("The '%s' widget doesn't exist in module '%s'!",$widget,$module));
	  	} else if($layout && !file_exists($this->layoutdir."/".$widget.".xml")) {
	  		throw new sfCommandException(sprintf("The '%s' layout doesn't exist!",$widget));
	  	}
  		$this->widgets[$module][] = $widget;
  	} else {
  		$dir = ($layout) ? $this->layoutdir : $this->moduledir;
  		if(!$layout && $arguments["widget"] != "*") {
  			$dir .= "/".$module."/config";
  		}

  		if($arguments["widget"] == "*" && $this->config->layouts) {
  			$this->scanWidgets($this->layoutdir,$this->widgets,"pages");
  		}
  		
  		$this->scanWidgets($dir,$this->widgets,$module);
  		
  	}
  	
  	// Clear SF cache is needed..
  	
  	if(!$this->config->use_cache) {
  		$this->logSection("Clearing cache..",null,null,"INFO");
  		$task = new sfCacheClearTask($this->dispatcher,$this->formatter);
	    $task->setCommandApplication($this->commandApplication);
	    $task->run();
  	}
  	
  	// Curl requester and profiler instance
  	
  	$this->browser = new afCurlRequest($this->config);
  	
  	$this->logSection("Started at: ".date("Y-m-d H:i:s",$this->stamp),null,null,"INFO");
  	$this->logSection("Connecting to ".$this->config->url,null,null,"INFO");
  	
  	$this->browser->get($this->config->url);
  	
  	$headers = $this->browser->getHeaders();
  	
  	if($this->config->profiling) {
  		if(!isset($headers["X-Debug-Token"])) {
  			throw new sfCommandException("Profiler is not running, please turn off this option!");	
  		} else {
            require_once dirname(dirname(dirname(__DIR__))).'/afProfilerPlugin/lib/afProfiler.class.php';
  		    $this->profiler = afProfiler::create();
  		}
  	} 
  	
  	// Bypass CSRF filter if necessary
  	
  	if($this->config->csrf) {
  		$filters = sfYaml::load($rootdir."/apps/".$this->config->application."/config/filters.yml");
  		if(isset($filters["csrf"])) {
  			$this->browser = $this->browser->restart();
  			$this->browser->ajaxOn();
  		}
  	}
  	
  	$this->logSection("Signing in user..",null,null,"INFO");
  	$this->logBlock(" ",null);
  	
  	if($this->config->username && $this->config->password) {
  		$this->logIn();
  	}
    
    // Add config..
    
    sfConfig::add(array("benchmark" => true));
  	
  	$this->executeWidgets(null,$options);
  	
  	$this->browser->shutdown();
  
  	$this->logBlock(" ",null);
  	$this->logBlock("All Done!","QUESTION");
  	$this->logSection(sprintf("Finished at: %s",date("Y-m-d H:i:s")),null,null,"INFO");
  	$this->logSection("Result details:",null,null,"INFO");
  	
  	$w = 0;
  	foreach($this->totals as $code => $totals) {
  		if(!is_numeric($code) ) {
  			continue;
  		}
  		$str = $code." - ".$totals[0];
  		$this->logBlock(sprintf("%s%s%d",$str,str_repeat(" ", $this->maxwidth-strlen($str)+6),count($totals)),"INFO");
  		$w += count($totals);
  	}
  	
  	$overtime = isset($this->totals["overtime"]) ? count($this->totals["overtime"]) : 0;
  	
  	if($overtime) {
  		$this->logBlock(sprintf("%d entries took more than ".$this->config->limit.$this->config->time_unit." to run.",$overtime),"INFO");
  	}
  	
  	$this->logSection(sprintf("Executed %d widgets in %s%s",$w,$this->totals["totaltime"],$this->config->time_unit),null,null,"INFO");
  	$this->logSection(sprintf("Average execution time was: %1.2f%s",($this->totals["totaltime"] / $w),$this->config->time_unit),null,null,"INFO");
  	
  }
  
  /**
   * 
   * Prints table header string
   * 
   */
  private function printHeader() {
  	
  	$max = $this->maxwidth-22;
  	
  	$text = "Widget".str_repeat(" ", $max)."Status    Time    Data";
  	$this->logBlock(" ",null);	
  	$this->logBlock($text,null);	
  	$this->logBlock(str_repeat("-", strlen($text)),null);
  	
  }
  
  /**
   * 
   * Prints section subtitle.
   * @param string $module - The name of the currently processed module
   */
  private function printSubHeader($module) {
  		
  	$this->logBlock(" ",null);
	$this->logBlock($module == "pages" ? "Processing layout.." : "Processing widgets in '".$module."'","INFO");	
	$this->logBlock(" ",null);
	
  }
  
  
  /**
   * 
   * Executes the selected widgets and layouts and prints benchmarking results.
   * 
   * @param array $widgets An array containing the widgets / layouts to be executed.
   * @param bool $layout Whether the currently processed item is a layout or a widget.
   * @param bool $header Whether to print extra title row. Used only when layouts are processed.
   * 
   * @return int The number of processed items.
   * 
   */
  private function executeWidgets(Array $widgets = null,$layout = false,$header = false) {
  	
  	if(is_null($widgets)) {
  		$widgets = $this->widgets;
  	}
  	
	$w = 0;
	$max = $this->maxwidth-22;
	
	if(!$layout) {
		$this->logBlock("Processing..",null);	
	}
	
	$this->printHeader();	
  	
	foreach($widgets as $module => $entries) {
		if(!empty($entries)) {
			$this->printSubHeader($module);
		}
		foreach($entries as $k => $widget) {
			
			$ajax = ($module != "pages");
			$entry = $module."/".$widget;
	  		
			if(!$ajax && $k) {
				$this->printHeader();
				$this->printSubHeader($module);	
			} 
			
			if(in_array($entry,$this->processed)) {
				continue;
			}
			
			$uri = "/".$entry.((@$options["params"]) ? ("?".$options["params"]) : "?");
	  		
	  		if($ajax) {
	  			$uri .= "&widget_load=true";
	  		}
	  		
	  		$this->browser->get($this->config->url.$uri);
//			file_put_contents("./foo/".$widget, $this->browser->getResponseBody());
	  		$this->totals[$this->browser->getStatusCode()][] = $this->browser->getStatusMessage();
	  		$execTimeNumber = $this->browser->getResponseTime(true);
	  		$execTime = $execTimeNumber.$this->config->time_unit;
	  		
	  		if(str_replace($this->config->time_unit, "", $execTimeNumber) > $this->config->limit) {
	  			$this->totals["overtime"][] = $entry;
	  		}
	  		
	  		$this->totals["totaltime"] += $execTimeNumber;
	  		
	  		if($ajax) {
	  			$this->processed[] = $entry;	
	  		}
	  		
	  		$this->logBlock($entry.str_repeat(" ",$max - (strlen($module."/".$widget)-6)).$this->browser->getStatusCode().str_repeat(" ",7).$execTime.str_repeat(" ",8-strlen($execTime)).$this->browser->getResponseSize(),null);
	  		
	  		$w++;
	  		
	  		if($header && $k == count($entries)-1) {
	  			$this->printHeader();
	  		}
	  		
			if(!$ajax) {
		  		$this->executeWidgets($this->extractWidgetsFromLayout($this->layoutdir."/".$widget.".xml"),$entry,
		  		(isset($this->widgets["pages"]) && array_search($widget, $this->widgets["pages"]) === count($this->widgets["pages"])-1));
		  	}
	  	}
	 }
	 
	 return $w;
  }
  
  
  /**
   * 
   * Signs in the user if the application is secured. It's executed only when username / pw is provided and works with afGuard at the moment.
   * Return true on success, throws exception otherwise.
   * 
   * @throws sfCommandException
   * @return bool true
   */
  private function logIn() {
  	
  	$this->browser->post($this->config->url."/login","signin[username]=".$this->config->username."&signin[password]=".$this->config->password."&signin[remember]=on");
  	$response = json_decode($this->browser->getResponseBody());
  	
  	if($this->browser->getStatusCode() != 200 || $this->browser->isValid() === null) {
  		throw new sfCommandException("Couldn't execute signin action!");
  	} else if(!is_object($response) || !property_exists($response, "success")) {
  		throw new sfCommandException("Login mechanism doesn't seem to be afGuard, opreation failed!");
  	} else if($response->success === false) {
  		throw new sfCommandException($response->message);
  	}
  	
  	return true;
  }
 
  
  /**
   * 
   * It extracts the module and widget names from layout XML config files.
   * 
   * @param string $file A path to the layout file
   * @return Array An array of module/widget names
   */
  private function extractWidgetsFromLayout($file) {
  	
  	$ret = array();
  	
  	$xpath = XmlParser::readDocumentByPath($file);
  	$res = $xpath->evaluate("//i:component");
  	
  	foreach($res as $component) {
  		$ret[$component->getAttribute("module")][] = $component->getAttribute("name");
  	}
  	
  	unset($xpath);
  	
  	return $ret;
  	
  }
  
  /**
   * 
   * Returns the XML config files located under the given path, as an array of module / widget pairs.
   * It is running recursively, only a starting point must be defined.
   * It saves the results the array passed as argument.
   * 
   * @param string $dir A directory which contains the desired widgets / layouts
   * @param Array $ret The result as an array 
   * @param bool $module It's true when processing modules / widgets, false when reading layouts.
   */
  private function scanWidgets($dir,&$ret,$module = null) {
  	
  	$input = scandir($dir);
  	
  	foreach($input as $file) {
  		
  		$tmp = $dir."/".$file;
  		
  		if($file == "." || $file == "pages" || $file == ".." || substr($file,0,1) == ".") {
  			continue;
  		}
  		
  		if(is_dir($tmp."/config")) {
  			$ret[$file] = array();
  			$this->scanWidgets($tmp."/config",$ret,$file);
  		}
  		else if(strtolower(substr($tmp,strrpos($tmp,".")+1)) == "xml") {
  			$ret[$module][] = str_replace(".xml","",$file); 	
  		}
  		
  	}
  
  }
  
  /**
   * 
   * Returns the current time as microseconds
   */
  private function time() {	
  	
  	return microtime(true);
  
  }
  	
  
  private function getTotalTime() {
  	
  	
  	$res = $this->time() - $this->stamp;
  	
  	switch($this->config->time_unit) {
  		case "s":
  			$res = sprintf("%1.2f",$res);
  			break;
  		case "ms":
  			$res = round($res*1000);
  			break;
  	}
  	
  	return $res.$this->config->time_unit;
  	
  }
     

}
