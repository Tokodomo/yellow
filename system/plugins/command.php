<?php
// Command plugin, https://github.com/datenstrom/yellow-plugins/tree/master/command
// Copyright (c) 2013-2017 Datenstrom, https://datenstrom.se
// This file may be used and distributed under the terms of the public license.

class YellowCommand
{
	const VERSION = "0.7.3";
	var $yellow;					//access to API
	var $files;						//number of files
	var $errors;					//number of errors
	var $locationsArgs;				//locations with location arguments detected
	var $locationsArgsPagination;	//locations with pagination arguments detected
	
	// Handle initialisation
	function onLoad($yellow)
	{
		$this->yellow = $yellow;
	}
	
	// Handle command
	function onCommand($args)
	{
		list($command) = $args;
		switch($command)
		{
			case "":		$statusCode = $this->helpCommand(); break;
			case "build":	$statusCode = $this->buildCommand($args); break;
			case "clean":	$statusCode = $this->cleanCommand($args); break;
			case "version":	$statusCode = $this->versionCommand($args); break;
			default:		$statusCode = 0;
		}
		return $statusCode;
	}
	
	// Handle command help
	function onCommandHelp()
	{
		$help .= "build [DIRECTORY LOCATION]\n";
		$help .= "clean [DIRECTORY LOCATION]\n";
		$help .= "version\n";
		return $help;
	}
	
	// Show available commands
	function helpCommand()
	{
		echo "Datenstrom Yellow ".YellowCore::VERSION."\n";
		$lineCounter = 0;
		foreach($this->getCommandHelp() as $line) echo (++$lineCounter>1 ? "        " : "Syntax: ")."yellow.php $line\n";
		return 200;
	}
	
	// Build static files
	function buildCommand($args)
	{
		$statusCode = 0;
		list($command, $path, $location) = $args;
		if(empty($location) || $location[0]=='/')
		{
			if($this->checkStaticConfig())
			{
				$statusCode = $this->buildStatic($path, $location);
			} else {
				$statusCode = 500;
				$this->files = 0; $this->errors = 1;
				$fileName = $this->yellow->config->get("configDir").$this->yellow->config->get("configFile");
				echo "ERROR building files: Please configure StaticUrl in file '$fileName'!\n";
			}
			echo "Yellow $command: $this->files file".($this->files!=1 ? 's' : '');
			echo ", $this->errors error".($this->errors!=1 ? 's' : '');
			echo ", status $statusCode\n";
		} else {
			$statusCode = 400;
			echo "Yellow $command: Invalid arguments\n";
		}
		return $statusCode;
	}
	
	// Build static files and additional locations
	function buildStatic($path, $location)
	{
		$path = rtrim(empty($path) ? $this->yellow->config->get("staticDir") : $path, '/');
		$this->files = $this->errors = $statusCode = 0;
		$this->locationsArgs = $this->locationsArgsPagination = array();
		if(empty($location))
		{
			$statusCode = $this->cleanStatic($path, $location);
			foreach($this->getContentLocations() as $location)
			{
				$statusCode = max($statusCode, $this->buildStaticFile($path, $location, true));
			}
			foreach($this->locationsArgs as $location)
			{
				$statusCode = max($statusCode, $this->buildStaticFile($path, $location, true));
			}
			foreach($this->locationsArgsPagination as $location)
			{
				if(substru($location, -1)!=$this->yellow->toolbox->getLocationArgsSeparator())
				{
					$statusCode = max($statusCode, $this->buildStaticFile($path, $location, false, true));
				}
				for($pageNumber=2; $pageNumber<=999; ++$pageNumber)
				{
					$statusCodeLocation = $this->buildStaticFile($path, $location.$pageNumber, false, true);
					$statusCode = max($statusCode, $statusCodeLocation);
					if($statusCodeLocation==100) break;
				}
			}
			foreach($this->getMediaLocations() as $location)
			{
				$statusCode = max($statusCode, $this->buildStaticFile($path, $location));
			}
			foreach($this->getSystemLocations() as $location)
			{
				$statusCode = max($statusCode, $this->buildStaticFile($path, $location));
			}
			$statusCode = max($statusCode, $this->buildStaticFile($path, "/error/", false, false, true));
		} else {
			$statusCode = $this->buildStaticFile($path, $location);
		}
		return $statusCode;
	}
	
