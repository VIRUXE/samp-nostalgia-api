<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Fig\Http\Message\StatusCodeInterface;

$app->get('/gpci[/{hash}]', function (Request $request, Response $response, array $args) use ($gameDb) {
    $hash = $args['hash'] ?? null;

    if($hash) { // User supplied an hash so we'll retrieve just that
        // Hashes need to be 40 chars in length and have uppercase letters and digits
        if(!preg_match('/^[A-Z0-9]{40}$/', $hash) && strtolower($hash) !== "android") return $response->withStatus(StatusCodeInterface::STATUS_BAD_REQUEST);

        if(strtolower($hash) === "android") $hash = 'ED40ED0E8089CC44C08EE9580F4C8C44EE8EE990';

        $stmt = $gameDb->prepare('SELECT name, date FROM gpci_log WHERE hash = :hash;');
        $stmt->bindParam(':hash', $hash);
        $result = $stmt->execute();

        $players = [];
        while($player = $result->fetchArray(SQLITE3_ASSOC)) $players[$player['name']] = $player['date'];

        if(empty($players)) return $response->withStatus(StatusCodeInterface::STATUS_NOT_FOUND);

        $response->getBody()->write(json_encode($players));
        return $response->withStatus(StatusCodeInterface::STATUS_OK)->withHeader('Content-Type', 'application/json'); 
    }

    // Output all players that have more than one hash registered
    // SQL to fetch hashes associated with more than one player
    $stmt = $gameDb->prepare('
        SELECT hash, name, date
        FROM gpci_log
        WHERE hash IN (
            SELECT hash FROM gpci_log GROUP BY hash HAVING COUNT(hash) > 1
        )'
    );
    $result = $stmt->execute();

    $hashGroups = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) $hashGroups[$row['hash']][$row['name']] = $row['date'];

    $response->getBody()->write(json_encode($hashGroups));
    return $response->withStatus(StatusCodeInterface::STATUS_OK)->withHeader('Content-Type', 'application/json');
});