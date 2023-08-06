<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

$app->get('/news', function (Request $request, Response $response, array $args) {
    return $response->withHeader('Location', '/news/pt')->withStatus(302);
});

$app->get('/news/{language}', function (Request $request, Response $response, array $args) use ($db) {
    $language = $args['language'];

    // Check if the language is allowed
    if (!in_array($language, ['pt', 'en'])) {
        $response->getBody()->write(json_encode([
            'error' => 'Invalid language provided. Allowed languages are "pt" and "en".'
        ]));
        
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(400);
    }

    try {
        $titleField   = "title_" . $language;
        $contentField = "content_" . $language;
        $queryParams  = $request->getQueryParams();
        $limit        = isset($queryParams['limit']) ? (int)$queryParams['limit'] : 5;  // Default to 5 if no limit is provided

        $stmt = $db->prepare("SELECT $titleField as title, $contentField as content, published_at FROM news ORDER BY published_at DESC LIMIT $limit");
        
        if ($stmt === false) throw new Exception('Failed to prepare SQL statement');

        $result = $stmt->execute();
        
        if ($result === false) throw new Exception('Failed to execute SQL statement');

        $news = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) $news[] = $row;

        $response->getBody()->write(json_encode($news));

        return $response->withHeader('Content-Type', 'application/json');
    } catch (Exception $e) {
        $response->getBody()->write(json_encode([
            'error' => $e->getMessage()
        ]));
        
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(500);
    }
});
