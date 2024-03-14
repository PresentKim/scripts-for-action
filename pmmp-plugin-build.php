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

function safe_dir(string ...$paths) : string{
	$path = implode("/", $paths);
	if(!file_exists($path)){
		mkdir($path, 0777, true);
	}
	return $path;
}

function make_releative(string $path, string $basePath) : string{
	if(str_starts_with($path, $basePath)){
		return substr($path, strlen($basePath));
	}
	return $path;
}

function prepare_virion(string $virionOwner, string $virionRepo, string|null $virionTree) : string{
	if(empty($virionTree)){
		$virionTree = "[git]";
	}
	echo "Preparing virion $virionOwner/$virionRepo@$virionTree\n";

	$virionDir = CACHE_DIR . "/$virionOwner-$virionRepo-$virionTree";
	if(!file_exists($virionDir) || !is_dir($virionDir)){
		$virionZipPath = CACHE_DIR . "/$virionOwner.$virionRepo.$virionTree.zip";
		$virionDownloadUrl = "https://github.com/$virionOwner/$virionRepo/archive/$virionTree.zip";
		echo "  - Downloading virion from $virionDownloadUrl\n";

		$virionZipContents = @file_get_contents($virionDownloadUrl);
		if($virionZipContents === false){
			echo "  - Failed download zip from above url\n";
			$gitUrl = "https://github.com/$virionOwner/$virionRepo.git";
			echo "  - Try clone git from $gitUrl\n";
			exec(
				"git clone $gitUrl $virionDir"
				. (PHP_OS_FAMILY === "Windows" ? " > NUL 2>&1" : " > /dev/null 2>&1")
			);

			if(!file_exists($virionDir)){
				echo "  - Failed to download virion\n";
				exit(1);
			}
			echo "  - Successfully cloned virion git to $virionDir\n";
		}else{
			file_put_contents($virionZipPath, $virionZipContents);
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
			echo "  - Successfully unzip virion zip to $virionDir\n";
		}
	}else{
		echo "  - Using cached virion from $virionDir\n";
	}

	return $virionDir;
}

function infect_virion(string $targetDir, string $antibodyBase, string $virionDir, string $virionName) : void{
	$poggitYmlPath = $virionDir . "/.poggit.yml";
	if(!file_exists($poggitYmlPath)){
		echo "  - FAILED : Not found .poggit.yml in $virionDir\n";
		exit(1);
	}

	$poggitYml = yaml_parse(file_get_contents($poggitYmlPath));
	if(!is_array($poggitYml)){
		echo "  - FAILED : Invalid .poggit.yml in $virionDir\n";
		exit(1);
	}

	if(!isset($poggitYml["projects"][$virionName])){
		echo "  - FAILED : Not found virion in .poggit.yml in $virionDir\n";
		exit(1);
	}
	$virionProject = $poggitYml["projects"][$virionName];
	$virionPath = $virionProject["path"] ?: ".";
	$virionDir = realpath($virionDir . "/" . $virionPath);
	$virionLibs = [];
	foreach($virionProject["libs"] ?? [] as $lib){
		[$virionOwner, $virionRepo] = explode("/", $lib["src"]);
		$virionTree = trim($lib["version"], "^~");
		$virionLibs[] = [$virionOwner, $virionRepo, $virionTree];
	}

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
	$infectionLogPath = $targetDir . "/virus-infections.json";
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
	if(count($virionLibs) > 0){
		echo "  - Detect libs: " . PHP_EOL;
		foreach($virionLibs as [$virionOwner, $virionRepo, $virionTree]){
			echo "    - $virionOwner/$virionRepo@$virionTree" . PHP_EOL;
			$libVirionDir = prepare_virion($virionOwner, $virionRepo, $virionTree);
			infect_virion($virionDir, $antigen, $libVirionDir, $virionRepo);
		}
	}

	$antibody = $antibodyBase . "\\libs\\" . $antigen;
	$infectionLog[$antibody] = $virionYml;
	echo "  - Detect antibody: $antibody\n";

	echo "  - Infect antigen to antibody...";
	/** @var SplFileInfo $fileInfo */
	foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($targetDir)) as $name => $fileInfo){
		if($fileInfo->isDir() || $fileInfo->getExtension() !== "php"){
			continue;
		}
		file_put_contents($name, change_dna(file_get_contents($name), $antigen, $antibody));
	}

	$restriction = clear_path("/src/" . $antigen) . "/";
	$ligase = clear_path("/src/" . $antibody) . "/";

	foreach(scandir_recursive($virionDir) as $file){
		$source = $virionDir . "/" . $file;
		$contents = file_get_contents($source);
		if(str_starts_with($file, "/resources/")){
			file_put_contents($targetDir . "/" . $file, $contents);
		}elseif(str_starts_with($file, "/src/")){
			if(!str_starts_with($file, $restriction)){
				echo "Warning: File $file in virion is not under the antigen $antigen ($restriction)" . PHP_EOL;
				$newRel = $file;
			}else{
				$newRel = $ligase . make_releative($file, $restriction);
			}

			safe_file_put_contents(
				$targetDir . $newRel,
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

function build_phar(string $pharPath, array $pluginYml) : void{
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
This file has been generated using Github Action of PresentKim at %s
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
	foreach(scandir_recursive(BUILD_DIR) as $file){
		$phar->addFile(BUILD_DIR . "/" . $file, $file);
	}
	$phar->stopBuffering();
}

if($argc < 2){
	echo "Usage: php " . basename(__FILE__) . " <base-directory>\n";
	exit(1);
}
[, $baseDir] = $argv;
define("WORK_DIR", clear_path(realpath(dirname(__DIR__, 2) . "/$baseDir")));
define("RELEASE_DIR", safe_dir(WORK_DIR, ".releases"));
define("CACHE_DIR", safe_dir(RELEASE_DIR, "cache"));
define("BUILD_DIR", safe_dir(RELEASE_DIR, "plugin"));

// Load cache mapping file
$cacheJson = RELEASE_DIR . "/releases.lock";
if(file_exists($cacheJson)){
	$cache = json_decode(file_get_contents($cacheJson), true);
}else{
	$cache = [];
}

// Read plugin.yml file
$pluginYml = WORK_DIR . "/plugin.yml";
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
if(file_exists(BUILD_DIR)){
	rmdir_recursive(BUILD_DIR);
}
mkdir(BUILD_DIR, 0777, true);
foreach(scandir_recursive(WORK_DIR) as $file){
	safe_file_put_contents(
		BUILD_DIR . "/" . $file,
		file_get_contents(WORK_DIR . "/" . $file)
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

	@[$virionOwner, $virionRepo, $virionTree] = $virionData;
	$virionDir = prepare_virion($virionOwner, $virionRepo, $virionTree);

	echo "  - Start virion infecting...\n";
	$main = $pluginYml["main"];
	$antibodyBase = substr($main, 0, -strlen(strrchr($main, "\\")));
	infect_virion(BUILD_DIR, $antibodyBase, $virionDir, $virionRepo);
}

echo "\n";
echo "Building phar...\n";
build_phar(RELEASE_DIR . "/$pluginName-v$pluginVersion.phar", $pluginYml);
echo "Done in " . round(microtime(true) - $start, 3) . "s";
exit(0);