	// Build static file
	function buildStaticFile($path, $location, $analyse = false, $probe = false, $error = false)
	{
		$this->yellow->pages = new YellowPages($this->yellow);
		$this->yellow->page = new YellowPage($this->yellow);
		$this->yellow->page->fileName = substru($location, 1);
		if(!is_readable($this->yellow->page->fileName))
		{
			ob_start();
			$staticUrl = $this->yellow->config->get("staticUrl");
			list($scheme, $address, $base) = $this->yellow->lookup->getUrlInformation($staticUrl);
			$statusCode = $this->requestStaticFile($scheme, $address, $base, $location);
			if($statusCode<400 || $error)
			{
				$fileData = ob_get_contents();
				$modified = strtotime($this->yellow->page->getHeader("Last-Modified"));
				if($modified==0) $modified = $this->yellow->toolbox->getFileModified($this->yellow->page->fileName);
				if($statusCode>=301 && $statusCode<=303)
				{
					$fileData = $this->getStaticRedirect($this->yellow->page->getHeader("Location"));
					$modified = time();
				}
				$fileName = $this->getStaticFile($path, $location, $statusCode);
				if(!$this->yellow->toolbox->createFile($fileName, $fileData, true) ||
				   !$this->yellow->toolbox->modifyFile($fileName, $modified))
				{
					$statusCode = 500;
					$this->yellow->page->statusCode = $statusCode;
					$this->yellow->page->set("pageError", "Can't write file '$fileName'!");
				}
			}
			ob_end_clean();
		} else {
			$statusCode = 200;
			$modified = $this->yellow->toolbox->getFileModified($this->yellow->page->fileName);
			$fileName = $this->getStaticFile($path, $location, $statusCode);
			if(!$this->yellow->toolbox->copyFile($this->yellow->page->fileName, $fileName, true) ||
			   !$this->yellow->toolbox->modifyFile($fileName, $modified))
			{
				$statusCode = 500;
				$this->yellow->page->statusCode = $statusCode;
				$this->yellow->page->set("pageError", "Can't write file '$fileName'!");
			}
		}
		if($statusCode==200 && $analyse) $this->analyseStaticFile($scheme, $address, $base, $fileData);
		if($statusCode==404 && $probe) $statusCode = 100;
		if($statusCode==404 && $error) $statusCode = 200;
		if($statusCode>=200) ++$this->files;
		if($statusCode>=400)
		{
			++$this->errors;
			echo "ERROR building location '$location', ".$this->yellow->page->getStatusCode(true)."\n";
		}
		if(defined("DEBUG") && DEBUG>=1) echo "YellowCommand::buildStaticFile status:$statusCode location:$location<br/>\n";
		return $statusCode;
	}
	
	// Request static file
	function requestStaticFile($scheme, $address, $base, $location)
	{
		list($serverName, $serverPort) = explode(':', $address);
		if(is_null($serverPort)) $serverPort = $scheme=="https" ? 443 : 80;
		$_SERVER["HTTPS"] = $scheme=="https" ? "on" : "off";
		$_SERVER["SERVER_PROTOCOL"] = "HTTP/1.1";
		$_SERVER["SERVER_NAME"] = $serverName;
		$_SERVER["SERVER_PORT"] = $serverPort;
		$_SERVER["REQUEST_METHOD"] = "GET";
		$_SERVER["REQUEST_URI"] = $base.$location;
		$_SERVER["SCRIPT_NAME"] = $base."/yellow.php";
		$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
		$_REQUEST = array();
		return $this->yellow->request();
	}
	
	// Analyse static file, detect locations with arguments
	function analyseStaticFile($scheme, $address, $base, $rawData)
	{
		$pagination = $this->yellow->config->get("contentPagination");
		preg_match_all("/<(.*?)href=\"([^\"]+)\"(.*?)>/i", $rawData, $matches);
		foreach($matches[2] as $match)
		{
			$location = rawurldecode($match);
			if(preg_match("/^(.*?)#(.*)$/", $location, $tokens)) $location = $tokens[1];
			if(preg_match("/^(\w+):\/\/([^\/]+)(.*)$/", $location, $tokens))
			{
				if($tokens[1]!=$scheme) continue;
				if($tokens[2]!=$address) continue;
				$location = $tokens[3];
			}
			if(substru($location, 0, strlenu($base))!=$base) continue;
			$location = substru($location, strlenu($base));
			if(!$this->yellow->toolbox->isLocationArgs($location)) continue;
			if(!$this->yellow->toolbox->isLocationArgsPagination($location, $pagination))
			{
				$location = rtrim($location, '/').'/';
				if(is_null($this->locationsArgs[$location]))
				{
					$this->locationsArgs[$location] = $location;
					if(defined("DEBUG") && DEBUG>=2) echo "YellowCommand::analyseStaticFile detected location:$location<br/>\n";
				}
			} else {
				$location = rtrim($location, "0..9");
				if(is_null($this->locationsArgsPagination[$location]))
				{
					$this->locationsArgsPagination[$location] = $location;
					if(defined("DEBUG") && DEBUG>=2) echo "YellowCommand::analyseStaticFile detected location:$location<br/>\n";
				}
			}
		}
	}
	
