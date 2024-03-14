<?php

declare(strict_types=1);

/** Recursively scan a directory and return all files */
function scandir_recursive(string $dir, string $baseDir = "") : array{
	$files = [];
	$dirs = [];
	foreach(scandir($dir) as $file){
		if(str_starts_with($file, ".")){
			continue;
		}
		$innerPath = $baseDir . "/" . $file;
		if(is_dir($dir . "/" . $file)){
			$dirs[] = scandir_recursive($dir . "/" . $file, $innerPath);
		}else{
			$files[] = $innerPath;
		}
	}
	$dirs[] = $files;
	return array_merge(...$dirs);
}

function rmdir_recursive($folderPath) : bool{
	if(!is_dir($folderPath)){
		return false;
	}
	$files = array_diff(scandir($folderPath), ['.', '..']);
	foreach($files as $file){
		if((is_dir("$folderPath/$file"))){
			rmdir_recursive("$folderPath/$file");
		}else{
			unlink("$folderPath/$file");
		}
	}
	return rmdir($folderPath);
}

function clear_path(string $path) : string{
	return rtrim(str_replace("\\", "/", $path), "/");
}

function safe_file_put_contents(string $filename, string $data) : void{
	@mkdir(dirname($filename), 0777, true);
	file_put_contents($filename, $data);
}

function make_releative(string $path, string $basePath) : string{
	if(str_starts_with($path, $basePath)){
		return substr($path, strlen($basePath));
	}
	return $path;
}


function infect_virion(string $pluginDir, array $pluginYml, string $virionDir) : void{
	/* Check to make sure virion.yml exists in the virion */
	$virionYmlPath = $virionDir . "/virion.yml";
	if(!file_exists($virionYmlPath)){
		echo "  - FAILED : Not found virion.yml in $virionDir\n";
		exit(1);
	}

	$virionYml = yaml_parse(file_get_contents($virionYmlPath));
	if(!is_array($virionYml)){
		echo "  - FAILED : Invalid virion.yml in $virionDir\n";
		exit(1);
	}

	/* Infection Log. File that keeps all the virions injected into the plugin */
	$infectionLogPath = $pluginDir . "/virus-infections.json";
	$infectionLog = file_exists($infectionLogPath) ? json_decode(file_get_contents($infectionLogPath), true) : [];

	/* Virion injection process now starts */
	$virionName = $virionYml["name"];
	$antigen = $virionYml["antigen"];
	foreach($infectionLog as $log){
		if($log["antigen"] === $antigen){
			echo "  - PASSED : already infected with $virionName" . PHP_EOL;
			return;
		}
	}
	echo "  - Detect antigen: $antigen\n";

	$main = $pluginYml["main"];
	$antibody = substr($main, 0, -strlen(strrchr($main, "\\"))) . "\\libs\\" . $antigen;
	$infectionLog[$antibody] = $virionYml;
	echo "  - Detect antibody: $antibody\n";

	echo "  - Change codes of plugin...";
	/** @var SplFileInfo $fileInfo */
	foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pluginDir)) as $name => $fileInfo){
		if($fileInfo->isDir() || $fileInfo->getExtension() !== "php"){
			continue;
		}
		file_put_contents($name, change_dna(file_get_contents($name), $antigen, $antibody));
	}
	echo "  - Done\n";

	$restriction = clear_path("/src/" . $antigen) . "/";
	$ligase = clear_path("/src/" . $antibody) . "/";

	echo "  - Change codes of virion...";
	foreach(scandir_recursive($virionDir) as $file){
		$source = $virionDir . "/" . $file;
		$contents = file_get_contents($source);
		if(str_starts_with($file, "/resources/")){
			file_put_contents($pluginDir . "/" . $file, $contents);
		}elseif(str_starts_with($file, "/src/")){
			if(!str_starts_with($file, $restriction)){
				echo "Warning: File $file in virion is not under the antigen $antigen ($restriction)" . PHP_EOL;
				$newRel = $file;
			}else{
				$newRel = $ligase . make_releative($file, $restriction);
			}

			safe_file_put_contents(
				$pluginDir . $newRel,
				change_dna($contents, $antigen, $antibody)
			);
		}
	}
	echo "  - Done\n";

	file_put_contents($infectionLogPath, json_encode($infectionLog));
}

