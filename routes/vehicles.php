<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Fig\Http\Message\StatusCodeInterface;

$app->get('/vehicle[/{id}]', function (Request $request, Response $response, array $args) {
    $vehicleId = $args['id'] ?? null;

    $jsonData = file_get_contents(__DIR__ . '/vehicle_data.json');
    $vehicles = json_decode($jsonData, true);

    if(!$vehicleId) { // If an ID is not provided, output the entire file
        $response->getBody()->write($jsonData);
        return $response->withStatus(StatusCodeInterface::STATUS_OK)->withHeader('Content-Type', 'application/json');
    } else { // Some form of id was passed
        if(is_numeric($vehicleId)) { // Numeric ID provided
            if(isset($vehicles[$vehicleId])) { // Check if the vehicle exists
                $vehicle = $vehicles[$vehicleId];
                $response->getBody()->write(json_encode($vehicle));
                
                return $response->withStatus(StatusCodeInterface::STATUS_OK)->withHeader('Content-Type', 'application/json');
            }
        } else { // Name of the vehicle provided
            $matchingVehicles = [];

            foreach ($vehicles as $id => $vehicle) {
                if (stripos($vehicle['name'], $vehicleId) !== false) $matchingVehicles[$id] = $vehicle;
            }

            if(!empty($matchingVehicles)) {
                $response->getBody()->write(json_encode($matchingVehicles));
                return $response->withStatus(StatusCodeInterface::STATUS_OK)->withHeader('Content-Type', 'application/json');
            }
        }

        // If we reach here, the vehicle was not found
        return $response->withStatus(StatusCodeInterface::STATUS_NOT_FOUND);
    }
});