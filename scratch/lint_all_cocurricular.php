<?php
$dir = new RecursiveDirectoryIterator(__DIR__ . '/../modules/cocurricular');
$it = new RecursiveIteratorIterator($dir);
$err = 0;
$checked = 0;
foreach ($it as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $checked++;
        $cmd = 'C:\\xampp\\php\\php.exe -l "' . $file->getPathname() . '"';
        exec($cmd, $out, $ret);
        if ($ret !== 0) {
            echo "Error in: " . $file->getPathname() . "\n";
            $err++;
        }
    }
}
echo "Checked $checked PHP files in modules/cocurricular. Syntax errors found: $err\n";
