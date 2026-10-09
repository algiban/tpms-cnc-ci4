<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

$routes->setAutoRoute(false);

// ============================================================
// PUBLIC / READ ONLY
// ============================================================
$routes->get('health/live', 'HealthController::live');
$routes->get('health/ready', 'HealthController::ready');
$routes->get('/', 'MonitoringController::index');
$routes->get('monitoring', 'MonitoringController::index');
$routes->get('api/monitoring', 'MonitoringController::data');
$routes->get('api/monitoring/machines/(:num)/history', 'MonitoringController::history/$1');

// ============================================================
// AUTH
// ============================================================
$routes->get('login', 'Auth::index');
$routes->post('login', 'Auth::login', ['filter' => 'csrf']);
$routes->post('logout', 'Auth::logout', ['filter' => ['auth', 'csrf']]);

$routes->get('dashboard', 'Dashboard::index', ['filter' => 'auth']);

// ============================================================
// ADMIN ONLY - USERS
// ============================================================
$routes->get('users', 'Users::index', ['filter' => ['auth', 'admin']]);
$routes->post('users', 'Users::store', ['filter' => ['auth', 'admin', 'csrf']]);
$routes->get('rfid-capture', 'RfidCaptureController::index', ['filter' => ['auth', 'admin']]);
$routes->get('admin/rfid/latest', 'RfidCaptureController::latest', ['filter' => ['auth', 'admin']]);

// ============================================================
// MASTER DATA - READ: authenticated
// ============================================================
$routes->group('master-data', ['filter' => 'auth'], static function (RouteCollection $routes): void {
    $routes->get('/', 'MasterData::machines');
    $routes->get('machines', 'MasterData::machines');
    $routes->get('tpms', 'MasterData::tpms');
    $routes->get('device-assignments', 'MasterData::deviceAssignments');
    $routes->get('customers', 'MasterData::customers');
    $routes->get('materials', 'MasterData::materials');
    $routes->get('parts', 'MasterData::parts');
    $routes->get('tools', 'MasterData::tools');
    $routes->get('employees', 'MasterData::employees');
});

// ============================================================
// MASTER DATA - MUTATION: admin + CSRF
// Jangan mengandalkan tombol UI sebagai authorization boundary.
// ============================================================
$adminWrite = ['filter' => ['auth', 'admin', 'csrf']];

$routes->post('master-data/slots', 'MachineController::storeSlot', $adminWrite);
$routes->post('master-data/machines', 'MachineController::storeMachine', $adminWrite);
$routes->post('master-data/machines/(:num)/update', 'MachineController::update/$1', $adminWrite);
$routes->post('master-data/machines/(:num)/assign', 'MachineController::assign/$1', $adminWrite);
$routes->post('master-data/machines/(:num)/assign-tools', 'MachineToolController::sync/$1', $adminWrite);
$routes->post('master-data/slots/(:num)/dispose-machine', 'MachineController::disposeFromSlot/$1', $adminWrite);

$routes->post('master-data/tpms/scan-connections', 'TpmsController::scanConnections', $adminWrite);

$routes->post('master-data/customers', 'CustomerController::store', $adminWrite);
$routes->post('master-data/customers/(:num)/update', 'CustomerController::update/$1', $adminWrite);
$routes->post('master-data/customers/(:num)/delete', 'CustomerController::delete/$1', $adminWrite);

$routes->post('master-data/materials', 'MaterialController::store', $adminWrite);
$routes->post('master-data/materials/(:num)/update', 'MaterialController::update/$1', $adminWrite);
$routes->post('master-data/materials/(:num)/delete', 'MaterialController::delete/$1', $adminWrite);

$routes->post('master-data/parts', 'PartController::store', $adminWrite);
$routes->post('master-data/parts/(:num)/update', 'PartController::update/$1', $adminWrite);
$routes->post('master-data/parts/(:num)/delete', 'PartController::delete/$1', $adminWrite);


$routes->post('master-data/tools', 'ToolController::store', $adminWrite);
$routes->post('master-data/tools/(:num)/update', 'ToolController::update/$1', $adminWrite);
$routes->post('master-data/tools/(:num)/reset-lifetime', 'ToolController::resetLifetime/$1', $adminWrite);
$routes->post('master-data/tools/(:num)/change-edge', 'ToolController::changeEdge/$1', $adminWrite);
$routes->post('master-data/tools/(:num)/delete', 'ToolController::delete/$1', $adminWrite);

$routes->post('master-data/employees', 'EmployeeController::store', $adminWrite);
$routes->post('master-data/employees/(:num)/update', 'EmployeeController::update/$1', $adminWrite);
$routes->post('master-data/employees/(:num)/delete', 'EmployeeController::delete/$1', $adminWrite);

