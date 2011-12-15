<?php
/**
 * fwCdn - Joomla! 1.5 plugin provides easy Content Delivery Network integration
 *   It alters file URLs, so that files are dowloaded from a CDN instead
 *   of your web server.
 *      
 * @author faresweb.net <webmaster@faresweb.net>
 * @copyright Copyright (c) 2011 faresweb.net  
 * @license GNU/GPLv3, See LICENSE file
 *  
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * If LICENSE file missing, see <http://www.gnu.org/licenses/>.
 *
 * This plugin, inspired by CssJsCompress <http://www.joomlatags.org>, was
 * created in March 2010 and includes other copyrighted works. See individual
 * files for details.
 */
 
// Check to ensure this file is included in Joomla! - No direct access
defined( '_JEXEC' ) or die( 'Restricted access' );

jimport( 'joomla.plugin.plugin' );

// External library to merge JavaScript source files - includes Compressor class library
require_once ( dirname(__FILE__). DS . 'fwcdn' . DS . 'S3.php' );

//@todo: check to exclude processing on some extensions -> add in plugin

/**
 * Joomla! 1.5 fwCdn System Plugin
 *
 * @author      fareswebnet <webmaster@faresweb.net>
 * @package     Joomla
 * @subpackage  System
 * @since       1.5
 */
class  plgSystemfwCdn extends JPlugin
{

  /**
   * Attributes
   *    
   */
   
  var $version = "1.0";
  
  /** var array     The array for CDN mappings */
  private $cdnmapping;
  
  /** var boolean   To use if CDN support HTTPS */
  private $httpsSupport;

  /** var boolean   To check if page is server by HTTPS */
  private $isHttpsPage;

  /** @var array   Resource file extensions to populate */
  private $fileExtensions = array (
    'css', 'js', 'jpg', 'jpeg',
    'png', 'gif', 'swf', 'ico');
    
  /** @var string   Resource files extension regular expression pattern */
  private $regexPattern = '/([^\"\'=]+\.(%s))[\"\']/i';

  /** @var string   Resource files regular expression pattern */
  private $fileRegex;

	/**
	 * Constructor
	 *
	 * For php4 compatability we must not use the __constructor as a constructor for plugins
	 * because func_get_args ( void ) returns a copy of all passed arguments NOT references.
	 * This causes problems with cross-referencing necessary for the observer design pattern.
	 *
	 * @param object $subject The object to observe
	 * @param object $params  The object that holds the plugin parameters
	 * @since 1.5
	 */
	function plgSystemfwCdn ( &$subject, $params )
	{
		parent::__construct( $subject, $params );

    // Get CDN support for https
    $this->httpsSupport = $this->params->get('cdnhttpssupport', false);
    
    // Check if page served by https
    $this->isHttpsPage = $this->isHttpsPage();

    // Check if need to log events
		$fields = $this->params->get('cdnmapping');
		$this->cdnmapping = $this->getCDNMapping($fields);
		
    // Set regex expression to find entries in application tags
    $regex = @implode('|', $this->fileExtensions);
    $this->fileRegex = sprintf($this->regexPattern, $regex);
	}
  
	/**
	 * Method is called to check if plugin have to be triggered
	 * 	 
	 */
  function fireEvent()
  {
    // Exit if not rendering front-end site
    $app =& JFactory::getApplication();
    if($app->getName() != 'site') {
      return false;
    }

		// Exit if not rendering HTML output
		$document	=& JFactory::getDocument();
		$doctype	= $document->getType();
		if ( $doctype != 'html' ) {
      return false;
    }

    // If the current file path matches one of the blacklisted file paths,
    // return immediately, except when the current file path also matches one of
    // the whitelisted file paths.
    //@todo: check if component to exclude - add to plugin xml

    // If the current page is being served via HTTPS, and the CDN does not
    // support HTTPS, then don't rewrite the file URL, because it would make the
    // visit insecure.
    if ($this->isHttpsPage && !$this->httpsSupport) {
      return false;
    }

		return true;
	}

	/**
	 * Method is called after the framework has rendered the application (page)
	 * 	 
	 * When this event is triggered, the output of the application is in the
	 * response buffer.   	 
	 */
	function onAfterRender()
	{
    // Exit if condition does not match plugin conditions to execute
    if (!$this->fireEvent()) {
      return true;
    }

    // Get HTML body response
    $body = JResponse::getBody();
    
    // Replace URLs if CDN is set
    if (isset($this->cdnmapping)) {    
      $body = $this->replaceURLs($body);
    }

    // Re-set body response
    JResponse::setBody($body);
  }

