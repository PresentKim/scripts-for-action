<?php

declare(strict_types=1);

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

/** Remove comments of JSON content */
function remove_json_comments($jsonContent) : string{
    return preg_replace(
        '~(" (?:[^"\\\\]++|\\\\.)*+ ") | \# [^\r\n]*+ | // [^\r\n]*+ | /\* .*? \*/~sx',
        '$1',
        $jsonContent
    );
}

/** Size suffix from file size */
function suffixUnitOfSize(int $beforeSize, int $afterSize) : array{
    $savingRate = number_format(($beforeSize - $afterSize) / $beforeSize * 100, 2) . "%";

    $units = ["B", "KB", "MB", "GB", "TB"];
    $unit = 0;
    while($beforeSize > 99){
        $beforeSize /= 1024;
        $afterSize /= 1024;
        $unit++;
    }

    $before = number_format($beforeSize, 2) . " $units[$unit]";
    $after = number_format($afterSize, 2) . " $units[$unit]";
    return [$before, $after, $savingRate];
}

$archiveName = $argv[1] ?? "release";
$baseDir = $argv[2] ?? ".";
define("WORK_DIR", clear_path(realpath(getcwd() . "/$baseDir")));
define("RELEASE_DIR", safe_path_join(WORK_DIR, ".releases"));
define("CACHE_DIR", safe_path_join(RELEASE_DIR, "cache"));

// Load cache mapping file
$cacheJson = RELEASE_DIR . "/releases.lock";
if(file_exists($cacheJson)){
    $cache = json_decode(file_get_contents($cacheJson), true);
}else{
    $cache = [];
}

// Create zip archives
$minifiedArchivePath = clear_path(RELEASE_DIR . "/$archiveName.zip");
$minifiedArchive = new ZipArchive();
$minifiedArchive->open($minifiedArchivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

$originalArchivePath = clear_path(RELEASE_DIR . "/$archiveName.original.zip");
$originalArchive = new ZipArchive();
$originalArchive->open($originalArchivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

$failedFiles = [];
$minifiedFiles = [];

$totalOriginalSize = 0;
$totalMinifiedSize = 0;
foreach(scandir_recursive(WORK_DIR) as $innerPath){
    if($innerPath === "/README.md"){
        continue;
    }
    $originalPath = clear_path(WORK_DIR . $innerPath);
    $modifiedPath = clear_path(CACHE_DIR . $innerPath);
    $sha = hash_file("sha256", $originalPath);
    if(!file_exists(dirname($modifiedPath))){
        mkdir(dirname($modifiedPath), 0777, true);
    }

    $originalSize = filesize($originalPath);
    $originalArchive->addFile($originalPath, $innerPath);
    if(file_exists($modifiedPath) && isset($cache[$innerPath]) && $cache[$innerPath] === $sha){
        echo "Load from cache: $innerPath...Done\n";
    }else{
        echo "Load from disk: $innerPath...";

        if(str_ends_with($innerPath, ".json")){
            echo "minifying...";

            try{
                $content = file_get_contents($originalPath);
                $minified = json_encode(json_decode(remove_json_comments($content), true, 512, JSON_THROW_ON_ERROR));
                file_put_contents($modifiedPath, $minified);

                echo "Done\n";
            }catch(JsonException $e){
                $failedFiles[$innerPath] = "JSON Parsing failed";
                copy($originalPath, $modifiedPath);
                echo "Failed : " . $e->getMessage() . "\n";
            }
        }elseif(str_ends_with($innerPath, ".png")){
            echo "minifying...";
            exec("oxipng --opt max --strip safe --alpha --quiet \"$originalPath\" --out \"$modifiedPath\"");
            echo "Done\n";
        }else{
            copy($originalPath, $modifiedPath);
            echo "Done\n";
        }
    }

    $minifiedSize = filesize($modifiedPath);
    $minifiedArchive->addFile($modifiedPath, $innerPath);

    if($minifiedSize < $originalSize){
        $minifiedFiles[$innerPath] = [$originalSize, $minifiedSize];
    }
    $cache[$innerPath] = $sha;

    $totalOriginalSize += $originalSize;
    $totalMinifiedSize += $minifiedSize;
}

$originalArchive->close();
$minifiedArchive->close();

// Write minifying results to markdown file
$md = "Update date : " . date("Y-m-d H:i:s") . "\n\n";

// Write summary to markdown file
[$contentOriginalSizeStr, $contentMinifiedSizeStr, $contentSavingRate] = suffixUnitOfSize(
    $totalOriginalSize,
    $totalMinifiedSize
);
[$originalSizeStr, $minifiedSizeStr, $savingRate] = suffixUnitOfSize(
    filesize($originalArchivePath),
    filesize($minifiedArchivePath)
);
$md .= "\n\n";
$md .= "## Summary\n";
$md .= "| Type | Original | Minified | Savings |\n";
$md .= "|:-----|---------:|---------:|--------:|\n";
$md .= "| Content | $contentOriginalSizeStr | $contentMinifiedSizeStr | $contentSavingRate |\n";
$md .= "| Archive | $originalSizeStr | $minifiedSizeStr | $savingRate |\n";

// Write failed files to markdown file
if(count($failedFiles) > 0){
    $md .= "\n\n";
    $md .= "## Failed files\n";
    $md .= "| File | Reason |\n";
    $md .= "|:-----|--------|\n";
    foreach($failedFiles as $file => $reason){
        $md .= "| $file | $reason |\n";
    }
}
$md .= "# Minified files\n\n";
$md .= "| File | Original | Minified | Savings |\n";
$md .= "|:-----|---------:|---------:|--------:|\n";

foreach($minifiedFiles as $file => [$originalSize, $minifiedSize]){
    [$originalSizeStr, $minifiedSizeStr, $savingRate] = suffixUnitOfSize($originalSize, $minifiedSize);
    $md .= "| $file | $originalSizeStr | $minifiedSizeStr | $savingRate |\n";
}

file_put_contents(RELEASE_DIR . "/body.md", $md);
file_put_contents($cacheJson, json_encode($cache, JSON_PRETTY_PRINT));

echo "Done\n";
exit(0);
