<?php
/**
 * @version		$Id$
 * @package		Joomla.Framework
 * @subpackage	FileSystem
 * @copyright	Copyright (C) 2005 - 2007 Open Source Matters. All rights reserved.
 * @license		GNU/GPL, see LICENSE.php
 * Joomla! is free software. This version may have been modified pursuant
 * to the GNU General Public License, and as distributed it includes or
 * is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 * See COPYRIGHT.php for copyright notices and details.
 */

if (!defined('DS')) {
	/** string Shortcut for the DIRECTORY_SEPERATOR define */
	define('DS', DIRECTORY_SEPERATOR);
}

jimport('joomla.filesystem.path');

/**
 * A Folder handling class
 *
 * @static
 * @author		Louis Landry <louis.landry@joomla.org>
 * @package 	Joomla.Framework
 * @subpackage		FileSystem
 * @since		1.5
 */
class JFolder
{
	/**
	 * Create a folder -- and all necessary parent folders
	 *
	 * @param string $path A path to create from the base path
	 * @param int $mode Directory permissions to set for folders created
	 * @return boolean True if successful
	 * @since 1.5
	 */
	function create($path = '', $mode = 0755)
	{
		$config =& JFactory::getConfig();

		// Initialize variables
		$ftpFlag	= true;
		$ftpRoot	= $config->getValue('config.ftp_root');

		// Check to make sure the path valid and clean
		$path = JPath::clean($path, false);

		// Check if dir already exists
		if (JFolder::exists($path)) {
			return true;
		}

		// Do NOT use ftp if it is not enabled
		if ($config->getValue('config.ftp_enable') != 1) {
			$ftpFlag = false;
		}

		// Check for safe mode
		if ($ftpFlag == true) {
			// Connect the FTP client
			jimport('joomla.client.ftp');
			$ftp = & JFTP::getInstance($config->getValue('config.ftp_host'), $config->getValue('config.ftp_port'));
			$ftp->login($config->getValue('config.ftp_user'), $config->getValue('config.ftp_pass'));
			$ret = true;

			// Translate path to FTP path
			$path = JPath::clean(str_replace(JPATH_SITE, $ftpRoot, $path), false);
			if (!$ftp->mkdir($path)) {
				$ret = false;
			}
			if (!$ftp->chmod($path, $mode)) {
				$ret = false;
			}
			$ftp->quit();
		}
		else
		{
			// First set umask
			$origmask = @ umask(0);

			// We need to get and explode the open_basedir paths
			$obd = ini_get('open_basedir');

			// If open_basedir is et we need to get the open_basedir that the path is in
			if ($obd != null)
			{
				if (JPATH_ISWIN) {
					$obdSeparator = ";";
				} else {
					$obdSeparator = ":";
				}
				// Create the array of open_basedir paths
				$obdArray = explode($obdSeparator, $obd);
				$inOBD = false;
				// Iterate through open_basedir paths looking for a match
				foreach ($obdArray as $test) {
					if (strpos($path, $test) === 0) {
						$obdpath = $test;
						$inOBD = true;
						break;
					}
				}
				if ($inOBD == false) {
					// Return false for JFolder::create because the path to be created is not in open_basedir
					return false;
				}
			}
			// Try creating the folder and its parent folders, if necessary:
			// TODO: there's potential for an infinite loop here..!
			$ret = true;
			$dir = $path;
			while ($dir != dirname($dir))
			{
				$dir = $path;
				while (!@ mkdir($dir, $mode)) {
					$dir = dirname($dir);
					if ($obd != null) {
						if (strpos($dir, $obdpath) === false) {
							$inOBD = false;
							break 2;
						}
					}
					if ($dir == dirname($dir)) {
						break;
					}
					if (is_dir($dir)) {
						// Reset umask
						//	@ umask($origmask);
						//	return false;
					}
				}
			}
			// Reset umask
			@ umask($origmask);
		}
		return $ret;
	}