	// Clean static files
	function cleanCommand($args)
	{
		$statusCode = 0;
		list($command, $path, $location) = $args;
		if(empty($location) || $location[0]=='/')
		{
			$statusCode = $this->cleanStatic($path, $location);
			echo "Yellow $command: Static file".(empty($location) ? "s" : "")." ".($statusCode!=200 ? "not " : "")."cleaned\n";
		} else {
			$statusCode = 400;
			echo "Yellow $command: Invalid arguments\n";
		}
		return $statusCode;
	}
	
	// Clean static files and directories
	function cleanStatic($path, $location)
	{
		$statusCode = 200;
		$path = rtrim(empty($path) ? $this->yellow->config->get("staticDir") : $path, '/');
		if(empty($location))
		{
			$statusCode = max($statusCode, $this->commandBroadcast("clean", "all"));
			$statusCode = max($statusCode, $this->cleanStaticDirectory($path));
		} else {
			$statusCode = $this->cleanStaticFile($path, $location);
		}
		return $statusCode;
	}
	
	// Clean static directory
	function cleanStaticDirectory($path)
	{
		$statusCode = 200;
		if(is_dir($path) && $this->checkStaticDirectory($path))
		{
			if(!$this->yellow->toolbox->deleteDirectory($path))
			{
				$statusCode = 500;
				echo "ERROR cleaning files: Can't delete directory '$path'!\n";
			}
		}
		return $statusCode;
	}
	
	// Clean static file
	function cleanStaticFile($path, $location)
	{
		$statusCode = 200;
		$fileName = $this->getStaticFile($path, $location, $statusCode);
		if(is_file($fileName))
		{
			if(!$this->yellow->toolbox->deleteFile($fileName))
			{
				$statusCode = 500;
				echo "ERROR cleaning files: Can't delete file '$fileName'!\n";
			}
		}
		return $statusCode;
	}
	
	// Broadcast command to other plugins
	function commandBroadcast($args)
	{
		$statusCode = 0;
		foreach($this->yellow->plugins->plugins as $key=>$value)
		{
			if($key=="command") continue;
			if(method_exists($value["obj"], "onCommand"))
			{
				$statusCode = $value["obj"]->onCommand(func_get_args());
				if($statusCode!=0) break;
			}
		}
		return $statusCode;
	}
	
	// Show software version and updates
	function versionCommand($args)
	{
		$serverVersion = $this->yellow->toolbox->getServerVersion();
		echo "Datenstrom Yellow ".YellowCore::VERSION.", PHP ".PHP_VERSION.", $serverVersion\n";
		list($command) = $args;
		list($statusCode, $dataCurrent) = $this->getSoftwareVersion();
		list($statusCode, $dataLatest) = $this->getSoftwareVersion(true);
		foreach($dataCurrent as $key=>$value)
		{
			if(strnatcasecmp($dataCurrent[$key], $dataLatest[$key])>=0)
			{
				echo "$key $value\n";
			} else {
				echo "$key $dataLatest[$key] - Update available\n";
			}
		}
		if($statusCode!=200) echo "ERROR checking updates: ".$this->yellow->page->get("pageError")."\n";
		return $statusCode;
	}
	
	// Check static configuration
	function checkStaticConfig()
	{
		$staticUrl = $this->yellow->config->get("staticUrl");
		return !empty($staticUrl);
	}
	