	/**
	 * Method is called to parse CDN mapping entries from plugin params
	 * 	 
	 * @param string $fields A string that represent all users entries data
	 * @param array  $maps   Values for CDN map   	 
	 */
	function getCDNmapping($fields) {

    $cdnmap = array();

    $lines = $lines = preg_split("/[\n\r]+/", $fields, -1, PREG_SPLIT_NO_EMPTY);
    if (isset($lines)) {
      // Generate an array with each cdn entry list <URL>[|extensions]
      foreach ($lines as $line) {
        if (strpos($line, '|') !== false) {
          $parts = explode('|', $line);
          // Remove whitespace and last trailing slash.
          $map = rtrim(trim($parts[0]), '/');
          // Convert to lower case, remove periods, whitespace and split on ' '.
          $extensions = explode(' ', trim(str_replace('.', '', strtolower($parts[1]))));
        } else {
          $map = trim($line);
          // Use the asterisk as a wildcard
          $extensions = array('*');
        }
        // If the current page is being served via HTTPS, and the CDN supports
        // HTTPS, then use the HTTPS file URL.
        if ($this->isHttpsPage && $this->httpsSupport) {
          $map = preg_replace('/^http/', 'https', $map);
        }
        // Create the mapping lookup table.
        foreach ($extensions as $extension) {
          $cdnmap[$extension][] = $map;
        }
      }
    }
    
    return $cdnmap;
  }

  /**
   * Method to replace application resource URL path relative to CDN's
   *     
	 * @param object $body   Application rendered HTML
	 * @return object  Application modified HTML   	 
   */
  function replaceURLs($body) {
  
    // Get array of file and extension names
    $listOfFiles = $this->getListOfFiles($body);

    // Get name of mapped files
    $listOfFiles['mappedfiles'] = $this->getListOfMappedFiles($listOfFiles);
    
    // Init callback method with resource files to exclude
    // used in preg_replace_callback
    $this->replace(NULL, $listOfFiles);

    preg_match_all($this->fileRegex, $body, $matches);

    // Perform a regular expression search using replace callback to
    // alter the files urls 
		$body = preg_replace_callback($this->fileRegex, array('self', 'replace'), $body);

    return $body;
  }

	/**
	 * Method is called to collect a list of files in application by type
	 * 	 
	 * @param string $body       Application's rendered HTML code
	 * @return array Array of valid list of ressource in <html> element      	 
	 */
  function getListOfFiles( $body ) {

    preg_match_all($this->fileRegex, $body, $matches);
    
    if(isset($matches[0])) {
      $listOfFiles['files'] = $matches[1];
      $listOfFiles['extension'] = $matches[2];
    }
    
    return $listOfFiles;
  }

	/**
	 * Method is called to map application's files to cdn
	 * 	 
	 * @param  array $listOfFiles  Array of occuring files in application
	 * @return array Array of mapped files      	 
	 */
  function getListOfMappedFiles( $listOfFiles ) {

    $listOfMappedFiles = array();
    
    // Get list entries found in body for resource type based on tag
    $count = count($listOfFiles['files']);
    for($i = 0; $i < $count; $i++) {
      $file = $listOfFiles['files'][$i];
      $extension = $listOfFiles['extension'][$i];
      // Check if the file is served from this server
      if(JURI::isInternal($file)) {
        $mapped = $this->getMap($file, $extension);
      } else {
        // Do not move to cdn
        $mapped = NULL;
      }
      $listOfMappedFiles[] = $mapped;
    }
    
    return $listOfMappedFiles;
  }

  /**
  * Method callback to alter files URLs
  * 	 
  * @param array $matches matches[0] represents all list,
  *                        matches[1] the first element in the list
  * @param array $map Used to initialize static object
  */
  function replace($matches, $map = NULL) {

  	// Binding static object
    static $_map;

  	// Store entry list to exclude for preg_replace_callback
  	if (isset($map)) {
  		$_map = $map;
  		return;
  	}
    // If list of mapped files, alter identified entry
    if (isset($_map)) {
  		// Search for value in array
  		$key = array_search($matches[1], $_map['files']);
  		if (isset($key)) {
  			// Alter entry if mapped to CDN
        if (isset($_map['mappedfiles'][$key])) {
          //@todo: clean bug here !! regex issue
					return $_map['mappedfiles'][$key].'"'; // Return mapped value
  			}
    	}
    }
  	
  	return $matches[0]; // Nothing found, return initial value
  }

	/**
	 * Method is called to map application's files to cdn
	 * 	 
	 * @param  String  $file       Resource filename
	 * @param  String  $extension  Resource extension	 
	 * @return String  Return mapped file name in CDN      	 
	 */
  function getMap( $file, $extension ) {

    // Get urn - JURI instance is related to Joomla! install folder
    $uri = &JURI::getInstance($file);
    $urn = $uri->getPath(true);
    
    // Do not take into account url parameters
    if (($pos = strpos($urn, '?')) > 0) {
      $urn = substr($urn, 0, $pos);
    }
        
    // Map resource to cdn
    if(isset($this->cdnmapping[$extension])) {
      //$map = $this->cdnmapping[$extension][0].'/'.$filename;    
      $map = $this->cdnmapping[$extension][0] . $urn;
    } else {
      $map = $this->cdnmapping['*'][0] . $urn;
    }

    return $map;
  }

	/**
	 * Method is used to check if page is served in HTTPS
	 * 	 
	 */
	function isHttpsPage() {
	
    if(isset($_SERVER['HTTPS'])) {
      return (strtolower($_SERVER["HTTPS"]) == "on" || $_SERVER['HTTPS'] == true);
    }

    return ($_SERVER['SERVER_PORT'] == '443');
	}

}
