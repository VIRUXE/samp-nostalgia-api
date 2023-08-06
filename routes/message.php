<?php
/* $app->post('/message', function (Request $request, Response $response, array $args) use ($db, $log) {
    $requestData = $request->getParsedBody();

    $log->info('Message post request received', (array)$requestData);

    if(!$requestData || !isset($requestData['player_name']) || !isset($requestData['message']) || !isset($requestData['from_admin_name'])) return $response->withStatus(400); // 400 Bad Request

    $stmt = $db->prepare('INSERT OR REPLACE INTO messages (player_name, message, from_admin_name, timestamp) VALUES (:player_name, :message, :from_admin_name, :timestamp)');
    $stmt->bindValue(':player_name', $requestData['player_name']);
    $stmt->bindValue(':message', $requestData['message']);
    $stmt->bindValue(':from_admin_name', $requestData['from_admin_name']);
    $stmt->bindValue(':timestamp', time());

    $result = $stmt->execute() 
        ? $response->withStatus(201)  // 201 Created
        : $response->withStatus(500); // 500 Internal Server Error

    return $result;
});

$app->get('/message/{player_name}', function (Request $request, Response $response, array $args) use ($db, $log) {
    $player_name = $args['player_name'];
    
    // Get the IP address of the incoming request
    $ipAddress = $request->getServerParams()['REMOTE_ADDR'];

    // Allow only localhost
    if ($ipAddress !== '127.0.0.1' && $ipAddress !== '::1') return $response->withStatus(401); // Unauthorized
    
    try {
        $stmt = $db->prepare('SELECT * FROM messages WHERE player_name = :player_name COLLATE NOCASE');
        $stmt->bindValue(':player_name', $player_name);

        $result  = $stmt->execute();
        $message = $result->fetchArray(SQLITE3_ASSOC);

        // check if no message was found
        if (!$message) return $response->withStatus(404); // Not Found

        $response->getBody()->write(json_encode($message));
    } catch (Exception $e) {
        $log->error("Error: " . $e->getMessage());
        return $response->withStatus(500); // Internal Server Error
    }
    
    return $response->withHeader('Content-Type', 'application/json');
}); */
