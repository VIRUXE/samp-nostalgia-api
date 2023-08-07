<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Fig\Http\Message\StatusCodeInterface;

$app->post('/login', function (Request $request, Response $response, array $args) use ($db, $gameDb) {
    // if ($request->getHeaderLine('User-Agent') !== 'NostalgiaLauncher') return $response->withStatus(StatusCodeInterface::STATUS_FORBIDDEN);

    // Check if Authorization header is set
    if (isset($request->getHeaders()['Authorization'])) {
        $response->getBody()->write(json_encode(['error' => 'You are already logged in.']));

        return $response->withStatus(StatusCodeInterface::STATUS_FORBIDDEN)->withHeader('Content-Type', 'application/json');
    }

    $loginData = $request->getParsedBody();

    if(!$loginData) return $response->withStatus(StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR);

    if($loginData['version'] != VERSION) return $response->withStatus(StatusCodeInterface::STATUS_VERSION_NOT_SUPPORTED);

    // Get account data    
    $playerStmt = $gameDb->prepare('SELECT ipv4, lastLog, active FROM players WHERE name = :nickname AND pass = :password');
    $playerStmt->bindValue(':nickname', $loginData['nickname']);
    $playerStmt->bindValue(':password', strtoupper(hash('whirlpool', $loginData['password'])));

    $result = $playerStmt->execute();

    if ($result) { // Account load succesful
        $accountData = $result->fetchArray(SQLITE3_ASSOC);
        
        if (!$accountData) return $response->withStatus(StatusCodeInterface::STATUS_UNAUTHORIZED);

        if($accountData['active'] == 0) return $response->withStatus(StatusCodeInterface::STATUS_FORBIDDEN);

        $token = bin2hex(random_bytes(32));

        // Check if there is an existing session for the same player
        $existingSessionStmt = $db->prepare('SELECT token FROM sessions WHERE player_name = :nickname AND logged_out IS NULL');
        $existingSessionStmt->bindValue(':nickname', $loginData['nickname']);

        $existingSessionResult = $existingSessionStmt->execute();

        if ($existingSessionResult) { // Player had a previous unclosed session - close it
            // Set the logged_out time for the existing session to the current timestamp
            $logoutStmt = $db->prepare("UPDATE sessions SET logged_out = strftime('%s', 'now') WHERE token = :existingSessionToken");
            $logoutStmt->bindValue(':existingSessionToken', $existingSessionResult->fetchArray(SQLITE3_ASSOC)['token']);
            $logoutStmt->execute();
        }

        // Store the new token into the database without generating "auth_code" here
        $newSessionStmt = $db->prepare('INSERT INTO sessions (token, player_name, hwid) VALUES (:token, :nickname, :hwid)');
        $newSessionStmt->bindValue(':token', $token);
        $newSessionStmt->bindValue(':nickname', $loginData['nickname']);
        $newSessionStmt->bindValue(':hwid', $loginData['serial']);

        if($newSessionStmt->execute()) {
            $response = $response->withHeader('Authorization', 'Bearer ' . $token);
            
            $response->getBody()->write(json_encode([
                'token'   => $token,
                'ipv4'    => long2ip($accountData['ipv4']),
                'lastLog' => date("H:i:s d-m-Y", $accountData['lastLog'])
            ]));

            return $response->withStatus(StatusCodeInterface::STATUS_OK)->withHeader('Content-Type', 'application/json');
        } else {
            error_log('SQLite error: ' . $db->lastErrorMsg());

            error_log('Nickname: ' . $loginData['nickname']);
            error_log('Token: ' . $token);

            return $response->withStatus(StatusCodeInterface::STATUS_SERVICE_UNAVAILABLE);
        }
    } else
        return $response->withStatus(StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR);
});

$app->post('/logout', function (Request $request, Response $response, array $args) use ($db) {
    $stmt = $db->prepare("UPDATE sessions SET logged_out = strftime('%s', 'now') WHERE token = :token");
    $stmt->bindValue(':token', $request->getAttribute('token'));
    $stmt->execute();

    return $response->withStatus($db->changes() > 0 ? StatusCodeInterface::STATUS_OK : StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR);
})->add($authenticationMiddleware);

$app->post('/ping', function (Request $request, Response $response) use ($db) {
    $stmt = $db->prepare("UPDATE sessions SET last_active = strftime('%s', 'now') WHERE token = :token");
    $stmt->bindValue(':token', $request->getAttribute('token'));
    $stmt->execute();

    return $response->withStatus($db->changes() > 0 ? StatusCodeInterface::STATUS_OK : StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR);
})->add($authenticationMiddleware);

