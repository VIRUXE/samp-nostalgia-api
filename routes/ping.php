<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Fig\Http\Message\StatusCodeInterface;

$app->post('/ping', function (Request $request, Response $response) use ($db) {
    if($request->getAttribute('player_level')) return $response->withStatus(StatusCodeInterface::STATUS_OK); // Ignore admins

    $pingData = $response->getBody();

    // We need what we need
    if (!isset($pingData['windows']) || !isset($pingData['modules'])) return $response->withHeader('Content-Type', 'application/json')->withStatus(StatusCodeInterface::STATUS_BAD_REQUEST);

    return $response->withStatus(StatusCodeInterface::STATUS_OK);
})->add($authenticationMiddleware);