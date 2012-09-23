<?php
/*
 * This file contains common methods for both the 
 * Elgg Language Packs plugin and the
 * GlotPress Elgg hack for ElggTranslate.com
 */

define('ELGGLP_VERSION',					'1.0.0');

define('ELGGLP_IMPORT_OK',					   -1);
define('ELGGLP_IMPORT_ERR_STRUCTURE',			1);
define('ELGGLP_IMPORT_ERR_VERSION',			    2);

function elgglp_create_languagepack_meta($info, $dir) {
	// json-encode the meta info
	$contents = json_encode($info);
	// write to language pack meta file
	file_put_contents("$dir/languagepack.meta", $contents);
}

function elgglp_create_languagemod_meta($info, $dir) {
	// json-encode the meta info
	$contents = json_encode($info);
	// write to language mod meta file
	file_put_contents("$dir/languages/languagemod.meta", $contents);
}

function elgglp_check_language_pack($dir, &$elgg_version, &$languagepack_version, $check_meta = true) {
	// if anything goes wrong, we return false for both out params
	$elgg_version = false;
	$languagepack_version = false;
	// install directory exists and has languages/ directory?
	$install = file_exists("$dir/install/languages") && is_dir("$dir/install/languages");
	// root directory has languages/ directory?
	$core = file_exists("$dir/languages") && is_dir("$dir/languages");
	// mod directory exists?
	$mod = file_exists("$dir/mod") && is_dir("$dir/mod");
	// need to check for language pack meta?
	if ( $check_meta ) {
		// we are looking at a language pack proper
		$meta = file_exists("$dir/languagepack.meta") && is_file("$dir/languagepack.meta");
		if ( $meta ) {
			$meta = json_decode(file_get_contents("$dir/languagepack.meta"), true);
			$elgg_version = $meta['elgg_version'];
			$languagepack_version = $meta['languagepack_version'];
		}
	} else {
		// we must be looking at an Ellg product folder
		$meta = file_exists("$dir/version.php") && is_file("$dir/version.php");
		if ( $meta ) {
			include "$dir/version.php";
			$elgg_version = $release;
			$languagepack_version = ELGGLP_VERSION;
		}
	}
	// return result
	return $install && $core && $mod && $meta;
}

function elgglp_read_languagemod_meta($src_dir) {
	$meta_name = "$src_dir/languages/languagemod.meta";
	if ( !file_exists($meta_name) ) return false;
	$meta_contents = file_get_contents($meta_name);
	$info = json_decode($meta_contents, true);
	$slug = basename($src_dir);
	if ( $info['unique'] == $slug ) {
		return $info;
	} else {
		return false;
	}
}

function elgglp_read_plugin_manifest($src_dir) {
	if ( !file_exists("$src_dir/languages") ) return false;
	$manifest_name = "$src_dir/manifest.xml";
	if ( !file_exists($manifest_name) ) return false;
	$manifest_contents = file_get_contents($manifest_name);
	try {
		$manifest = new SimpleXMLElement($manifest_contents);				
	} catch ( Exception $e ) {
		error_log("elgglp_read_manifest: $manifest_name not valid");
		return false;
	}
	$slug = basename($src_dir);
	$info = array(
		'name' => (string)$manifest->name,
		'version' => (string)$manifest->version,
		'description' => (string)$manifest->description,
		'unique' => $slug,
	);
	return $info;
}

/**
 * Creates a directory with a unique directory name.
 * 
 * @param string|boolean $dir The directory under which the new directory should be created. If empty, the system temporary folder will be used
 * @param string $prefix A prefix to prepended to the generated directory name. If empty, no prefix will be added
 * @return string|boolean The name of the newly created directory, of false if a directory could not be created
 */
function elgglp_tempdir($dir = false, $prefix = '') {
	if ( !$dir ) $dir = sys_get_temp_dir();
    if ( ($tempfile = tempnam($dir, $prefix)) ) {
		unlink($tempfile);
		if ( mkdir($tempfile) ) {
			return $tempfile;
		}
	}
	return false;
}

function elgglp_copy_languagemod($meta, $srcdir, $filters) {
	$dstdir = $filters['dst_dir'];
	if ( $meta['unique'] == 'install' ) {
		$dstdir = "$dstdir/install";
	} else if ( $meta['unique'] != 'core' ) {
		$dstdir = "$dstdir/mod/$meta[unique]";
	}
	if ( @elgglp_copy_languages($srcdir, $dstdir, $filters) ) {
		if ( $filters['needs_manifest'] ) {
			@elgglp_create_languagemod_meta($meta, $dstdir);
		}
	}
}

function elgglp_copy_languages($srcdir, $dstdir, $filters) {
	// copy filter options used here into local variables
	$langs = @$filters['langs'];
	$overwrite = (bool)@$filters['overwrite'];
	$ignore_en = (bool)@$filters['ignore_en'];
	// keep track of whether any file was copied from this folder
	$copied = false;
	// get all the files that match an Elgg language file and iterate
	$all_files = array_merge(glob("$srcdir/languages/??.php"), glob("$srcdir/languages/??_??.php"));
	foreach ( $all_files as $file ) {
		// basic name of the file is the locale name
		$lang = basename($file, '.php');
		// should the current locale be filtered out?
		if ( $langs && !in_array($lang, $langs) ) {
			continue;
		}
		// should we ignore this if it is the original English file?
		if ( $ignore_en && $lang == 'en' ) {
			continue;
		}
		// the destination file we should write to
		$to_file = "$dstdir/languages/" . basename($file);
		// if it exists already, can we overwrite it?
		if ( !$overwrite && file_exists($to_file) ) {
			continue;
		}
		// create the folder if it does not exist
		if ( !file_exists("$dstdir/languages") ) {
			@mkdir("$dstdir/languages", 0777, true);
		}
		// copy the file
		if ( @copy($file, $to_file) ) {
			// signal at least one file was copied
			$copied = true;
		}
	}
	// all done in this folder
	return $copied;
}