function change_dna(string $chromosome, string $antigen, string $antibody) : string{
	$tokens = token_get_all($chromosome);
	$tokens[] = ""; // should not be valid though
	foreach($tokens as $offset => $token){
		if(!is_array($token) or $token[0] !== T_WHITESPACE){
			[$id, $str, $line] = is_array($token) ? $token : [-1, $token, $line ?? 1];
			//namespace test; is a T_STRING whereas namespace test\test; is not.
			if(isset($init, $prefixToken) and $id === T_STRING){
				if($str === $antigen){ // case-sensitive!
					$tokens[$offset][1] = $antibody . substr($str, strlen($antigen));
				}elseif(stripos($str, $antigen) === 0){
					echo "  - WARNING : Not replacing FQN $str case-insensitively.\n";
				}
				unset($init, $prefixToken);
			}elseif($id === T_NAMESPACE){
				$init = $offset;
				$prefixToken = $id;
			}elseif($id === T_NAME_QUALIFIED){
				if(($str[strlen($antigen)] ?? "\\") === "\\"){
					if(str_starts_with($str, $antigen)){ // case-sensitive!
						$tokens[$offset][1] = $antibody . substr($str, strlen($antigen));
					}elseif(stripos($str, $antigen) === 0){
						echo "  - WARNING : Not replacing FQN $str case-insensitively.\n";
					}
				}
				unset($init, $prefixToken);
			}elseif($id === T_NAME_FULLY_QUALIFIED){
				if(str_starts_with($str, "\\$antigen\\")){ // case-sensitive!
					$tokens[$offset][1] = "\\" . $antibody . substr($str, strlen($antigen) + 1);
				}elseif(stripos($str, "\\" . $antigen . "\\") === 0){
					echo "  - WARNING : Not replacing FQN $str case-insensitively.\n";
				}
				unset($init, $prefixToken);
			}
		}
	}
	$ret = "";
	foreach($tokens as $token){
		$ret .= is_array($token) ? $token[1] : $token;
	}
	return $ret;
}

function build_phar(string $pharPath, string $pluginDir, array $pluginYml) : void{
	$metadata = [
		"name" => $pluginYml["name"],
		"version" => $pluginYml["version"],
		"main" => $pluginYml["main"],
		"api" => $pluginYml["api"],
		"depend" => $pluginYml["depend"] ?? "",
		"description" => $pluginYml["description"] ?? "",
		"authors" => $pluginYml["authors"] ?? "",
		"website" => $pluginYml["website"] ?? "",
		"creationDate" => time()
	];
	$stubMetadata = [];
	foreach($metadata as $key => $value){
		$stubMetadata[] = addslashes(ucfirst($key) . ": " . (is_array($value) ? implode(", ", $value) : $value));
	}
	$stub = sprintf(<<< STUB
<?php
echo "PocketMine-MP plugin %s v%s
This file has been generated using PresentKim's pmmp-plugin-build script at %s
----------------
%s
";
__HALT_COMPILER();
>>>
STUB, $metadata["name"], $metadata["version"], date("r"), implode("\n", $stubMetadata));

	if(file_exists($pharPath)){
		try{
			Phar::unlinkArchive($pharPath);
		}catch(PharException){
			unlink($pharPath);
		}
	}

	$phar = new Phar($pharPath);
	$phar->setMetadata($metadata);
	$phar->setStub($stub);
	$phar->setSignatureAlgorithm(Phar::SHA1);
	$phar->startBuffering();
	foreach(scandir_recursive($pluginDir) as $file){
		$phar->addFile($pluginDir . "/" . $file, $file);
	}
	$phar->stopBuffering();
}

