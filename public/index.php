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
    $uri  = $request->getUri();
    $path = $uri->getPath();

    if ($path != '/' && substr($path, -1) == '/') {
        $uri = $uri->withPath(substr($path, 0, -1)); // Remove the trailing slash
        
        // Use a 301 redirect to redirect to the non-trailing slash URL
        $response = new \Slim\Psr7\Response();
        return $response->withHeader('Location', (string)$uri)->withStatus(StatusCodeInterface::STATUS_MOVED_PERMANENTLY);
    }
    
    return $handler->handle($request);
});

$userAgentMiddleware = function (Request $request, RequestHandler $handler): Response {
    if($request->getHeaderLine('User-Agent') !== 'NostalgiaLauncher') return (new Slim\Psr7\Response)->withStatus(StatusCodeInterface::STATUS_FORBIDDEN);

    return $handler->handle($request);
};

// Middleware that makes sure the Auth Token is set
$authenticationMiddleware = function(Request $request, RequestHandler $handler) use ($db, $gameDb) {
    $authHeader = $request->getHeaderLine('Authorization');
    
    if(!$authHeader) {
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
    if ($interval->i >= 1 || $interval->h > 0 || $interval->d > 0 || $interval->y > 0) {
        // Update the logged_out field to close the session
        $logoutStmt = $db->prepare("UPDATE sessions SET logged_out = strftime('%s', 'now') WHERE token = :token");
        $logoutStmt->bindValue(':token', $token);
        $logoutStmt->execute();
    
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(json_encode(['error' => 'Last active session is more than a minute old']));
        return $response->withStatus(StatusCodeInterface::STATUS_EXPECTATION_FAILED);
    }

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
                <a href=\"/launcher\" class=\"btn\">{$currentTranslations['download']}</a>
            </div>
        </body>
        </html>
    ";

    $response->getBody()->write($html);
    return $response->withHeader('Content-Type', 'text/html');
});

$app->get('/launcher', function (Request $request, Response $response, $args) {
    $file = __DIR__ . '/nostalgia.exe';
    
    if (!file_exists($file)) throw new HttpNotFoundException($request);

    $fileSize = filesize($file);
    $response->getBody()->write(file_get_contents($file));

    return $response
        ->withHeader('Content-Type', 'application/octet-stream')
        ->withHeader('Content-Disposition', 'attachment; filename="' . basename($file) . '"')
        ->withHeader('Content-Length', $fileSize);
});

// Manifest specially to update the windows app
$app->get('/launcher/manifest', function (Request $request, Response $response, array $args) {
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
        'hash'          => sha1_file(__DIR__ . '/nostalgia.exe'),
        'url'           => (string)$request->getUri()->withPath("/launcher")
    ];

    $response->getBody()->write(json_encode($manifest));

    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(StatusCodeInterface::STATUS_OK);
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