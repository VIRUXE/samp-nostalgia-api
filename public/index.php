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

    error_log("Token: $token");

    $sessionData = $sessionStmt->execute()->fetchArray(SQLITE3_ASSOC);

    // If token is invalid or expired
    if (!$sessionData) {
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(json_encode(['error' => 'Invalid or expired token']));
        return $response->withStatus(StatusCodeInterface::STATUS_UNPROCESSABLE_ENTITY);
    }

    // Check if the last_active has elapsed more than one minute
    $lastActiveTime = new \DateTime('@' . (int)$sessionData['last_active']);
    $oneMinuteAgo   = (new \DateTime())->sub(new \DateInterval('PT1M'));

    if ($lastActiveTime < $oneMinuteAgo) {
        // Update the logged_out field to close the session
        $updateStmt = $db->prepare('UPDATE sessions SET logged_out = CURRENT_TIMESTAMP WHERE token = :token');
        $updateStmt->bindValue(':token', $token);
        $updateStmt->execute();

        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(json_encode(['error' => 'Last active session is more than a minute old']));
        return $response->withStatus(StatusCodeInterface::STATUS_EXPECTATION_FAILED);
    }

    // Get the admin level if any
    $playerStmt = $gameDb->prepare('SELECT level FROM Admins WHERE name = :name;');
    $playerStmt->bindValue(':name', $sessionData['name']);

    $playerData = $playerStmt->execute()->fetchArray(SQLITE3_ASSOC);

    $request = $request->withAttribute('player_name', $sessionData['name'])
                        ->withAttribute('player_level', $playerData['level'] ?? null);

    // Pass the request to the next middleware
    return $handler->handle($request);
};

$app->get('/', function (Request $request, Response $response, $args) {
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

// Load up route files
foreach (glob(__DIR__ . '/../routes/*.php') as $routeFile) require $routeFile;

$app->run();