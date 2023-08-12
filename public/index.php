<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Fig\Http\Message\StatusCodeInterface;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

define('VERSION', '1.0.0.0');

$app = AppFactory::create();

global $gameDb;
$gameDb = new SQLite3('/home/samp/servidor/scriptfiles/data/accounts.db', SQLITE3_OPEN_READONLY);

global $db;
$db = new SQLite3(__DIR__ . '/../database.db');

$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$app->addErrorMiddleware(true, true, true);

$app->add(function (Request $request, RequestHandler $handler): Response {
    $uri = $request->getUri();
    $path = $uri->getPath();
    if ($path != '/' && substr($path, -1) == '/') {
        // Remove the trailing slash
        $uri = $uri->withPath(substr($path, 0, -1));
        
        // Use a 301 redirect to redirect to the non-trailing slash URL
        $response = new \Slim\Psr7\Response();
        return $response->withHeader('Location', (string)$uri)->withStatus(301);
    }
    
    return $handler->handle($request);
});

$userAgentMiddleware = function (Request $request, RequestHandler $handler): Response {
    if($request->getHeaderLine('User-Agent') !== 'NostalgiaLauncher') return (new Slim\Psr7\Response)->withStatus(StatusCodeInterface::STATUS_FORBIDDEN);

    return $handler->handle($request);
};

// Middleware that makes sure the Auth Token is set
$authenticationMiddleware = function (Request $request, RequestHandler $handler) use ($db, $gameDb) {
    $authHeader = $request->getHeaderLine('Authorization');
    
    if (!$authHeader) {
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(json_encode(['error' => 'No token provided']));
        return $response->withStatus(StatusCodeInterface::STATUS_UNAUTHORIZED);
    }

    if(!str_contains($authHeader, "Bearer")) {
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(json_encode(['error' => 'Stop being silly.']));
        return $response->withStatus(StatusCodeInterface::STATUS_EXPECTATION_FAILED);
    }

    // Check if the token is valid and get the player_name and last_active
    $token = str_replace('Bearer ', '', $authHeader);

    $sessionStmt = $db->prepare('SELECT player_name as name, last_active FROM sessions WHERE token = :token AND logged_out IS NULL;');
    $sessionStmt->bindValue(':token', $token);

    $sessionData = $sessionStmt->execute()->fetchArray(SQLITE3_ASSOC);

    // If token is invalid or expired
    if (!$sessionData) {
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(json_encode(['error' => 'Invalid or expired token']));
        return $response->withStatus(StatusCodeInterface::STATUS_UNPROCESSABLE_ENTITY);
    }

    $currentDateTime = new \DateTime();                                        // Represents the time of the request
    $lastActiveTime  = new \DateTime('@' . (int)$sessionData['last_active']);  // Create DateTime from Unix timestamp
    
    // Difference between current time and the last active time
    $interval = $currentDateTime->diff($lastActiveTime);
    
    // Check if the difference is more than one minute
    /* if ($interval->i >= 1 || $interval->h > 0 || $interval->d > 0 || $interval->y > 0) {
        // Update the logged_out field to close the session
        $logoutStmt = $db->prepare("UPDATE sessions SET logged_out = strftime('%s', 'now') WHERE token = :token");
        $logoutStmt->bindValue(':token', $token);
        $logoutStmt->execute();
    
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(json_encode(['error' => 'Last active session is more than a minute old']));
        return $response->withStatus(StatusCodeInterface::STATUS_EXPECTATION_FAILED);
    } */

    // Get the admin level if any
    $playerLevelStmt = $gameDb->prepare('SELECT level FROM Admins WHERE name = :name;');
    $playerLevelStmt->bindValue(':name', $sessionData['name']);

    // Passed all checks, now update the last_active field for the session
    $updateActiveStmt = $db->prepare("UPDATE sessions SET last_active = strftime('%s', 'now') WHERE token = :token");
    $updateActiveStmt->bindValue(':token', $token);
    $updateActiveStmt->execute();

    $request = $request->withAttribute('token', $token)
                        ->withAttribute('player_name', $sessionData['name'])
                         ->withAttribute('player_level', $playerLevelStmt->execute()->fetchArray(SQLITE3_ASSOC)['level'] ?? null);

    // Pass the request to the next middleware
    return $handler->handle($request);
};

