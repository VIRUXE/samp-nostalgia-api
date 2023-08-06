<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

function getDirectoryContents($dirPath) {
    $contents = [];
    $files    = [];
    
    foreach (scandir($dirPath) as $node) {
        if ($node === '.' || $node === '..') continue;

        $nodePath = $dirPath . '/' . $node;

        if (is_dir($nodePath))
            $contents[$node] = getDirectoryContents($nodePath);
        else
            $files[] = $node;
    }

    if (!empty($files)) $contents['files'] = $files;

    return $contents;
}

$app->get('/gta', function (Request $request, Response $response, $args) {
    $filePath = $request->getQueryParams()['path'] ?? null;

    if (is_null($filePath)) {
        // If the file path is not set, walk through the 'gta' directory and create a JSON of the folder structure
        $dirPath = dirname(__DIR__) . '/gta/';

        if (!is_dir($dirPath)) {
            $response->getBody()->write(json_encode([ 'error' => 'Directory not found.' ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $response->getBody()->write(json_encode(getDirectoryContents($dirPath)));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // get the real path of the file
    $realPath = dirname(__DIR__) . '/gta/' . $filePath;
    
    // check if the file exists
    if (!file_exists($realPath) || is_dir($realPath)) {
        $response->getBody()->write(json_encode([ 'error' => "File not found: $filePath" ]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
    }

    $fileInfo = new \SplFileInfo($realPath);
    
    $details = [
        'name'          => $fileInfo->getBasename(),
        'size'          => $fileInfo->getSize(),       // in bytes
        'last_modified' => $fileInfo->getMTime()       // Unix timestamp
    ];
    
    $response->getBody()->write(json_encode($details));
    return $response->withHeader('Content-Type', 'application/json');
});