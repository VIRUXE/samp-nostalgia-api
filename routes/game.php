<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

function getDirectoryContents($dirPath) {
    $contents = [];

    foreach (scandir($dirPath) as $node) {
        if ($node === '.' || $node === '..') continue;

        $nodePath = $dirPath . '/' . $node;
        $fileInfo = new \SplFileInfo($nodePath);

        if (is_dir($nodePath)) {
            $contents[$node] = getDirectoryContents($nodePath);
        } else {
            $contents['files'][$fileInfo->getBasename()] = [
                'size'          => $fileInfo->getSize(),
                'last_modified' => $fileInfo->getMTime()
            ];
        }
    }

    return $contents;
}

$app->get('/game', function (Request $request, Response $response, $args) {
    $dirPath = dirname(__DIR__) . '/gta/';
    
    if (!is_dir($dirPath)) {
        $response->getBody()->write(json_encode(['error' => 'Directory not found.']));
        return $response->withStatus(404);
    }

    $response->getBody()->write(json_encode(getDirectoryContents($dirPath)));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/game/download[/{path:.+}]', function (Request $request, Response $response, $args) {
    $filePath = $args['path'] ?? null;
    
    if (is_null($filePath)) {
        $response->getBody()->write('No file path specified');
        return $response->withStatus(404);
    }

    $realPath = dirname(__DIR__) . '/gta/' . $filePath;
    
    if (!file_exists($realPath) || is_dir($realPath)) return $response->withStatus(404);

    $fileInfo = new \SplFileInfo($realPath);
    $fh = fopen($realPath, 'rb');
    $stream = new \Slim\Psr7\Stream($fh); 

    return $response->withHeader('Content-Type', 'application/octet-stream')
        ->withHeader('Content-Disposition', 'attachment; filename=' . $fileInfo->getBasename())
        ->withBody($stream);
});