$routes->post('master-data/import/(:segment)', 'MasterDataTransferController::import/$1', $adminWrite);
$routes->get('master-data/export/(:segment)', 'MasterDataTransferController::export/$1', ['filter' => 'auth']);

// ============================================================
// TPMS DEVICE API - device/API-key authenticated in controllers
// CSRF tidak dipakai untuk machine-to-machine API.
// ============================================================
$routes->group('api/tpms', static function (RouteCollection $routes): void {
    $routes->post('register', 'Api\\TpmsApiController::register');
    $routes->post('heartbeat', 'Api\\TpmsApiController::heartbeat');
    $routes->get('ping', 'Api\\TpmsApiController::ping');
    $routes->post('rfid/scan', 'Api\\RfidApiController::scan');
});

$routes->group('api/tpms/production', static function (RouteCollection $routes): void {
    $routes->post('context', 'Api\\ProductionApiController::dispatch/context');
    $routes->post('start', 'Api\\ProductionApiController::dispatch/start');
    $routes->post('count', 'Api\\ProductionApiController::dispatch/count');
    $routes->post('cycle-start', 'Api\\ProductionApiController::dispatch/cycle-start');
    $routes->post('cycle-stop', 'Api\\ProductionApiController::dispatch/cycle-stop');
    $routes->post('pause', 'Api\\ProductionApiController::dispatch/pause');
    $routes->post('alarm', 'Api\\ProductionApiController::dispatch/alarm');
    $routes->post('stop', 'Api\\ProductionApiController::dispatch/stop');
    $routes->post('finish', 'Api\\ProductionApiController::dispatch/finish');
    $routes->post('state', 'Api\\ProductionApiController::dispatch/state');
    $routes->post('service-complete', 'Api\\ProductionApiController::dispatch/service-complete');
    $routes->post('resume', 'Api\\ProductionApiController::dispatch/resume');
    $routes->post('operator-sick', 'Api\\ProductionApiController::dispatch/operator-sick');
    $routes->post('operator-sick-resolve', 'Api\\ProductionApiController::dispatch/operator-sick-resolve');
    $routes->post('operator-replacement', 'Api\\ProductionApiController::dispatch/operator-replacement');
});

// ============================================================
// PRODUCTION / PLANNING / LOGS
// ============================================================
$routes->get('production', 'ProductionController::index', ['filter' => 'auth']);
$routes->get('production/detail/(:num)', 'ProductionController::detail/$1', ['filter' => 'auth']);
$routes->get('production/export', 'ProductionController::export', ['filter' => 'auth']);
$routes->get('machine-utility', 'MachineUtilityController::index', ['filter' => 'auth']);

$routes->get('planning-employees', 'PlanningEmployeeController::index', ['filter' => 'auth']);
$routes->post(
    'planning-employees/save',
    'PlanningEmployeeController::saveCell',
    ['filter' => ['auth', 'admin', 'csrf']]
);

$routes->group('logs', ['filter' => 'auth'], static function (RouteCollection $routes): void {
    $routes->get('tpms', 'LogController::tpms');
    $routes->get('tools', 'LogController::tools');
    $routes->get('machines', 'LogController::machines');
});

$routes->get('master-data/tool-types', 'ToolTypeController::index', ['filter'=>'auth']);
$routes->post('master-data/tool-types', 'ToolTypeController::save', $adminWrite);
$routes->post('master-data/tool-types/(:num)/update', 'ToolTypeController::save/$1', $adminWrite);

$routes->post('api/tpms/production/parts', 'Api\\ProductionApiController::dispatch/parts');
$routes->post('api/tpms/production/pic', 'Api\\ProductionApiController::dispatch/pic');
$routes->post('api/tpms/production/operator', 'Api\\ProductionApiController::dispatch/operator');
$routes->post('api/tpms/production/setting-start', 'Api\\ProductionApiController::dispatch/setting-start');
$routes->post('api/tpms/production/setting-configure', 'Api\\ProductionApiController::dispatch/setting-configure');
$routes->post('api/tpms/production/setting-finish', 'Api\\ProductionApiController::dispatch/setting-finish');
$routes->post('api/tpms/production/change-edge', 'Api\\ProductionApiController::dispatch/change-edge');
$routes->post('api/tpms/production/reset-tool', 'Api\\ProductionApiController::dispatch/reset-tool');

$routes->get('reports/cycle-time','CycleTimeReportController::index',['filter'=>'auth']);
$routes->get('reports/cycle-time/detail','CycleTimeReportController::detail',['filter'=>'auth']);
$routes->get('reports/cycle-time/export','CycleTimeReportController::export',['filter'=>'auth']);
