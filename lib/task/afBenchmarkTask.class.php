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
 * It also generates SF cache in the meantime. Can be used to benchmark the local or any remore AF application.
 */
class afBenchmarkTask extends sfBaseTask
{
	
  private 
  	$widgets = array(),
  	$context,
	$totals = array("widgettotaltime" => 0, "layouttotaltime" => 0, "widgets" => 0, "layouts" => 0, "true" => 0, "false" => 0, "noprofile" => 0),
  	$stamp,
  	$processed = array(),
  	$config,
  	$maxwidth = 60;

  /**
   * @var afCurlRequest
   */
  private $browser;
	
  /**
   * Configures task.
   */
  public function configure()
  {
  	
  	$this->addArguments(array(
  	  new sfCommandArgument('widget', sfCommandArgument::OPTIONAL, 'The URI of the widget / layout to benchmark','*'),
  	));
    
  	$this->addOptions(array(
  	  new sfCommandOption('params', null, sfCommandOption::PARAMETER_OPTIONAL, 'Optional request parameters', null),
  	  new sfCommandOption('username', null, sfCommandOption::PARAMETER_OPTIONAL, 'User login for secured apps', null),
  	  new sfCommandOption('password', null, sfCommandOption::PARAMETER_OPTIONAL, 'User password for secured apps', null),
      new sfCommandOption('url', null, sfCommandOption::PARAMETER_OPTIONAL, 'The URL of the AF appliance you wanna benchmark', null),
      new sfCommandOption('time_unit', null, sfCommandOption::PARAMETER_OPTIONAL, 'Show execution times as..', null),
      new sfCommandOption('size_unit', null, sfCommandOption::PARAMETER_OPTIONAL, 'Show response sizes as..', null),
      new sfCommandOption('use_cache', null, sfCommandOption::PARAMETER_OPTIONAL, 'Whether to use SF cache', null),
      new sfCommandOption('application', null, sfCommandOption::PARAMETER_OPTIONAL, 'The SF application', 'frontend'),
      new sfCommandOption('env', null, sfCommandOption::PARAMETER_OPTIONAL, 'The SF environment', 'prod'),
      new sfCommandOption('csrf', null, sfCommandOption::PARAMETER_OPTIONAL, 'Whether there is a CSRF filter to bypass', null),
      new sfCommandOption('layouts', null, sfCommandOption::PARAMETER_OPTIONAL, 'Whether to process layouts when benchmarking the entire app', null),
    ));
    
    $this->namespace = 'appflower';
    $this->name = 'benchmark';
    $this->briefDescription = 'The task benchmarks AF widgets and layouts';

    $this->detailedDescription = <<<EOF
This task executes one or more AF widgets or layouts and provides benchmarking information. It can be used to benchmark one widget, an 
entire module or the whole AF application.

If afProfilerPlugin is installed and enabled, it will display profiling results as well.

Before using the plugin, make sure the "url", "username", "password" and "csrf" values are correctly set in app.yml or supply these
on the command-line.

About the arguments:

* The "[widget|COMMENT]" argument should be a valid SF internal URI. If the URI points to a layout view, all associated widgets will be benchmarked.
The URI may also contain the '*' wildcard as widget name (i.e.: foo/*). In such a case all widgets in the given module will be processed.
If you omit the "widget" argument or use the '*' value, the whole application (all widgets and layouts) will be processed.

About the options:

All these options are available in the plugin's app.yml. You should use the command-line versions for overriding the config values only!

* The "[params|COMMENT]" option allows you to supply one or more parameters to be passed with the request.
  The expected value is a query string and this option should be used only when you're benchmarking a single widget.
  
* The "[username|COMMENT]" and "[password|COMMENT]" options should be used if the AF application is secured. If you don't need this, simply
empty their values in app.yml. Please note that this works only if authentication was implemented using afGuardPlugin. 

* The "[url|COMMENT]" parameter should point to the AF application you want to work with. If you're benchmarking the current application, you may
use the value "local". Otherwise a HTTP / HTTPS URL is expected, with port number (if needed).

* The "[time_unit|COMMENT]" option determines how execution times are measured. Valid values are "s" (seconds) or "ms" (microseconds).

* The "[size_unit|COMMENT]" option controls how the response sizes are calculated. Valid values are "B" (bytes") or "KB" (kilobytes).

* The "[use_cache|COMMENT]" option should be set to false if you want SF cache to be cleared before benchmarking.

* The "[application|COMMENT]" and "[env|COMMENT]" options are names of the SF application and environment, respectively. If you are benchmarking a 
remote application, leave the default values intact.

* The "[csrf|COMMENT]" option's value should be set to true if your application uses CSRF protection. 

* The "[layouts|COMMENT]" option should be enabled, if you want layouts to be processed as well as widgets when doing full benchmarking (app).


Examples:

To benchmark a single widget (and override some config options):

[symfony appflower:benchmark users/edit|INFO]

To benchmark all widgets in a module:

[symfony appflower:benchmark users/*|INFO]

To benchmark the entire app:

[symfony appflower:benchmark|INFO]
   
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
	  		$value = (!is_null($options[$prop])) ? $options[$prop] : $value;
	  		$this->config->$prop = $value;
  		}
  	}
  	
  	if(!$this->config->url) {
  		$this->config->url = "http://localhost/";
  	}
  	
  	$this->config->mode = $arguments["widget"];
  	
  	preg_match("/\/([^\/:]+)/", $this->config->url,$m);
  	
  	if(!isset($m[1]) || !$m[1]) {
  		throw new sfCommandException(sprintf("The value '%s' is invalid URL! Please specify in prot://host format!",$this->config->url));
  	}
  
  	$this->config->ip = trim($m[1]);
  	$this->config->remote = $this->config->ip == "127.0.0.1";
  	
  	$this->config->limit = ($this->config->time_unit == "ms") ? 1000 : 1;
  	
  	if(!in_array($this->config->time_unit,$time_units)) {
  		throw new sfCommandException(sprintf("The value '%s' is invalid, one of '%s' is expected!",$this->config->time_unit,implode(",", $time_units)));
  	} else if(!in_array($this->config->size_unit,$size_units)) {
  		throw new sfCommandException(sprintf("The value '%s' is invalid, one of '%s' is expected!",$this->config->size_unit,implode(",", $size_units)));
  	} else if(!$this->config->url) {
  		throw new sfCommandException("The URL parameter is not defined!");
  	} 
  	
  	//error_reporting(0);
  	
  	// Init context..
  	
  	$config = ProjectConfiguration::getApplicationConfiguration($this->config->application, $this->config->env, false);
  	$rootdir = $config->getRootDir();
  	$this->moduledir = $rootdir."/apps/".$this->config->application."/modules";
  	$this->layoutdir = $rootdir."/apps/".$this->config->application."/config/pages";

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
  	$this->logSection("Connecting to ".$this->config->url." (".gethostbyname($this->config->ip).")",null,null,"INFO");
  	
  	$this->browser->get($this->config->url);
  	
  	$headers = $this->browser->getHeaders();

    if(!isset($headers["X-Debug-Token"])) {
        $this->config->profiling = false;
    } else {
        require_once dirname(dirname(dirname(__DIR__))).'/afProfilerPlugin/lib/afProfiler.class.php';
        $this->profiler = afProfiler::create();
        $this->config->profiling = true;
    }
  	
  	// Bypass CSRF filter if necessary
  	
  	if($this->config->csrf) {
  		$filters = sfYaml::load($rootdir."/apps/".$this->config->application."/config/filters.yml");
  		if(isset($filters["csrf"])) {
  			$this->browser = $this->browser->restart();
  			$this->browser->ajaxOn();
  		}
  	}
  	
  	$this->logSection("Authenticating..",null,null,"INFO");
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
  	
  	$this->logBlock(" ",null);
  	$overtime = isset($this->totals["overtime"]) ? count($this->totals["overtime"]) : 0;
  	
  	$this->logSection(sprintf("Executed %d item(s) in %s%s",$this->totals["widgets"]+$this->totals["layouts"],$this->totals["widgettotaltime"]+$this->totals["layouttotaltime"],$this->config->time_unit),null,null,"INFO");
  		
  	if($this->totals["false"]) {
  		$this->logSection(sprintf("%d request(s) were unsuccessful!",$this->totals["false"]),null,null,"ERROR");
  	}
  	
  	if($this->totals["noprofile"]) {
  		$this->logSection(sprintf("%d request(s) did not return profiling data!",$this->totals["noprofile"]),null,null,"ERROR");
  	}
  	
    if($overtime) {
  		$this->logSection(sprintf("%d widget(s) took more than ".$this->config->limit.$this->config->time_unit." to run.",$overtime),null,null,"INFO");
  	}
  	
  	$this->logSection(sprintf("Average widget execution time was: %1.2f%s",($this->totals["widgettotaltime"] / $this->totals["widgets"]),$this->config->time_unit),null,null,"INFO");
  	
  	if($this->totals["layouts"]) {
  		$this->logSection(sprintf("Average layout execution time was: %1.2f%s",($this->totals["layouttotaltime"] / $this->totals["layouts"]),$this->config->time_unit),null,null,"INFO");	
  	}
  	
  }
  
  /**
   * 
   * Prints table header string
   * 
   */
  private function printHeader() {
  	
  	$max = $this->maxwidth-22;

    if ($this->config->profiling) {
        $text = "Widget".str_repeat(" ", $max)."Status    Valid    Time    actionTime    renderTime    queriesCount        Data";
    } else {
        $text = "Widget".str_repeat(" ", $max)."Status    Valid    Time        Data";
    }
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
  
  
  private function allProcessed(Array $items,$module) {
  	
  	 foreach($items as $widget) {
	   	if(!in_array($module."/".$widget, $this->processed)) {
	   		return false;
	   	}	
  	 }
  	 
  	 return true;
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
  	
	$max = $this->maxwidth-22;
	
	if(!$layout) {
		$this->logBlock("Processing..",null);	
	}
	
	$this->printHeader();	
  	
	foreach($widgets as $module => $entries) {
		$this->printSubHeader($module);
		if($this->allProcessed($entries,$module)) {
			$this->logBlock("Contains only already processed widgets or is empty, skipping..","COMMENT");
			continue;
		}
		foreach($entries as $k => $widget) {
			
			$ajax = ($module != "pages");
			$entry = $module."/".$widget;
			
			if(in_array($entry,$this->processed)) {
				continue;
			}
			
			if(!$ajax && $k) {
				$this->printHeader();
				$this->printSubHeader($module);	
			} 
			
			$uri = "/".$entry.(($this->config->params) ? ("?".urlencode($this->config->params)) : "?");
	  		
	  		if($ajax) {
	  			$uri .= "&widget_load=true";
	  		}
	  		
	  		$this->browser->get($this->config->url.$uri);
	  		$this->browser->checkForAuthenticationError();
//			file_put_contents("./foo/".$widget, $this->browser->getResponseBody());
	  		$this->totals[$this->browser->getStatusCode()][] = $this->browser->getStatusMessage();
	  		$execTimeNumber = $this->browser->getResponseTime(true);
	  		$execTime = $execTimeNumber.$this->config->time_unit;
	  		$valid = $this->browser->isValidRequest($ajax);
	  		
	  		if(str_replace($this->config->time_unit, "", $execTimeNumber) > $this->config->limit && $ajax) {
	  			$this->totals["overtime"][] = $entry;
	  		}
	  		
	  		if($ajax) {
	  			$this->processed[] = $entry;	
	  			$this->totals["widgettotaltime"] += $execTimeNumber;
	  			++$this->totals["widgets"];
	  		} else {
	  			$this->totals["layouttotaltime"] += $execTimeNumber;
	  			++$this->totals["layouts"];
	  		}	  	

	  		++$this->totals[$valid];

            if ($this->config->profiling) {
                $token = $this->browser->getXDebugTokenHeaderValue();
                $requestProfiler = $this->profiler->loadFromToken($token);
                if(!$requestProfiler->isEmpty()) {
                	$widgetDataCollector = $requestProfiler->get('widget');
                	$actionTime = $widgetDataCollector->getActionTime().$this->config->time_unit;
                	$propelDataCollector = $requestProfiler->get('propel');
                	$renderTime = $widgetDataCollector->getRenderTime().$this->config->time_unit;
                	$dbCount = $propelDataCollector->getQueriesCount();
                    $dbTime = $propelDataCollector->getTotalQueriesTime();
                    if ($dbTime != '') {
                        $dbCount .= " ({$dbTime}ms)";
                    }
                
                } else {
                	$actionTime = $renderTime = $dbCount = "-";
                	if($valid == "true") {
                		--$this->totals[$valid];
                		++$this->totals["false"];
                		++$this->totals["noprofile"];
                		$valid = "false";
                	}
                }

                $this->logBlock(
                    $entry.str_repeat(" ",$max - (strlen($module."/".$widget)-6)).
                        str_pad($this->browser->getStatusCode(), 6, ' ', STR_PAD_LEFT).
                        '  '.
                        str_pad($valid, 7, ' ', STR_PAD_LEFT).
                        '  '.
                        str_pad($execTime, 6, ' ', STR_PAD_LEFT).
                        '  '.
                        str_pad($actionTime, 12, ' ', STR_PAD_LEFT).
                        '  '.
                        str_pad($renderTime, 12, ' ', STR_PAD_LEFT).
                        '  '.
                        str_pad($dbCount, 14, ' ', STR_PAD_LEFT).
                        '    '.
                        str_pad($this->browser->getResponseSize(), 10, ' ', STR_PAD_LEFT),
                    null
                );
            } else {
                $this->logBlock(
                    $entry.str_repeat(" ",$max - (strlen($module."/".$widget)-6)).
                        str_pad($this->browser->getStatusCode(), 6, ' ', STR_PAD_LEFT).
                        '  '.
                        str_pad($valid, 7, ' ', STR_PAD_LEFT).
                        '  '.
                        str_pad($execTime, 6, ' ', STR_PAD_LEFT).
                        '    '.
                        str_pad($this->browser->getResponseSize(), 10, ' ', STR_PAD_LEFT),
                    null
                );

            }
	  		
	  		if($this->config->mode == "*" && $ajax && $header && $k == count($entries)-1) {
	  			$this->logBlock(" ",null);
	  			$this->printHeader();
	  		}
	  		
			if(!$ajax) {
		  		$this->executeWidgets($this->extractWidgetsFromLayout($this->layoutdir."/".$widget.".xml"),$entry,
		  		(isset($this->widgets["pages"]) && array_search($widget, $this->widgets["pages"]) === count($this->widgets["pages"])-1));
		  	}
	  	}
	 }
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
  	
  	if($this->browser->getStatusCode() != 200 || $this->browser->contentSent() === null) {
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
     

}
