#!/usr/bin/env php
<?php

require 'IMGArchive.php';

function prompt(string $message): bool {
    echo $message . " (y/n) ";
    $response = trim(fgets(STDIN));
    return strtolower($response) === 'y';
}

function getFilePath(string $directory, string $file): string {
    return $directory ? rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $file : $file;
}

function handleAdd(IMGArchive $img, array &$allFiles, string ...$files): void {
    echo "Adding files...\n";
    foreach ($files as $file) {
        if (in_array($file, $allFiles)) {
            echo "Warning: $file is being processed in another operation. Skipping.\n";
            continue;
        }

        if ($img->exists($file)) {
            echo "Warning: $file already exists in the archive. Skipping.\n";
            continue;
        }

        if (!file_exists($file)) {
            echo "Warning: $file does not exist. Skipping.\n";
            continue;
        }

        $fileData = file_get_contents($file);
        if (!$fileData) {
            echo "Warning: Failed to read file data for $file. Skipping.\n";
            continue;
        }

        if (prompt("Add $file?")) {
            $img->add($file, $fileData);
            echo "$file added.\n";
            $allFiles[] = $file;
        }
    }
}

function handleDelete(IMGArchive $img, array &$allFiles, string ...$files): void {
    echo "Deleting files...\n";
    foreach ($files as $file) {
        if (in_array($file, $allFiles)) {
            echo "Warning: $file is being processed in another operation. Skipping.\n";
            continue;
        }

        if (!$img->exists($file)) {
            echo "Warning: $file does not exist in the archive. Skipping.\n";
            continue;
        }

        if (prompt("Delete $file?")) {
            $img->delete($file);
            echo "$file deleted.\n";
            $allFiles[] = $file;
        }
    }
}

function handleReplace(IMGArchive $img, array &$allFiles, string ...$files): void {
    echo "Replacing files...\n";
    foreach ($files as $file) {
        if (in_array($file, $allFiles)) {
            echo "Warning: $file is being processed in another operation. Skipping.\n";
            continue;
        }

        if (!file_exists($file)) {
            echo "Warning: $file to replace with does not exist. Skipping.\n";
            continue;
        }

        if (!$img->exists($file)) {
            echo "Warning: $file to be replaced does not exist in the archive. Skipping.\n";
            continue;
        }

        $fileData = file_get_contents($file);
        if (!$fileData) {
            echo "Warning: Failed to read file data for $file. Skipping.\n";
            continue;
        }

        if (prompt("Replace $file?")) {
            $img->replace($file, $fileData);
            echo "$file replaced.\n";
            $allFiles[] = $file;
        }
    }
}

function main(array $argv): int {
    if (count($argv) < 3) throw new InvalidArgumentException("Usage: img.php <archive_path> <commands>");

    $archivePath = $argv[1];
    if (!file_exists($archivePath)) throw new InvalidArgumentException("Archive file '$archivePath' does not exist.");

    $img = new IMGArchive($archivePath);

    // Store commands and their corresponding handler functions
    $commands = [
        '-a' => 'handleAdd',
        '-d' => 'handleDelete',
        '-r' => 'handleReplace'
    ];

    $allFiles  = [];
    $directory = "";
    for ($i = 2, $len = count($argv); $i < $len; $i++) {
        if ($argv[$i] === '-dir') {
            $i++;
            $directory = $argv[$i];
            continue;
        }

        $cmd = $argv[$i];

        if (isset($commands[$cmd])) {
            $i++;
            $files   = array_map(fn($file) => getFilePath($directory, $file), explode(',', $argv[$i]));
            $handler = $commands[$cmd];
            $handler($img, $allFiles, ...$files);
        } else {
            throw new InvalidArgumentException("Invalid command: $cmd");
        }
    }

    if (prompt("Save changes to the archive?")) {
        $img->save();
        echo "Changes saved.\n";
    } else {
        echo "Changes discarded.\n";
    }

    if (prompt("Do you want to delete the files processed?")) {
        foreach ($allFiles as $file) {
            if (unlink($file)) {
                echo "$file deleted from filesystem.\n";
            } else {
                echo "Failed to delete $file from filesystem.\n";
            }
        }
    }

    return 0;
}

exit(main($argv));
