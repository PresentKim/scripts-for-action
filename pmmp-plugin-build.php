<?php

declare(strict_types=1);

const IGNORE_FILES = [
    "README.md",
    "BUILDING.md",
    "SECURITY.md",
    "CONFIGURATION.md",
    "composer.json",
    "composer.lock",
    "phpstan.neon.dist",
    "phpstan-baseline.neon",
];

/** Scan all files that do not start with dot("."), including subdirectories */
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

/** Remove directory and its contents recursively */
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

/** Clear path separator */
function clear_path(string $path) : string{
    return rtrim(str_replace("\\", "/", $path), "/");
}

/** Create directory safely, creating directories if not exists */
function safe_path_join(string ...$paths) : string{
    $path = implode("/", $paths);
    if(!file_exists($path)){
        mkdir($path, 0777, true);
    }
    return $path;
}

/** Make path relative to base path */
function make_releative(string $path, string $basePath) : string{
    if(str_starts_with($path, $basePath)){
        return substr($path, strlen($basePath));
    }
    return $path;
}

function parsePoggitYml(string $poggitYmlPath, string $name, string $projectDir) : array{
    $poggitYml = yaml_parse(file_get_contents($poggitYmlPath));
    if(!is_array($poggitYml)){
        echo "  - FAILED : Invalid .poggit.yml in $projectDir\n";
        exit(1);
    }

    $project = $poggitYml["projects"][$name] ?? array_pop($poggitYml["projects"]);
    if(!is_array($project)){
        echo "  - FAILED : Invalid project in .poggit.yml in $projectDir\n";
        exit(1);
    }

    $projectPath = $project["path"] ?? "." ?: ".";
    $projectDir = realpath($projectDir . "/" . $projectPath);
    $projectLibs = [];
    foreach($project["libs"] ?? [] as $lib){
        [$owner, $repo] = explode("/", $lib["src"]);
        $tree = trim($lib["version"], "^~");
        $projectLibs[] = [$owner, $repo, $tree];
    }
    return [$projectDir, $projectLibs];
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

                $path = $virionDir . "/" . make_releative($name, $zipPrefix);
                $dir = dirname($path);
                if(!file_exists($dir)){
                    mkdir($dir, 0777, true);
                }
                file_put_contents($path, $virionZip->getFromName($name));
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

function infect_virion(
    string $targetDir, string $antibodyBase, string $virionDir, string $virionName, string $sreNamespacePrefix = ""
) : void{
    $virionLibs = [];
    $poggitYmlPath = $virionDir . "/.poggit.yml";
    if(file_exists($poggitYmlPath)){
        [$virionDir, $virionLibs] = parsePoggitYml($poggitYmlPath, $virionName, $virionDir);
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
    $ligase = clear_path("/src/" . make_releative($antibody, $sreNamespacePrefix)) . "/";

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

            $path = $targetDir . $newRel;
            $dir = dirname($path);
            if(!file_exists($dir)){
                mkdir($dir, 0777, true);
            }
            file_put_contents($path, change_dna($contents, $antigen, $antibody));
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

function build_phar(string $pharPath, array $pluginYml, array $ignoreFiles) : void{
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
        foreach($ignoreFiles as $ignoreFile){
            if(str_ends_with($file, $ignoreFile)){
                continue 2;
            }
        }
        $phar->addFile(BUILD_DIR . "/" . $file, $file);
    }
    $phar->stopBuffering();
}

$firstArg = $argv[1] ?? "";
if($firstArg === "?" || strtolower($firstArg) === "help"){
    echo "Usage: php $argv[0] [project dir] [ignore files]\n";
    echo "  - project dir: The directory where the plugin is located\n";
    echo "      - default: ./\n";
    echo "  - ignore files: Files to ignore when building the plugin. Separate multiple files with commas\n";
    echo "      - default: README.md, BUILDING.md, SECURITY.md, CONFIGURATION.md, composer.json, composer.lock, phpstan.neon.dist, phpstan-baseline.neon\n";
    exit(0);
}

$baseDir = clear_path(realpath(getcwd() . "/" . ($firstArg === "" ? "." : $firstArg)));
$ignoreFiles = array_map("trim", array_slice($argv, 2));
if(count($ignoreFiles) === 0){
    $ignoreFiles = IGNORE_FILES;
}
$poggitYml = $baseDir . "/.poggit.yml";
if(file_exists($poggitYml)){
    [$baseDir, $virions] = parsePoggitYml($poggitYml, "", $baseDir);
}else{
    $virions = [];
}
define("WORK_DIR", $baseDir);
define("RELEASE_DIR", safe_path_join(WORK_DIR, ".releases"));
define("CACHE_DIR", safe_path_join(RELEASE_DIR, "cache"));
define("BUILD_DIR", safe_path_join(RELEASE_DIR, "plugin"));

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
    $path = BUILD_DIR . "/$file";
    $dir = dirname($path);
    if(!file_exists($dir)){
        mkdir($dir, 0777, true);
    }
    copy(WORK_DIR . "/$file", $path);
}

$start = microtime(true);
$pluginName = $pluginYml["name"];
$pluginVersion = $pluginYml["version"];
if(isset($pluginYml["virions"])){
    $virions = [];
    foreach($pluginYml["virions"] as $virion){
        $virionData = explode("/", $virion);
        if(empty($virionData[1])){
            echo "Invalid virion format: $virion\n";
            exit(1);
        }
        $virions[] = $virionData;
    }
}
echo "Processing $pluginName v$pluginVersion\n";

foreach($virions as $virion){
    echo "\n";
    @[$virionOwner, $virionRepo, $virionTree] = $virion;
    $virionDir = prepare_virion($virionOwner, $virionRepo, $virionTree);

    echo "  - Start virion infecting...\n";
    $main = $pluginYml["main"];
    $antibodyBase = substr($main, 0, -strlen(strrchr($main, "\\")));
    infect_virion(BUILD_DIR, $antibodyBase, $virionDir, $virionRepo, $pluginYml["src-namespace-prefix"] ?? "");
}

echo "\n";
echo "Building phar...\n";
build_phar(RELEASE_DIR . "/$pluginName-v$pluginVersion.phar", $pluginYml, $ignoreFiles);
echo "Done in " . round(microtime(true) - $start, 3) . "s";
exit(0);
