<?php
$dir = __DIR__;
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
$errors = [];
foreach ($files as $file) {
    if ($file->isDir()) continue;
    if ($file->getExtension() !== 'php') continue;
    $path = $file->getRealPath();
    $output = [];
    $ret = 0;
    exec("php -l \"$path\" 2>&1", $output, $ret);
    if ($ret !== 0) {
        $errors[] = implode("\n", $output);
    }
}
if (empty($errors)) {
    echo "NO_SYNTAX_ERRORS";
} else {
    echo implode("\n---\n", $errors);
}