$app->get('/', function (Request $request, Response $response, $args) {
    $lang = getPreferredLanguage($request->getHeaderLine('Accept-Language'));

    $translations = [
        'en' => [
            'title'       => 'Download Nostalgia Launcher',
            'description' => 'This download is for the Nostalgia Launcher which is a part of Scavenge Nostalgia.<br>The launcher serves as a game manager and anti-cheat.<br>It can install the entire game and/or SA-MP. Stops most hacks and manage IMG archives, allowing for server-specific GTA files to be handled within those archives.',
            'download'    => 'Download'
        ],
        'pt' => [
            'title'       => 'Baixar o Nostalgia Launcher',
            'description' => 'Este download é para o Nostalgia Launcher, que é parte do servidor Scavenge Nostalgia.<br>O lançador funciona como um gerenciador de jogo e anti-cheat.<br>Ele consegue instalar o jogo inteiro e/ou o SA-MP. Impede hacks e gerencia arquivos IMG, permitindo que arquivos específicos (objetos para itens e etc) do servidor sejam gerenciados dentro desses arquivos.',
            'download'    => 'Baixar'
        ]
    ];

    $currentTranslations = $translations[$lang];

    $html = "
        <!DOCTYPE html>
        <html lang=\"$lang\">
        <head>
            <meta charset=\"UTF-8\">
            <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
            <title>Download Nostalgia</title>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    height: 100vh;
                    background-color: #f2f2f2;
                }
                
                .container {
                    text-align: center;
                    opacity: 0;
                    transition: opacity 1.5s;
                }
            </style>
            <script>
                window.onload = function() {
                    document.querySelector(\".container\").style.opacity = \"1\";
                };
            </script>
        </head>
        <body>
            <div class=\"container\">
                <h2>{$currentTranslations['title']}</h2>
                <p>{$currentTranslations['description']}</p>
                <a href=\"/download\" class=\"btn\">{$currentTranslations['download']}</a>
            </div>
        </body>
        </html>
    ";

    $response->getBody()->write($html);
    return $response->withHeader('Content-Type', 'text/html');
});

$app->get('/download', function (Request $request, Response $response, $args) {
    $file = __DIR__ . '/nostalgia.zip';
    
    if (!file_exists($file)) throw new HttpNotFoundException($request);

    $response->getBody()->write(file_get_contents($file));

    return $response
        ->withHeader('Content-Type', 'application/octet-stream')
        ->withHeader('Content-Disposition', 'attachment; filename="' . basename($file) . '"');
});

// Manifest specially to update the windows app
$app->get('/manifest', function (Request $request, Response $response, array $args) {
    $baseUrl = str_replace('manifest', '', (string)$request->getUri());

    // Extract major, minor, patch, and additional version components
    $versionComponents = explode('.', VERSION);
    $additional = null;
    
    if (count($versionComponents) > 3) $additional = implode('.', array_slice($versionComponents, 3));

    $manifest = [
        'version' => [
            'major'      => (int)$versionComponents[0],
            'minor'      => (int)$versionComponents[1],
            'patch'      => (int)$versionComponents[2],
            'additional' => $additional
        ],
        'release_notes' => 'Updated anti-cheat algorithms. Fixed minor bugs.',
        'hash'          => sha1_file(__DIR__ . '/nostalgia.zip'),
        'url'           => $baseUrl
    ];

    $response->getBody()->write(json_encode($manifest));

    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(StatusCodeInterface::STATUS_OK);
});

