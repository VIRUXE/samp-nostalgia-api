<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Fig\Http\Message\StatusCodeInterface;

$app->post('/player/login', function (Request $request, Response $response, array $args) use ($db, $gameDb) {
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
    $playerStmt = $gameDb->prepare('SELECT ipv4, alive, regDate, lastLog, spawnTime, totalSpawns, warnings, joinSentence, clan, vip, kills, deaths, aliveTime, coins, active FROM players WHERE name = :nickname AND pass = :password');
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

        // Check if we're being given a correct serial, just in case
        if(!isValidHWID($loginData['serial'])) return $response->withStatus(StatusCodeInterface::STATUS_BAD_REQUEST);

        // Store the new token into the database without generating "auth_code" here
        $newSessionStmt = $db->prepare('INSERT INTO sessions (token, player_name, hwid, gpci) VALUES (:token, :nickname, :hwid, :gpci)');
        $newSessionStmt->bindValue(':token', $token);
        $newSessionStmt->bindValue(':nickname', $loginData['nickname']);
        $newSessionStmt->bindValue(':hwid', $loginData['serial']);
        $newSessionStmt->bindValue(':gpci', $loginData['gpci']);

        if($newSessionStmt->execute()) {
            $response = $response->withHeader('Authorization', 'Bearer ' . $token);
            
            $response->getBody()->write(json_encode([
                'token'        => $token,
                'lastIp'       => long2ip($accountData['ipv4']),
                'alive'        => (string)$accountData['alive'],
                'regDate'      => date("H:i:s d-m-Y", $accountData['regDate']),
                'lastLog'      => date("H:i:s d-m-Y", $accountData['lastLog']),
                'spawnTime'    => date("H:i:s d-m-Y", $accountData['spawnTime']),
                'totalSpawns'  => (string)$accountData['totalSpawns'],
                'warnings'     => (string)$accountData['warnings'],
                'joinSentence' => $accountData['joinSentence'],
                'clan'         => $accountData['clan'],
                'vip'          => (string)$accountData['vip'],
                'kills'        => (string)$accountData['kills'],
                'deaths'       => (string)$accountData['deaths'],
                'aliveTime'    => (string)$accountData['aliveTime'],
                'coins'        => (string)$accountData['coins'],
                'active'       => (string)$accountData['active']
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

$app->post('/player/logout', function (Request $request, Response $response, array $args) use ($db) {
    $stmt = $db->prepare("UPDATE sessions SET logged_out = strftime('%s', 'now') WHERE token = :token");
    $stmt->bindValue(':token', $request->getAttribute('token'));
    $stmt->execute();

    return $response->withStatus($db->changes() > 0 ? StatusCodeInterface::STATUS_OK : StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR);
})->add($authenticationMiddleware);

$app->post('/player/ping', function (Request $request, Response $response) use ($db) {
    if($request->getAttribute('player_level')) return $response->withStatus(StatusCodeInterface::STATUS_OK); // Ignore admins

    $pingData = $request->getParsedBody();

    // We need what we need
    if (!isset($pingData['windows']) || !isset($pingData['modules'])) return $response->withHeader('Content-Type', 'application/json')->withStatus(StatusCodeInterface::STATUS_BAD_REQUEST);

    return $response->withStatus(StatusCodeInterface::STATUS_OK);
})->add($authenticationMiddleware);

$app->post('/player/play', function (Request $request, Response $response, array $args) use ($db) {
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
        $response->getBody()->write(json_encode([ 'status'  => 'error', 'message' => 'Mismatched file count.' ]));

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


$app->get('/player/profile/{name}[/{column}]', function (Request $request, Response $response, array $args) {
    $column        = $args['column'] ?? null;
    $humanReadable = isset($request->getQueryParams()['human']);

    $playerAccount = getPlayerAccount($args['name'], $column ? [$column] : null, $humanReadable);

    if(!$playerAccount) return $response->withStatus(StatusCodeInterface::STATUS_NOT_FOUND);

    if($bans = getPlayerBans($args['name'], $humanReadable)) $playerAccount['bans'] = $bans;

    $response->getBody()->write(json_encode($playerAccount));
    return $response->withStatus(StatusCodeInterface::STATUS_OK)->withHeader('Content-Type', 'application/json');
});

$app->get('/player/aliases/{name}[/{type}]', function (Request $request, Response $response, array $args) use ($gameDb) {
    $name = $args['name'];
    $type = $args['type'] ?? 'all';

    // Step 1: Fetch player details
    $stmt = $gameDb->prepare('SELECT ipv4, pass, gpci FROM players WHERE name = :name COLLATE NOCASE');
    $stmt->bindValue(':name', $name);
    $result = $stmt->execute();

    $playerDetails = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$playerDetails) return $response->withStatus(StatusCodeInterface::STATUS_NOT_FOUND);

    // Step 2: Use player details to query aliases based on type
    $query = 'SELECT name FROM players WHERE name != :name COLLATE NOCASE AND ';
    $queryParams = [':name' => $name];

    switch ($type) {
        case 'ip':
            $query .= 'ipv4 = :ipv4';
            $queryParams[':ipv4'] = $playerDetails['ipv4'];
            break;
        case 'password':
            $query .= 'pass = :pass';
            $queryParams[':pass'] = $playerDetails['pass'];
            break;
        case 'gpci':
            $query .= 'gpci = :gpci';
            $queryParams[':gpci'] = $playerDetails['gpci'];
            break;
        default:
            $query .= '(pass = :pass OR ipv4 = :ipv4 OR gpci = :gpci)';
            $queryParams[':ipv4'] = $playerDetails['ipv4'];
            $queryParams[':pass'] = $playerDetails['pass'];
            $queryParams[':gpci'] = $playerDetails['gpci'];
            break;
    }

    $aliasesStmt = $gameDb->prepare($query);
    foreach ($queryParams as $param => $value) $aliasesStmt->bindValue($param, $value);
    
    $result = $aliasesStmt->execute();

    $aliases = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $alias = $row['name'];

        $humanReadable = isset($request->getQueryParams()['human']);

        $banData     = getPlayerBans($alias, $humanReadable);
        $accountData = getPlayerAccount($alias, null, $humanReadable);
    
        // If ban data exists, add it
        if ($banData) $aliases[$alias]['bans'] = $banData;
    
        // Merge account data after the potential 'ban' key
        if ($accountData) $aliases[$alias] = array_merge($aliases[$alias] ?? [], $accountData);
    }

    $response->getBody()->write(json_encode($aliases, JSON_INVALID_UTF8_IGNORE));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/bans', function (Request $request, Response $response, array $args) use ($gameDb) {
    $humanReadable = isset($request->getQueryParams()['human']);

    $result = $gameDb->query('SELECT name, date, reason, by, duration, active FROM Bans;');

    $banData = [];

    while($ban = $result->fetchArray(SQLITE3_ASSOC)) {
        $name = $ban['name'];
        unset($ban['name']);

        if(empty(preg_replace('/[[:^print:]]/', '', $ban['reason']))) unset($ban['reason']);

        if($humanReadable) {
            $ban['active'] = $ban['active'] ? true : false;
            
            foreach(['date', 'duration'] as $column) $ban[$column] = $ban[$column] ? date('H:i:s d-m-Y', $ban[$column]) : null;
        }

        $banData[$name] = $ban;
    }

    $response->getBody()->write(json_encode($banData));
    return $response->withStatus(StatusCodeInterface::STATUS_OK)->withHeader('Content-Type', 'application/json');
});

$app->get('/admins', function (Request $request, Response $response, array $args) use ($gameDb) {
    $playerAccountStmt = $gameDb->prepare('SELECT * FROM Admins ORDER BY level DESC');

    $result = $playerAccountStmt->execute();

    if(!$result) return $response->withStatus(StatusCodeInterface::STATUS_NOT_FOUND);

    $humanReadable = isset($request->getQueryParams()['human']);

    $admins = [];
    while($admin = $result->fetchArray(SQLITE3_ASSOC)) {
        if($humanReadable) 
            $admin['level'] = $admin['level'] = ['AJUDANTE', 'MODERADOR', 'ADMINISTRADOR', 'LIDER ADMIN', 'DEV'][$admin['level'] - 1] ?? 'UNKNOWN';

        // $admin['account'] = getPlayerAccount($admin['name'], null, $humanReadable);
        $admins[$admin['name']]['level'] = $admin['level'];

        // Pode ter admin mas a conta pode nao existir...
        $account = getPlayerAccount($admin['name'], ['language', 'lastLog'], $humanReadable);

        if($account) $admins[$admin['name']] = array_merge($admins[$admin['name']], $account);
    }

    $response->getBody()->write(json_encode($admins));
    return $response->withStatus(StatusCodeInterface::STATUS_OK)->withHeader('Content-Type', 'application/json');
});

function isValidHWID($hwid) {
    return preg_match('/^[a-fA-F0-9]{64}$/', $hwid) === 1;
}

function getPlayerBans(string $name, bool $humanReadable = false): bool|array {
    global $gameDb;

    // Fetch both active and inactive bans
    $banStmt = $gameDb->prepare('SELECT date, reason, by, duration, active FROM Bans WHERE name = :name COLLATE NOCASE ORDER BY date DESC;');
    $banStmt->bindValue(':name', $name);

    $result = $banStmt->execute();

    if (!$result) return false;

    $activeBan    = null;
    $inactiveBans = [];

    while ($ban = $result->fetchArray(SQLITE3_ASSOC)) {
        // Remove 'active' key as we don't need it anymore
        $isActive = (bool) $ban['active'];
        unset($ban['active']);

        $ban['reason'] = preg_replace('/[[:^print:]]/', '', $ban['reason']);
        if (empty($ban['reason'])) unset($ban['reason']);

        if($humanReadable) foreach(['date', 'duration'] as $column) $ban[$column] = $ban[$column] ? date('H:i:s d-m-Y', $ban[$column]) : null;

        if ($isActive)
            $activeBan = $ban;
        else
            $inactiveBans[] = $ban;
    }

    $bans = [];
    if ($activeBan !== null) $bans = $activeBan;  // As there is ever only one active ban, get the first one

    if (!empty($inactiveBans)) $bans['inactive_bans'] = $inactiveBans;

    return empty($bans) ? false : $bans;
}

function getPlayerAccount(string $name, ?array $columns = null, bool $humanReadable = false): bool|array {
    global $gameDb;

    $allowedColumns  = [
        'language',
        'alive',
        'regDate',
        'lastLog',
        'spawnTime',
        'totalSpawns',
        'warnings',
        'joinSentence',
        'clan',
        'vip',
        'kills',
        'deaths',
        'aliveTime',
        'active'
    ];
    $selectedColumns = '';

    if ($columns) {
        // Filter the user-provided columns against the allowed list
        $filteredColumns = array_filter($columns, function($column) use ($allowedColumns) {
            return in_array($column, $allowedColumns);
        });

        if (!empty($filteredColumns)) // Output only the valid columns found
            $selectedColumns = implode(', ', $filteredColumns);
        else // No valid columns so just output everything
            $selectedColumns = implode(', ', $allowedColumns);
    } else
        $selectedColumns = implode(', ', $allowedColumns);

    $playerAccountStmt = $gameDb->prepare("SELECT $selectedColumns FROM players WHERE name = :name;");
    $playerAccountStmt->bindValue(':name', $name);

    $result = $playerAccountStmt->execute();

    if($result) {
        $playerAccount = $result->fetchArray(SQLITE3_ASSOC);

        foreach($playerAccount as $column => $value) if(empty($value)) unset($playerAccount[$column]);
        
        if ($humanReadable) {
            if(isset($playerAccount['language'])) $playerAccount['language'] = $playerAccount['language'] ? 'EN' : 'PT';

            if(isset($playerAccount['vip'])) $playerAccount['vip'] = ['COPPER', 'SILVER', 'GOLD'][$playerAccount['vip'] - 1] ?? 'UNKNOWN';

            // Booleans
            foreach(['alive', 'active'] as $boolColumn) {
                if(isset($playerAccount[$boolColumn]))
                    $playerAccount[$boolColumn] = $playerAccount[$boolColumn] ? true : false;
            }

            // Timestamps
            foreach (['regDate', 'lastLog', 'spawnTime'] as $dateColumn) {
                if (isset($playerAccount[$dateColumn]))
                    $playerAccount[$dateColumn] = date('H:i:s d-m-Y', $playerAccount[$dateColumn]);
            }

            // Time-only
            if(isset($playerAccount['aliveTime'])) $playerAccount['aliveTime'] = date('H:i:s', $playerAccount['aliveTime']);
        }

        return $playerAccount;
    }

    return false;
}