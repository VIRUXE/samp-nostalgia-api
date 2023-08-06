<?php
require_once 'IMGArchive.php';

$archive = new IMGArchive("C:\Games\GTA San Andreas\models\gta3.img");

echo "File count: " . $archive->numberOfEntries . PHP_EOL;

$i = 0;
foreach ($archive->entries as $entry) {
    if ($i++ >= 10) break;
    echo "Name: " . $entry->name . PHP_EOL;
    echo "Type: " . $entry->type . PHP_EOL;
    echo "Size: " . $entry->size . " bytes" . PHP_EOL;
}

$firstFileName = array_key_first($archive->entries);
$archive->extractToFile($firstFileName, 'output', function ($fileName, $outputDir) {
    echo "The file '$fileName' has been extracted to '$outputDir'." . PHP_EOL;
});
