<?php
header('Content-Type: text/plain');
echo "Current directory: " . __DIR__ . "\n\n";

function listFiles($dir, $indent = "")
{
    if (!is_dir($dir)) {
        echo $indent . "Directory not found: $dir\n";
        return;
    }

    $files = scandir($dir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..')
            continue;

        $path = $dir . DIRECTORY_SEPARATOR . $file;
        echo $indent . $file . (is_dir($path) ? "/" : "") . "\n";

        if (is_dir($path)) {
            listFiles($path, $indent . "  ");
        }
    }
}

echo "File Structure:\n";
listFiles(__DIR__);