	// Check static directory
	function checkStaticDirectory($path)
	{
		$ok = false;
		if(!empty($path))
		{
			if($path==rtrim($this->yellow->config->get("staticDir"), '/')) $ok = true;
			if($path==rtrim($this->yellow->config->get("trashDir"), '/')) $ok = true;
			if(is_file("$path/".$this->yellow->config->get("staticDefaultFile"))) $ok = true;
			if(is_file("$path/yellow.php")) $ok = false;
		}
		return $ok;
	}
	
	// Return static file
	function getStaticFile($path, $location, $statusCode)
	{
		if($statusCode<400)
		{
			$fileName = $path.$location;
			if(!$this->yellow->lookup->isFileLocation($location)) $fileName .= $this->yellow->config->get("staticDefaultFile");
		} else if($statusCode==404) {
			$fileName = $path."/".$this->yellow->config->get("staticErrorFile");
		}
		return $fileName;
	}
	
	// Return static redirect
	function getStaticRedirect($location)
	{
		$output = "<!DOCTYPE html><html>\n<head>\n";
		$output .= "<meta http-equiv=\"content-type\" content=\"text/html; charset=utf-8\" />\n";
		$output .= "<meta http-equiv=\"refresh\" content=\"0;url=".htmlspecialchars($location)."\" />\n";
		$output .= "</head>\n</html>";
		return $output;
	}
	
	// Return content locations
	function getContentLocations()
	{
		$locations = array();
		$staticUrl = $this->yellow->config->get("staticUrl");
		list($scheme, $address, $base) = $this->yellow->lookup->getUrlInformation($staticUrl);
		$this->yellow->page->setRequestInformation($scheme, $address, $base, "", "");
		foreach($this->yellow->pages->index(true, true) as $page)
		{
			if($page->get("status")!="ignore" && $page->get("status")!="draft")
			{
				array_push($locations, $page->location);
			}
		}
		if(!$this->yellow->pages->find("/") && $this->yellow->config->get("multiLanguageMode")) array_unshift($locations, "/");
		return $locations;
	}
	
	// Return media locations
	function getMediaLocations()
	{
		$locations = array();
		$fileNames = $this->yellow->toolbox->getDirectoryEntriesRecursive($this->yellow->config->get("mediaDir"), "/.*/", false, false);
		foreach($fileNames as $fileName)
		{
			array_push($locations, "/".$fileName);
		}
		return $locations;
	}

	// Return system locations
	function getSystemLocations()
	{
		$locations = array();
		$regex = "/\.(css|gif|ico|js|jpg|png|svg|txt|woff|woff2)$/";
		$pluginDirLength = strlenu($this->yellow->config->get("pluginDir"));
		$fileNames = $this->yellow->toolbox->getDirectoryEntriesRecursive($this->yellow->config->get("pluginDir"), $regex, false, false);
		foreach($fileNames as $fileName)
		{
			array_push($locations, $this->yellow->config->get("pluginLocation").substru($fileName, $pluginDirLength));
		}
		$themeDirLength = strlenu($this->yellow->config->get("themeDir"));
		$fileNames = $this->yellow->toolbox->getDirectoryEntriesRecursive($this->yellow->config->get("themeDir"), $regex, false, false);
		foreach($fileNames as $fileName)
		{
			array_push($locations, $this->yellow->config->get("themeLocation").substru($fileName, $themeDirLength));
		}
		array_push($locations, "/".$this->yellow->config->get("robotsFile"));
		return $locations;
	}
	
	// Return command help
	function getCommandHelp()
	{
		$data = array();
		foreach($this->yellow->plugins->plugins as $key=>$value)
		{
			if(method_exists($value["obj"], "onCommandHelp"))
			{
				foreach(preg_split("/[\r\n]+/", $value["obj"]->onCommandHelp()) as $line)
				{
					list($command) = explode(' ', $line);
					if(!empty($command) && is_null($data[$command])) $data[$command] = $line;
				}
			}
		}
		uksort($data, "strnatcasecmp");
		return $data;
	}

	// Return software version
	function getSoftwareVersion($latest = false)
	{
		$data = array();
		if($this->yellow->plugins->isExisting("update"))
		{
			list($statusCode, $data) = $this->yellow->plugins->get("update")->getSoftwareVersion($latest);
		} else {
			$statusCode = 200;
			$data = array_merge($this->yellow->plugins->getData(), $this->yellow->themes->getData());
		}
		return array($statusCode, $data);
	}
}
	
$yellow->plugins->register("command", "YellowCommand", YellowCommand::VERSION);
?>