if($argc < 2){
	echo "Usage: php " . basename(__FILE__) . " <base-directory>\n";
	exit(1);
}
[, $baseDir] = $argv;
$workDir = realpath(dirname(__DIR__, 2) . "/$baseDir");

// Create cache directory if not exists
$releaseDir = $workDir . "/.releases";
$cacheDir = $releaseDir . "/cache";
if(!file_exists($cacheDir) || !is_dir($cacheDir)){
	mkdir($cacheDir, 0777, true);
}

// Load cache mapping file
$cacheJson = $releaseDir . "/releases.lock";
if(file_exists($cacheJson)){
	$cache = json_decode(file_get_contents($cacheJson), true);
}else{
	$cache = [];
}

// Read plugin.yml file
$pluginYml = $workDir . "/plugin.yml";
if(!file_exists($pluginYml)){
	echo "plugin.yml not found\n";
	exit(1);
}
$pluginYml = yaml_parse(file_get_contents($pluginYml));
if($pluginYml === false){
	echo "Failed to parse plugin.yml\n";
	exit(1);
}

// Copy work directory to release directory
$pluginDir = $releaseDir . "/plugin";
if(file_exists($pluginDir)){
	rmdir_recursive($pluginDir);
}
mkdir($pluginDir, 0777, true);
foreach(scandir_recursive($workDir) as $file){
	safe_file_put_contents(
		$pluginDir . "/" . $file,
		file_get_contents($workDir . "/" . $file)
	);
}

$start = microtime(true);
$pluginName = $pluginYml["name"];
$pluginVersion = $pluginYml["version"];
$virions = $pluginYml["virions"] ?? [];
echo "Processing $pluginName v$pluginVersion\n";

foreach($virions as $virion){
	echo "\n";
	$virionData = explode("/", $virion);
	if(empty($virionData[1])){
		echo "Invalid virion format: $virion\n";
		exit(1);
	}

	if(empty($virionData[2])){
		$virionData[2] = "main";
	}
	[$virionOwner, $virionRepo, $virionTree] = $virionData;
	echo "Preparing virion $virionOwner/$virionRepo@$virionTree\n";

	$virionDir = $cacheDir . "/$virionOwner-$virionRepo-$virionTree";
	if(!file_exists($virionDir) || !is_dir($virionDir)){
		echo "  - Downloading virion $virionOwner/$virionRepo@$virionTree\n";
		$virionZipPath = $cacheDir . "/$virionOwner.$virionRepo.$virionTree.zip";
		$virionDownloadUrl = "https://github.com/$virionOwner/$virionRepo/archive/$virionTree.zip";

		file_put_contents($virionZipPath, file_get_contents($virionDownloadUrl));
		$virionZip = new ZipArchive();
		$virionZip->open($virionZipPath);
		$count = $virionZip->numFiles;
		$zipPrefix = $virionZip->getNameIndex(0);
		for($i = 0; $i < $count; $i++){
			$name = $virionZip->getNameIndex($i);
			if(str_ends_with($name, "/")){
				continue;
			}

			safe_file_put_contents($virionDir . "/" . make_releative($name, $zipPrefix),
				$virionZip->getFromName($name));
		}
		$virionZip->close();
		unlink($virionZipPath);
		echo "  - Unzip virion to $virionDir\n";
	}else{
		echo "  - Using cached virion from $virionDir\n";
	}

	echo "  - Start virion infecting...\n";
	infect_virion($pluginDir, $pluginYml, $virionDir);
}

echo "\n";
echo "Building phar...\n";
build_phar($releaseDir . "/$pluginName-v$pluginVersion.phar", $pluginDir, $pluginYml);
echo "Done in " . round(microtime(true) - $start, 3) . "s";
exit(0);
