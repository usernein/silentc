<?php 
function glob_recursive($pattern, $flags = 0, $startdir = '') {
	$files = glob($startdir.$pattern, $flags);
	foreach (glob($startdir.'*', GLOB_ONLYDIR|GLOB_NOSORT|GLOB_MARK) as $dir) {
		$files = array_merge($files, glob_recursive($pattern, $flags, $dir));
	}
	sort($files);
	return $files;
}
function folderToZip($folder, &$zipFile, $exclusiveLength, array $except = []) {
	$files = glob_recursive($folder.'/*');
	$except = array_merge($except, ['..', '.']);
	foreach ($files as $filePath) {
		if (in_array(basename($filePath), $except)) continue;
		// Remove prefix from file path before add to zip. 
		$localPath = substr($filePath, $exclusiveLength); 
		if (is_file($filePath)) {
			$zipFile->addFile($filePath, $localPath);
		} else if (is_dir($filePath)) {
			// Add sub-directory. 
			$zipFile->addEmptyDir($localPath); 
			folderToZip($filePath, $zipFile, $exclusiveLength, $except);
		}
	}
}

function zipDir($sourcePath, $outZipPath, array $except = []) {
	@unlink($outZipPath);
	$zip = new ZipArchive(); 
	$zip->open($outZipPath, ZIPARCHIVE::CREATE);
	folderToZip($sourcePath, $zip, strlen($sourcePath), $except); 
	$zip->close();
}

function unzipDir($zipPath, $outDirPath) {
	if (@file_exists($outDirPath) != true) {
		mkdir($outDirPath, 077, true);
	}
	$zip = new ZipArchive();
	if ($zip->open($zipPath)) {
		$zip->extractTo($outDirPath);
	}
	$zip->close();
}