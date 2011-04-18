<?php

/**
 * afBenchmarkPlugin configuration.
 * 
 * @package     afBenchmarkPlugin
 * @subpackage  config
 * @author      Your name here
 * @version     SVN: $Id: PluginConfiguration.class.php 17207 2009-04-10 15:36:26Z Kris.Wallsmith $
 */
class afBenchmarkPluginConfiguration extends sfPluginConfiguration
{
  const VERSION = '1.0.0-DEV';

  /**
   * @see sfPluginConfiguration
   */
  public function initialize()
  {
 
  	// Suppress error reporting..
  	
  	ini_set("display_errors", "off");
  	error_reporting(0);
  	
  }
  
  
}
