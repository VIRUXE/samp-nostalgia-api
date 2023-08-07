<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Fig\Http\Message\StatusCodeInterface;

$app->get('/hwid', function (Request $request, Response $response) use ($db) {
    $params = $request->getQueryParams();
    $hwid   = isset($params['hwid']) ? $params['hwid'] : null;

    if (!$hwid) return $response->withStatus(StatusCodeInterface::STATUS_BAD_REQUEST);

    $stmt = $db->prepare("SELECT banned_at, reason FROM banned_hwids WHERE hwid = :hwid");
    $stmt->bindValue(':hwid', $hwid);
    $result = $stmt->execute();

    $banData = $result->fetchArray(SQLITE3_ASSOC);

    if($banData) {
        $response->getBody()->write(json_encode($banData));
        return $response->withStatus(StatusCodeInterface::STATUS_FORBIDDEN);
    } else
        return $response->withStatus(StatusCodeInterface::STATUS_NOT_FOUND);
});