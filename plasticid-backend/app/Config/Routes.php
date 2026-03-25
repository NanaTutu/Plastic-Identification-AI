<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');
$routes->post('api/predictions', 'Api\Predictions::store');
$routes->get('api/get_keys', 'Api\Predictions::index');
$routes->get('dashboard/api-keys', 'Api\ApiDashboard::keys');
$routes->get('dashboard/api-stats', 'Api\ApiDashboard::stats');