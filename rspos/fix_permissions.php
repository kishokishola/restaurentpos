<?php
/**
 * Run this ONCE to fix file permissions.
 * Visit: http://localhost/RestaurantPOS/fix_permissions.php
 * Delete this file after running.
 */

$dir = __DIR__;
$count = 0;
$errors = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if ($file->isFile()) {
        $path = $file->getPathname();
        // Make readable and writable by everyone
        if (@chmod($path, 0755)) {
            $count++;
        } else {
            $errors[] = $path;
        }
    }
}

// Also fix directories
foreach ($iterator as $file) {
    if ($file->isDir()) {
        @chmod($file->getPathname(), 0755);
    }
}
@chmod($dir, 0755);

echo "<h2>Permissions Fixed</h2>";
echo "<p style='color:green'>Fixed: $count files</p>";
if (!empty($errors)) {
    echo "<p style='color:red'>Could not fix: " . count($errors) . " files</p>";
    foreach ($errors as $e) echo "<small>$e</small><br>";
}
echo "<p><strong>Now visit:</strong> <a href='/RestaurantPOS/dashboard.php'>/RestaurantPOS/dashboard.php</a></p>";
echo "<p style='color:red'><strong>Delete this file after use!</strong></p>";

// List all PHP files found
echo "<h3>Files found:</h3><pre>";
$iter2 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
foreach ($iter2 as $f) {
    if (pathinfo($f, PATHINFO_EXTENSION) === 'php') {
        echo $f->getPathname() . " - " . (is_readable($f->getPathname()) ? 'READABLE' : 'NOT READABLE') . "\n";
    }
}
echo "</pre>";
?>
