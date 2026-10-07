<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');
$routes->options('api/predictions', static fn () => response());
$routes->post('api/predictions', 'Api\Predictions::store');
$routes->get('api/get_keys', 'Api\Predictions::index');
$routes->get('dashboard/api-keys', 'Api\ApiDashboard::keys');
$routes->get('dashboard/api-stats', 'Api\ApiDashboard::stats');

// Portal
$routes->get('portal', 'Portal::index');
$routes->get('portal/methodology', 'Portal::methodology');
$routes->get('portal/docs', 'Portal::docs');
$routes->get('portal/playground', 'Portal::playground');
$routes->get('portal/request', 'Portal::request_key');
$routes->options('portal/request', static fn () => response());
$routes->options('portal/predict', static fn () => response());
$routes->post('portal/request', 'Portal::submit_request', ['filter' => 'csrf']);
$routes->post('portal/predict', 'Portal::predict', ['filter' => 'csrf']);