	/**
	 * Delete a folder
	 *
	 * @param string $path The path to the folder to delete
	 * @return boolean True on success
	 * @since 1.5
	 */
	function delete($path)
	{
		$config =& JFactory::getConfig();

		// Initialize variables
		$ftpFlag	= true;
		$ftpRoot	= $config->getValue('config.ftp_root');

		// Check to make sure the path valid and clean
		$path = JPath::clean($path);
		// Is this really a folder?
		if (!is_dir($path)) {
			JError::raiseWarning(21, 'JFolder::delete: Path is not a folder: '.$path);
			return false;
		}

		// Remove all the files in folder if they exist
		$files = JFolder::files($path, '.', false, true);
		if (count($files)) {
			jimport('joomla.filesystem.file');
			JFile::delete($files);
		}
		// Remove sub-folders of folder
		$folders = JFolder::folders($path, '.', false, true);
		foreach ($folders as $folder) {
			JFolder::delete($folder);
		}

		// Do NOT use ftp if it is not enabled
		if ($config->getValue('config.ftp_enable') != 1) {
			$ftpFlag = false;
		}

		if ($ftpFlag == true) {
			// Connect the FTP client
			jimport('joomla.client.ftp');
			$ftp = & JFTP::getInstance($config->getValue('config.ftp_host'), $config->getValue('config.ftp_port'));
			$ftp->login($config->getValue('config.ftp_user'), $config->getValue('config.ftp_pass'));

		}
		// In case of restricted permissions we zap it one way or the other
		// as long as the owner is either the webserver or the ftp
		if(@rmdir($path)){
			$ret = true;
		} elseif($ftpFlag == true) {
			// Translate path and delete
			$path = JPath::clean(str_replace(JPATH_SITE, $ftpRoot, $path));
			$ret = $ftp->delete($path);
		} else {
			$ret = false;
		}

		if($ftpFlag){
			$ftp->quit();
		}
		return $ret;
	}

	/**
	 * Moves a folder
	 *
	 * @param string $src The path to the source folder
	 * @param string $dest The path to the destination folder
	 * @param string $path An optional base path to prefix to the file names
	 * @return mixed Error message on false or boolean True on success
	 * @since 1.5
	 */
	function move($src, $dest, $path = '')
	{
		$config =& JFactory::getConfig();

		// Initialize variables
		$ftpFlag	= false;
		$ftpRoot	= $config->getValue('config.ftp_root');

		if ($path) {
			$src = JPath::clean($path.$src, false);
			$dest = JPath::clean($path.$dest, false);
		}

		if (!JFolder::exists($src) && !is_writable($src)) {
			return JText::_('Cannot find source folder');
		}
		if (JFolder::exists($dest)) {
			return JText::_('Folder already exists');
		}

		// Do NOT use ftp if it is not enabled
		if ($config->getValue('config.ftp_enable') != 1) {
			$ftpFlag = false;
		}

		if ($ftpFlag == true) {
			// Connect the FTP client
			jimport('joomla.client.ftp');
			$ftp = & JFTP::getInstance($config->getValue('config.ftp_host'), $config->getValue('config.ftp_port'));
			$ftp->login($config->getValue('config.ftp_user'), $config->getValue('config.ftp_pass'));

			//Translate path for the FTP account
			$src = JPath::clean(str_replace(JPATH_SITE, $ftpRoot, $src), false);
			$dest = JPath::clean(str_replace(JPATH_SITE, $ftpRoot, $dest), false);

			// Use FTP rename to simulate move
			if (!$ftp->rename($src, $dest)) {
				return JText::_('Rename failed');
			}
			$ftp->quit();
			$ret = true;
		} else {
			if (!@ rename($src, $dest)) {
				return JText::_('Rename failed');
			}
			$ret = true;
		}
		return $ret;
	}

	/**
	 * Wrapper for the standard file_exists function
	 *
	 * @param string $path Folder name relative to installation dir
	 * @return boolean True if path is a folder
	 * @since 1.5
	 */
	function exists($path) {
		$path = JPath::clean($path, false);
		return is_dir($path);
	}