$app->post('/play', function (Request $request, Response $response, array $args) use ($db) {
    $gtaSnapshotPath = '../gta_fs_cache.json';
    $gtaSnapshot     = [];

    // Make sure we have a directory snapshot of our GTA files
    if (!file_exists($gtaSnapshotPath)) {
        function createDirectorySnapshot($dir) {
            $data = [];
        
            foreach (new DirectoryIterator($dir) as $fileInfo) {
                if ($fileInfo->isDot()) continue;
        
                if ($fileInfo->isDir())
                    $data[$fileInfo->getFilename()] = createDirectorySnapshot($fileInfo->getPathname());
                else
                    $data[$fileInfo->getFilename()] = $fileInfo->getSize();
            }
        
            return $data;
        }

        $gtaSnapshot = ["root" => createDirectorySnapshot('../gta')];

        file_put_contents($gtaSnapshotPath, json_encode($gtaSnapshot, JSON_PRETTY_PRINT));
    } else
        $gtaSnapshot = json_decode(file_get_contents($gtaSnapshotPath), true);

    $fileList = $request->getParsedBody();

    if (!$fileList || !is_array($fileList)) return $response->withStatus(StatusCodeInterface::STATUS_BAD_REQUEST);

    function countFilesInSnapshot($snapshot) {
        $count = 0;
        foreach ($snapshot as $key => $value) {
            if (is_array($value)) // Directory
                $count += countFilesInSnapshot($value);
            else // File
                $count++;
        }
        return $count;
    }

    function validateFiles($clientSnapshot, $serverSnapshot, &$badFiles, $currentPath = '') {
        foreach ($clientSnapshot as $key => $value) {
            $filePath = $currentPath ? $currentPath . '/' . $key : $key;
    
            if (is_array($value)) // Directory
                validateFiles($value, $serverSnapshot[$key], $badFiles, $filePath);
            else { // File
                if (!isset($serverSnapshot[$key])) { // File doesn't exist in our snapshot
                    $badFiles['unknown'][] = $filePath;

                    // Check if the file is blacklisted
                    $filenameWithExtension    = basename($key);
                    $filenameWithoutExtension = pathinfo($key, PATHINFO_FILENAME);
                
                    // We check against both the complete filename (with its extension) and just the filename (without extension) in the blacklist
                    foreach ([
                        's0beit.exe',
                        'another_blacklisted_file.extension'
                    ] as $blacklistedFile) {
                        // Extract filename and extension from the blacklisted file
                        $blacklistedFilenameWithExtension    = basename($blacklistedFile);
                        $blacklistedFilenameWithoutExtension = pathinfo($blacklistedFile, PATHINFO_FILENAME);
                        
                        if (
                            $filenameWithExtension == $blacklistedFilenameWithExtension ||
                            $filenameWithoutExtension == $blacklistedFilenameWithoutExtension
                        ) {
                            // Remove from unknown and add to blacklisted
                            array_pop($badFiles['unknown']);
                            $badFiles['blacklisted'][] = $filePath;
                        }
                    }

                    continue;
                }
    
                // Diff file size
                if ($serverSnapshot[$key] != $value) $badFiles['invalid_size'][] = $filePath;
            }
        }
    }

    $clientFileCount = countFilesInSnapshot($fileList);
    $serverFileCount = countFilesInSnapshot($gtaSnapshot);

    if ($clientFileCount !== $serverFileCount) {
        $response->getBody()->write(json_encode([
            'status' => 'error',
            'message' => 'Mismatched file count.'
        ]));

        return $response->withStatus(StatusCodeInterface::STATUS_BAD_REQUEST);    
    }

    $badFiles = [
        'blacklisted'  => [],
        'invalid_size' => [],
        'unknown'      => []
    ];

    // Loop through each file in the payload and check if any are blacklisted of have an invalid size
    validateFiles($fileList, $gtaSnapshot, $badFiles);

    // If there are offending files, send the list back to the client
    if (!empty($badFiles['unknown']) || !empty($badFiles['blacklisted']) || !empty($badFiles['invalid_size'])) {
        $response->getBody()->write(json_encode($badFiles));
        return $response->withStatus(StatusCodeInterface::STATUS_CONFLICT)->withHeader('Content-Type', 'application/json');
    }

    // If everything is fine, generate the "auth_code" and update the session
    $authCode = rand(100000, 999999);

    $stmt = $db->prepare('UPDATE sessions SET auth_code = :auth_code WHERE token = :token');
    $stmt->bindValue(':token', $request->getAttribute('token'));
    $stmt->bindValue(':auth_code', $authCode);

    $result = $stmt->execute();

    $response->getBody()->write(json_encode($result ? ['auth_code' => $authCode] : ['error' => 'Failed to update session.']));
    return $response->withStatus($result ? StatusCodeInterface::STATUS_OK : StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR)->withHeader('Content-Type', 'application/json');
})->add($authenticationMiddleware);