$app->get('/serverlog[/{lineCount}]', function (Request $request, Response $response, array $args): Response {
    $logPath = '/home/samp/servidor/server_log.txt';

    if (!file_exists($logPath)) return $response->withStatus(404)->withHeader('Content-Type', 'text/plain')->getBody()->write('Log file not found.');

    $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    // If a line count is provided (either by default or in the path), get the last 'n' lines.
    $lineCount = $args['lineCount'] ?? 50;
    $lines     = array_slice($lines, -$lineCount);
    $params    = $request->getQueryParams();

    $groupedLogs = [];
    $currentTimestamp = '';
    foreach ($lines as $line) {
        if (preg_match('/\[\d{2}:\d{2}:\d{2}\]/', $line, $matches)) {
            $currentTimestamp = $matches[0];
        }

        // If there's no timestamp at the start of the line, it's treated as a continuation of the previous log entry.
        if ($currentTimestamp) {
            $groupedLogs[$currentTimestamp][] = $line;
        }
    }

    // Check for timestamp in query parameters
    $sinceTimestamp = $params['since'];

    if ($sinceTimestamp) {
        $groupedLogs = array_filter($groupedLogs, function($key) use ($sinceTimestamp) {
            return strcmp($key, "[$sinceTimestamp]") > 0;
        }, ARRAY_FILTER_USE_KEY);
    } 

    // Flatten the grouped logs back into an array of lines
    $lines = [];
    foreach ($groupedLogs as $timestamp => $group) {
        $lines = array_merge($lines, $group);
    }
    
    $redactedLines = array_map(function($line) {
        $line = preg_replace('/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', '[REDACTED]', $line);
        $line = preg_replace('/-?\d+\.\d+\s*,?\s*-?\d+\.\d+\s*,?\s*-?\d+\.\d+/', '[COORDS-REDACTED]', $line);
        $line = preg_replace('/(\[DEFFAIL\].*code\s*)\d+/', '${1}[CODE-REDACTED]', $line);
        return $line;
    }, $lines);

    if ($params['format'] === 'raw') {
        // Return just the redacted logs joined by a newline for raw format
        $response->getBody()->write(join("\n", $redactedLines));
        return $response->withHeader('Content-Type', 'text/plain');
    }

    function tagToColor($tag) {
        if (!ctype_upper($tag)) return '000000';
        $hash = crc32($tag);
        $color = dechex($hash & 0xffffff);
        while (strlen($color) < 6) $color = '0' . $color;
        return $color;
    }

    $styledOutput = '<style>
        body { 
            font-family: Verdana, sans-serif; 
            font-size: 12px;
            color: #E0E0E0;
            background-color: black;
            margin: 0;
        }
        .log-entry { 
            display: flex; 
            align-items: center; 
            padding: 6px 0;
            transition: background-color 0.3s;
        }
        .log-entry:nth-child(even) {
            background-color: #121212;
        }
        .log-entry:nth-child(odd) {
            background-color: #181818; 
        }
        .log-entry:hover {
            background-color: #222222;
        }
        .tag { 
            width: 16px; 
            height: 16px; 
            border-radius: 50%; 
            margin: 0 10px; 
            display: inline-block;
        }
    </style>';

    foreach ($redactedLines as $line) {
        preg_match('/\[\d{2}:\d{2}:\d{2}\]\s+\[(.*?)\]/', $line, $matches);
        $tag   = $matches[1] ?? '';
        $color = tagToColor($tag);

        $styledOutput .= "<div class=\"log-entry\">
                            <div class=\"tag\" style=\"background-color: #$color\"></div>
                            $line
                          </div>";
    }

    // Add JS to scroll to the bottom
    $styledOutput .= '<script>
    const loginAlertSound = new Audio(\'join.wav\');
    
    function displayNotification(message) {
        if (Notification.permission !== \'granted\') {
            Notification.requestPermission();
        } else {
            const notification = new Notification(\'Scavenge Nostalgia\', {
                icon: \'favicon.png\',
                body: message,
            });
    
            // Optionally, add an onclick event handler
            notification.onclick = function () {
                window.focus();
            };
        }
    }

    // When you receive a log update...
    function handleLogUpdate(logLine) {
        // Check if the log line indicates a player login
        if (logLine.includes(\'[ACCOUNTS]\') && logLine.includes(\'logou.\')) {
            loginAlertSound.play();
            
            const playerName = logLine.split(\'[ACCOUNTS]\')[1].split(\'(\')[0].trim();
            displayNotification(`${playerName} entrou no servidor!`);
        }
    }

    function crc32(str) {
        var table = new Array(256);
        for (var i = 0; i < 256; i++) {
            var c = i;
            for (var j = 0; j < 8; j++) {
                c = ((c & 1) ? (0xEDB88320 ^ (c >>> 1)) : (c >>> 1));
            }
            table[i] = c;
        }
    
        var crc = 0 ^ (-1);
    
        for (var i = 0; i < str.length; i++) {
            crc = (crc >>> 8) ^ table[(crc ^ str.charCodeAt(i)) & 0xFF];
        }
    
        return (crc ^ (-1)) >>> 0;
    }
    
    function tagToColor(tag) {
        if (!tag.match(/^[A-Z]+$/)) return \'000000\';
    
        let hash = crc32(tag);
        let color = (hash & 0xffffff).toString(16); // Convert to hex
    
        while (color.length < 6) color = \'0\' + color;
    
        return color;
    }
    
    function fetchLatestLogs() {
        let lastEntry = document.querySelector(".log-entry:last-of-type").textContent.match(/\[\d{2}:\d{2}:\d{2}\]/);

        let lastTimestamp = null;
        
        if(!lastEntry) {
            console.log("Didnt find timestamp");
            setTimeout(fetchLatestLogs, 5000);
            return;
        } else
            lastTimestamp = lastEntry[0];

        lastTimestamp = lastTimestamp.replace(/\[|\]/g, \'\');

        const queryURL = `/serverlog?since=${lastTimestamp}&format=raw`;

        fetch(queryURL)
        .then(response => response.text())
        .then(data => {
            if (data) {
                const lines = data.split("\n");

                lines.forEach(line => {
                    const match = line.match(/\[\d{2}:\d{2}:\d{2}\]\s+\[(.*?)\]/);

                    if(match) handleLogUpdate(line);

                    const tag = match ? match[1] : \'\';
                    const color = tagToColor(tag);

                    const logEntry = document.createElement(\'div\');
                    logEntry.className = \'log-entry\';

                    const tagDiv = document.createElement(\'div\');
                    tagDiv.className = \'tag\';
                    tagDiv.style.backgroundColor = `#${color}`;
                    logEntry.appendChild(tagDiv);

                    logEntry.appendChild(document.createTextNode(line));
                    document.body.appendChild(logEntry);
                });
                
                window.scrollTo(0, document.body.scrollHeight);
            }
        })
        .finally(() => {
            setTimeout(fetchLatestLogs, 5000); // Poll every 5 seconds
        });
    }

    window.onload = function() { 
        setTimeout(fetchLatestLogs, 5000); // Start polling 5 seconds after initial load

        setTimeout(function() {
            window.scrollTo(0, document.body.scrollHeight);
        }, 100); 
    }</script>';

    $response->getBody()->write($styledOutput);
    return $response->withHeader('Content-Type', 'text/html');
});

function getPreferredLanguage($header) {
    foreach (explode(',', $header) as $lang) {
        $langParts = explode(';', $lang);
        $code      = strtolower(trim($langParts[0]));

        if (strpos($code, 'pt-br') === 0 || strpos($code, 'pt-pt') === 0) return 'pt';
    }
    
    return 'en'; // default
}

// Load up route files
foreach (glob(__DIR__ . '/../routes/*.php') as $routeFile) require $routeFile;

$app->run();