	/**
	 * Utility function to read the files in a folder
	 *
	 * @param	string	$path		The path of the folder to read
	 * @param	string	$filter		A filter for file names
	 * @param	boolean	$recurse	True to recursively search into sub-folders
	 * @param	boolean	$fullpath	True to return the full path to the file
	 * @return	array	Files in the given folder
	 * @since 1.5
	 */
	function files($path, $filter = '.', $recurse = false, $fullpath = false)
	{
		// Initialize variables
		$arr = array ();

		// Check to make sure the path valid and clean
		$path = JPath::clean($path);

		// Is the path a folder?
		if (!is_dir($path)) {
			JError::raiseWarning(21, 'JFolder::files: Path is not a folder: '.$path);
			return false;
		}

		// read the source directory
		$handle = opendir($path);
		while ($file = readdir($handle)) {
			$dir = $path.$file;
			$isDir = is_dir($dir);
			if ($file != '.' && $file != '..' && $file != '.svn') {
				if ($isDir) {
					if ($recurse) {
						$arr2 = JFolder::files($dir, $filter, $recurse, $fullpath);
						$arr = array_merge($arr, $arr2);
					}
				} else {
					if (preg_match("/$filter/", $file)) {
						if ($fullpath) {
							$arr[] = $path.$file;
						} else {
							$arr[] = $file;
						}
					}
				}
			}
		}
		closedir($handle);

		asort($arr);
		return $arr;
	}

	/**
	 * Utility function to read the folders in a folder
	 *
	 * @param	string	$path		The path of the folder to read
	 * @param	string	$filter		A filter for folder names
	 * @param	boolean	$recurse	True to recursively search into sub-folders
	 * @param	boolean	$fullpath	True to return the full path to the folders
	 * @return	array	Folders in the given folder
	 * @since 1.5
	 */
	function folders($path, $filter = '.', $recurse = false, $fullpath = false)
	{
		// Initialize variables
		$arr = array ();

		// Check to make sure the path valid and clean
		$path = JPath::clean($path);

		// Is the path a folder?
		if (!is_dir($path)) {
			JError::raiseWarning(21, 'JFolder::folder: Path is not a folder: '.$path);
			return false;
		}

		// read the source directory
		$handle = opendir($path);
		while ($file = readdir($handle)) {
			$dir = $path.$file;
			$isDir = is_dir($dir);
			if (($file != '.') && ($file != '..') && ($file != '.svn') && $isDir) {
				// removes SVN directores from list
				if (preg_match("/$filter/", $file)) {
					if ($fullpath) {
						$arr[] = $dir;
					} else {
						$arr[] = $file;
					}
				}
				if ($recurse) {
					$arr2 = JFolder::folders($dir, $filter, $recurse, $fullpath);
					$arr = array_merge($arr, $arr2);
				}
			}
		}
		closedir($handle);

		asort($arr);
		return $arr;
	}

	/**
	 * Lists folder in format suitable for tree display
	 */
	function listFolderTree($path, $filter, $maxLevel = 3, $level = 0, $parent = 0)
	{
		$dirs = array ();
		if ($level == 0) {
			$GLOBALS['_JFolder_folder_tree_index'] = 0;
		}
		if ($level < $maxLevel) {
			$folders = JFolder::folders($path, $filter);
			// first path, index foldernames
			for ($i = 0, $n = count($folders); $i < $n; $i ++) {
				$id = ++ $GLOBALS['_JFolder_folder_tree_index'];
				$name = $folders[$i];
				$fullName = JPath::clean($path.DS.$name, false);
				$dirs[] = array ('id' => $id, 'parent' => $parent, 'name' => $name, 'fullname' => $fullName, 'relname' => str_replace(JPATH_ROOT, '', $fullName));
				$dirs2 = JFolder::listFolderTree($fullName, $filter, $maxLevel, $level +1, $id);
				$dirs = array_merge($dirs, $dirs2);
			}
		}
		return $dirs;
	}
}
?>