/**
 * Zip an entire folder into a given file
 * 
 * @param type $source the folder to zip up
 * @param type $destination the filename of the zip file to create
 * @return boolean true if the zip file was successfully create, false otherwise
 */
function elgglp_zip_folder($source, $destination) {
	$zip = new ZipArchive();
    if ( !$zip->open($destination, ZIPARCHIVE::CREATE) ) {
        return false;
    }

    $source = str_replace('\\', '/', realpath($source));

    if ( is_dir($source) === true )
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source), RecursiveIteratorIterator::SELF_FIRST);

        foreach ( $files as $file )
        {
            $file = str_replace('\\', '/', $file);

            // Ignore all hidden files and folders
            if ( $file[0] == '.' )
                continue;

            $file = realpath($file);

            if ( is_dir($file) === true )
            {
                $zip->addEmptyDir(str_replace($source . '/', '', $file . '/'));
            }
            else if (is_file($file) === true)
            {
                $zip->addFromString(str_replace($source . '/', '', $file), file_get_contents($file));
            }
        }
    }
    else if (is_file($source) === true)
    {
        $zip->addFromString(basename($source), file_get_contents($source));
    }

    return $zip->close();
}

/**
 * Completely and recursively delete a directory
 * 
 * @param type $dirname the directory to remove
 * @return boolean true if the directory was removed successfully, false otherwise
 */
function elgglp_deltree($dirname) {
	// Sanity check
	if ( !file_exists($dirname) ) { return false; }
	// Simple delete if it is an ordinary file or link
	if ( is_file($dirname) || is_link($dirname) ) {
		return unlink($dirname);
	} 
	// Loop through each entry in the folder
	$dir = dir($dirname); 
	while ( false !== ($entry = $dir->read()) ) {
		// Skip special pointers 
		if ( $entry == '.' || $entry == '..' ) { 
			continue; 
		} 
		// Recurse - if $entry is a file, this method will delete it and return
		elgglp_deltree("$dirname/$entry");
	}
	// Clean up 
	$dir->close();
	return rmdir($dirname); 
}

function elgglp_recurse_language_pack($srcdir, $filters, $callback) {
	// remove possible trailing slash from source directory
	$srcdir = rtrim($srcdir, '/');
	// remove possible trailing slash from target directory if it exists
	if ( $filters['dst_dir'] ) {
		$filters['dst_dir'] = rtrim($filters['dst_dir'], '/');
	}
	// copy filter options used here into local variables
	$projs = $filters['projs'];
	$needs_meta = $filters['needs_meta'];
	$needs_manifest = $filters['needs_manifest'];
	$return = $filters['return_array'];
	// check whether it is a valid Elgg Language Pack
	$elgg_version = null;
	$languagepack_version = null;
	if ( !elgglp_check_language_pack($srcdir, $elgg_version, $languagepack_version, $needs_meta) ) {
		return ELGGLP_IMPORT_ERR_STRUCTURE;
	}
	// is the language pack for the right Language Pack or Elgg version?
	$release = get_version(true);
	if ( $release != $elgg_version || '1.0.0' != $languagepack_version ) {
		return ELGGLP_IMPORT_ERR_VERSION;
	}
	// should return list of language mods?
	if ( $return ) {
		$allmods = array();
	}
	// unless filtered, process the core language files
	if ( empty($projs) || in_array('core', $projs) ) {
		$meta = array(
			'version' => $release,
			'name' => 'Elgg Core',
			'description' => 'The core elements of the social networking engine',
			'unique' => 'core',
		);
		if ( $callback ) @$callback($meta, $srcdir, $filters);
		if ( $return ) $allmods[] = $meta;
	}
	// unless filtered, process the install language files
	if ( empty($projs) || in_array('install', $projs) ) {
		$meta = array(
			'version' => $release,
			'name' => 'Elgg Install',
			'description' => 'Install wizard for setting up and configuring a new Elgg instance, or upgrading an existing one',
			'unique' => 'install',
		);
		if ( $callback ) @$callback($meta, "$srcdir/install", $filters);
		if ( $return ) $allmods[] = $meta;
	}
	// loop through all directories in mod/ looking for language mods
	$dir = dir("$srcdir/mod");
	while ( false !== ($entry = $dir->read()) ) {
		$curdir = "$srcdir/mod/$entry";
		if ( $entry[0] != '.' && is_dir($curdir) ) {
			// skip if filtered out by user
			if ( $projs && !in_array($entry, $projs) ) continue;
			// check whether at least one of meta and manifest files exist
			$meta_file = "$curdir/languages/languagemod.meta";
			$manifest_file = "$curdir/manifest.xml";
			$meta_exists = file_exists($meta_file);
			$manifest_exists = file_exists($manifest_file);
			if ( !$meta_exists && !$manifest_exists ) continue;
			// try and create meta data from either
			if ( (!$needs_meta || $meta_exists) && (!$needs_manifest || $manifest_exists) ) {
				if ( !$meta_exists || !is_array($meta = elgglp_read_meta($curdir)) ) {
					if ( !$manifest_exists || !is_array($meta = elgglp_read_manifest($curdir)) ) {
						continue;
					}
				}
				if ( $callback ) @$callback($meta, "$srcdir/mod/$entry", $filters);
				if ( $return ) $allmods[] = $meta;
			}
		}
	}
	$dir->close();
	if ( $return ) return $allmods;
}
