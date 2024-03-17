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

/** Remove comments of JSON content */
function remove_json_comments($jsonContent) : string{
	return preg_replace(
		'~(" (?:[^"\\\\]++|\\\\.)*+ ") | \# [^\r\n]*+ | // [^\r\n]*+ | /\* .*? \*/~sx',
		'$1',
		$jsonContent
	);
}

/** Load JSON data from file, with comments removed */
function file_get_json(string $path) : mixed{
	$content = file_get_contents($path);
	$content = remove_json_comments($content);
	return json_decode($content, true);
}

/** Save JSON content to file */
function file_put_json(string $filename, mixed $json) : void{
	file_put_contents($filename, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

/** Create directory safely, creating directories if not exists */
function safe_path_join(string ...$paths) : string{
	$path = implode("/", $paths);
	if(!file_exists($path)){
		mkdir($path, 0777, true);
	}
	return $path;
}

/** Create a new image with the same size as the input image, and apply the alpha rounding based on the limit */
function imagealphafilter(GdImage $image, int $limit = 0x5f) : GdImage{
	$width = imagesx($image);
	$height = imagesy($image);

	$newImage = imagecreatetruecolor($width, $height);
	imagealphablending($newImage, false);
	imagesavealpha($newImage, true);
	for($x = 0; $x < $width; $x++){
		for($y = 0; $y < $height; $y++){
			$color = imagecolorat($image, $x, $y);
			$rgba = imagecolorsforindex($image, $color);

			// apply the alpha rounding based on the limit
			$alpha = $rgba['alpha'] > $limit ? 127 : 0;
			imagesetpixel($newImage, $x, $y,
				imagecolorallocatealpha($newImage, $rgba['red'], $rgba['green'], $rgba['blue'], $alpha)
			);
		}
	}

	return $newImage;
}

/** Find the first non-transparent position from the image */
function imagefirstcolorat(GdImage $image, int $start, int $end, int $step, int $length, bool $isVertial) : int{
	for(; $start !== $end; $start += $step){
		for($i = 0; $i < $length; $i++){
			$color = $isVertial ? imagecolorat($image, $start, $i) : imagecolorat($image, $i, $start);
			if($color >> 24 < 0x6F){
				break 2;
			}
		}
	}
	return $start;
}


$baseDir = $argv[1] ?? ".";
$default = $argv[2] ?? "default_tools";
define("WORK_DIR", clear_path(realpath(getcwd() . "/$baseDir")));

/**
 * Load template files...
 */
const GEOMETRY = "geometry";
const GEOMETRY_PREFIX = "/models/entity/";
const GEOMETRY_SUFFIX = ".geo.json";
const GEOMETRY_PATH = GEOMETRY_PREFIX . "%s" . GEOMETRY_SUFFIX;

const ATTACHABLE = "attachable";
const ATTACHABLE_PREFIX = "/attachables/";
const ATTACHABLE_SUFFIX = ".attachable.json";
const ATTACHABLE_PATH = ATTACHABLE_PREFIX . "%s" . ATTACHABLE_SUFFIX;

const TEXTURE = "item texture";
const TEXTURE_PREFIX = "/textures/tools/";
const TEXTURE_SUFFIX = ".png";
const TEXTURE_PATH = TEXTURE_PREFIX . "%s" . TEXTURE_SUFFIX;

const ICON = "item icon";
const ICON_PREFIX = "/textures/items/";
const ICON_SUFFIX = ".icon.png";
const ICON_PATH = ICON_PREFIX . "%s" . TEXTURE_SUFFIX;

const BEHAVIOR_PART = "behavior part";
const BEHAVIOR_PREFIX = "/.behavior/";
const BEHAVIOR_SUFFIX = ".json";
const BEHAVIOR_PART_PATH = BEHAVIOR_PREFIX . "%s" . BEHAVIOR_SUFFIX;

const ITEM_TEXTURE_PATH = "/textures/item_texture.json";

$defaults = [
	GEOMETRY => WORK_DIR . sprintf(GEOMETRY_PATH, $default),
	ATTACHABLE => WORK_DIR . sprintf(ATTACHABLE_PATH, $default),
	TEXTURE => WORK_DIR . ITEM_TEXTURE_PATH,
];
echo "┌ LOAD DEFAULT FILES ────────────────────────────────────\n";
echo "│\n";

foreach($defaults as $template => $templatePath){
	if(file_exists($templatePath)){
		echo "├──── found : $template ($templatePath)\n";
	}else{
		echo "├──── missing : $template ($templatePath)\n";
		echo "└──────────────────────────────────────────────────────────\n\n";
		exit(1);
	}
}
echo "└──────────────────────────────────────────────────────────\n\n";


/**
 * Load animation filles...
 */
const ANIMATION_MAP = [
	"setup" => "animation.%s.idle",
	"normal" => "animation.%s.normal",
	"hold_first_person" => "animation.%s.hold_first_person",
	"hold_third_person" => "animation.%s.hold_third_person"
];

$animationsDir = WORK_DIR . "/animations";
$animations = [];
echo "┌ LOAD ANIMATIONS FILES ────────────────────────────────────\n";
echo "│\n";
echo "├── read animation files from $animationsDir\n";
if(file_exists($animationsDir) && is_dir($animationsDir)){
	foreach(scandir_recursive($animationsDir) as $innerPath){
		$animationPath = $animationsDir . $innerPath;
		try{
			$animation = file_get_json($animationPath);
			$animations = array_merge($animations, $animation["animations"] ?? []);
		}catch(Exception){
		}
	}
}

$templateDir = WORK_DIR . "/.template";
echo "├── read animation files from $templateDir\n";
if(file_exists($templateDir) && is_dir($templateDir)){
	foreach(scandir_recursive($templateDir) as $innerPath){
		if(str_ends_with($innerPath, ".animation.json") || str_ends_with($innerPath, ".ani.json")){
			$animationPath = $templateDir . $innerPath;
			try{
				$animation = file_get_json($animationPath);
				$animations = array_merge($animations, $animation["animations"] ?? []);
				copy($animationPath, $animationsDir . $innerPath);
			}catch(Exception){
			}
		}
	}
}
foreach($animations as $animationName => $_){
	echo "├──── found : $animationName\n";
}

echo "│\n";
echo "├── check default animations...\n";
foreach(ANIMATION_MAP as $key){
	echo "├──── ";
	if(isset($animations[sprintf($key, $default)])){
		echo "found";
	}else{
		echo "missing";
	}

	echo " : " . sprintf($key, $default) . "\n";
}
echo "└──────────────────────────────────────────────────────────\n\n";


/**
 * Load template files...
 */
$templateDir = WORK_DIR . "/.template";
echo "┌ PROCESS TEMPLATE FILES ────────────────────────────────────\n";
echo "│\n";
if(!file_exists($templateDir) || !is_dir($templateDir)){
	echo "├── missing template directory : $templateDir\n";
	echo "└──────────────────────────────────────────────────────────\n\n";
	exit(1);
}

$templates = [];
safe_path_join(WORK_DIR . GEOMETRY_PREFIX);
safe_path_join(WORK_DIR . ATTACHABLE_PREFIX);
safe_path_join(WORK_DIR . TEXTURE_PREFIX);
safe_path_join(WORK_DIR . ICON_PREFIX);
safe_path_join(WORK_DIR . BEHAVIOR_PREFIX);

function processResource(string $type, string $input, string $output, Closure $closure) : void{
	if(!file_exists($input)){
		echo "├────── $type\t missing : $input\n";
		return;
	}

	try{
		$overwrite = file_exists($output);
		$closure($input, $output);
		if($overwrite){
			echo "├────── $type\t overwrited : $output\n";
		}else{
			echo "├────── $type\t created : $output\n";
		}
	}catch(Exception){
		echo "├────── $type\t failed : $output\n";
	}
}

$templateMap = [];
$meterialMap = [];
foreach(scandir_recursive($templateDir) as $innerPath){
	if(!str_ends_with($innerPath, GEOMETRY_SUFFIX)){
		continue;
	}

	$name = str_replace([GEOMETRY_SUFFIX, "/", "\\"], "", $innerPath);
	echo "│\n";
	echo "├──── $name\n";

	processResource(
		GEOMETRY,
		$templateDir . "/" . $name . GEOMETRY_SUFFIX,
		sprintf(WORK_DIR . GEOMETRY_PATH, $name),
		static function($input, $output) use ($default, $defaults, &$meterialMap, $name){
			$base = file_get_json($defaults[GEOMETRY]);
			$geometry = file_get_json($input);

			$meterialMap[$name] = $geometry["meterial"] ?? "entity_alphatest";
			$base["minecraft:geometry"][0]["description"] = $geometry["minecraft:geometry"][0]["description"];
			foreach($geometry["minecraft:geometry"][0]["bones"] as $bone){
				if(!isset($bone["parent"])){
					$bone["parent"] = $default;
				}
				$base["minecraft:geometry"][0]["bones"][] = $bone;
			}

			file_put_json($output, $base);
		}
	);

	processResource(
		TEXTURE,
		$templateDir . "/" . $name . TEXTURE_SUFFIX,
		sprintf(WORK_DIR . TEXTURE_PATH, $name),
		copy(...)
	);

	processResource(
		ICON,
		$templateDir . "/" . $name . ICON_SUFFIX,
		sprintf(WORK_DIR . ICON_PATH, $name),
		static function($input, $output) use ($defaults, $name){
			$source = imagecreatefrompng($input);
			$transparent = imagecolorallocatealpha($source, 0, 0, 0, 127);

			// Rotate the image by 45 degrees
			$rotated = imagealphafilter(imagerotate($source, 45, $transparent));

			// Find the first non-transparent positions from the image
			$width = imagesx($rotated);
			$height = imagesy($rotated);
			$left = imagefirstcolorat($rotated, 0, $width - 1, 1, $height, true);
			$right = imagefirstcolorat($rotated, $width - 1, -1, -1, $height, true);
			$top = imagefirstcolorat($rotated, 0, $height - 1, 1, $width, false);
			$bottom = imagefirstcolorat($rotated, $height - 1, -1, -1, $width, false);

			// Adjust the size and positions so that the image is the same aspect length
			$croppedWidth = $right - $left + 1;
			$croppedHeight = $bottom - $top + 1;
			if($croppedWidth > $croppedHeight){
				$top -= intdiv($croppedWidth - $croppedHeight, 2);
				$croppedHeight = $croppedWidth;
			}else{
				$left -= intdiv($croppedHeight - $croppedWidth, 2);
				$croppedWidth = $croppedHeight;
			}

			// Crop the image
			$cropped = imagecreatetruecolor($croppedWidth, $croppedHeight);
			imagealphablending($cropped, false);
			imagesavealpha($cropped, true);
			imagefill($cropped, 0, 0, $transparent);
			imagecopy($cropped, $rotated, 0, 0, $left, $top, $croppedWidth, $croppedHeight);

			// Resize the image to 32x32
			$pad = 2; // 2px padding
			$resized = imagecreatetruecolor(32, 32);
			imagealphablending($cropped, false);
			imagesavealpha($resized, true);
			imagefill($resized, 0, 0, $transparent);
			$srcWidth = imagesx($cropped);
			$srcHeight = imagesy($cropped);
			$dstSize = 32 - $pad * 2;
			imagecopyresampled($resized, $cropped, $pad, $pad, 0, 0, $dstSize, $dstSize, $srcWidth, $srcHeight);

			// Update the item_texture.json
			$textureData = file_get_json($defaults[TEXTURE]);
			$textureData["texture_data"][$name]["textures"] = "textures/items/" . $name;
			file_put_json($defaults[TEXTURE], $textureData);

			// Save the result image
			imagepng(imagealphafilter($resized), $output);
		}
	);

	processResource(
		ATTACHABLE,
		$defaults[ATTACHABLE],
		sprintf(WORK_DIR . ATTACHABLE_PATH, $name),
		static function($input, $output) use ($animations, $meterialMap, $default, $name){
			$attachable = file_get_json($input);
			$attachableIdentifer = &$attachable["minecraft:attachable"]["description"]["identifier"];
			$attachableIdentifer = str_replace($default, $name, $attachableIdentifer);
			$attachableTextures = &$attachable["minecraft:attachable"]["description"]["textures"]["default"];
			$attachableTextures = str_replace($default, $name, $attachableTextures);
			$attachable["minecraft:attachable"]["description"]["materials"]["default"] = $meterialMap[$name];
			$attachableGeometry = &$attachable["minecraft:attachable"]["description"]["geometry"]["default"];
			$attachableGeometry = str_replace($default, $name, $attachableGeometry);
			$attachableAnimations = &$attachable["minecraft:attachable"]["description"]["animations"];
			foreach(ANIMATION_MAP as $k => $v){
				$animationName = sprintf($v, $name);
				if(isset($animations[$animationName])){
					$attachableAnimations[$k] = $animationName;
				}
			}

			file_put_json($output, $attachable);
		}
	);

	processResource(
		BEHAVIOR_PART,
		$templateDir . "/.behavior.json",
		sprintf(WORK_DIR . BEHAVIOR_PART_PATH, $name),
		static function($input, $output) use ($default, $name){
			$behavior = file_get_contents($input);
			$behavior = str_replace($default, $name, $behavior);
			file_put_contents($output, $behavior);
		}
	);
}
echo "└──────────────────────────────────────────────────────────\n\n";

echo "done.\n";
exit(0);