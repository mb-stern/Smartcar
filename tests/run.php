<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}
function invoke(object $object, string $method, mixed ...$arguments): mixed
{
    return (new ReflectionMethod($object, $method))->invoke($object, ...$arguments);
}
function vehicle(): SmartcarVehicle
{
    $v = new SmartcarVehicle();
    $v->Create();
    $v->properties['VehicleID'] = 'vehicle-test';
    $v->properties['UserID'] = 'user-test';
    $v->properties['Permissions'] = '["control_charge","control_security","control_navigation"]';
    invoke($v, 'CreateProfile');
    return $v;
}
function splitter(): SmartcarSplitter
{
    $s = new SmartcarSplitter();
    $s->Create();
    $s->attributes['ApplicationAccessToken'] = 'test-fixture-only';
    $s->attributes['TokenExpiresAt'] = time() + 3600;
    return $s;
}
function signal(SmartcarVehicle $v, string $code, array $body, string $timestamp = '2026-10-10T10:00:00.500Z'): void
{
    $v->ProcessWebhookSignals(json_encode(['vehicleId'=>'vehicle-test', 'data'=>['signals'=>[
        ['code'=>$code, 'status'=>['value'=>'SUCCESS'], 'body'=>$body, 'meta'=>['oemUpdatedAt'=>$timestamp]]
    ]]], JSON_THROW_ON_ERROR));
}
function value(SmartcarVehicle $v, string $ident): mixed
{
    return GetValue($v->GetIDForIdent($ident));
}
function rejects(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (InvalidArgumentException $exception) {
        check(true, $message);
        return;
    }
    check(false, $message);
}
function commandResponse(int $status, string $outcome): array
{
    return ['statusCode'=>$status, 'headers'=>[], 'body'=>json_encode([
        'data'=>['attributes'=>['status'=>['value'=>$outcome]]]
    ])];
}

// Every concrete current V3 signal is exercised using its official body example.
$fixture = json_decode(file_get_contents(__DIR__ . '/fixtures/smartcar-v3.json'), true, 512, JSON_THROW_ON_ERROR);
check(count($fixture['signals']) === 93, 'Current V3 signal inventory');
foreach ($fixture['signals'] as $example) {
    $v = vehicle();
    $definition = invoke($v, 'GetSignalDefinition', $example['code'], $example['body']);
    check(($definition['known'] ?? false) === true, 'Explicit mapping: ' . $example['code']);
    signal($v, $example['code'], $example['body']);
    check(count($v->variables) > 1, 'Official signal example produces data: ' . $example['code']);
    $rows = invoke($v, 'BuildVariableSelectionRows');
    $types = [];
    foreach ($rows as $row) {
        $types[$row['Ident']] = IPS_GetVariable($v->GetIDForIdent($row['Ident']));
    }
    $v->properties['Permissions'] = '[]';
    $v->properties['VariableSelection'] = json_encode(array_map(static fn($row) => array_replace($row, ['Active'=>false]), $rows));
    $v->ApplyChanges();
    $v->attributes['LastSuccessfulSignalPayloads'] = '{}';
    $v->properties['VariableSelection'] = json_encode($rows);
    $v->ApplyChanges();
    $allCreated = true;
    $typesPreserved = true;
    foreach ($types as $ident => $oldVariable) {
        $id = $v->GetIDForIdent($ident);
        $allCreated = $allCreated && $id !== 0;
        $typesPreserved = $typesPreserved && $id !== 0 && IPS_GetVariable($id)['VariableType'] === $oldVariable['VariableType'] && IPS_GetVariable($id)['VariableProfile'] === $oldVariable['VariableProfile'];
    }
    check($allCreated, 'All checkboxes recreate variables without cache: ' . $example['code']);
    check($typesPreserved, 'Reselected signal variables keep their types and profiles: ' . $example['code']);
}

$v = vehicle();
signal($v, 'location-isathome', ['value'=>false]);
check(value($v, 'IsAtHome') === false, 'IsAtHome preserves false');
signal($v, 'charge-wattage', ['value'=>7000, 'unit'=>'watts']);
check(value($v, 'ChargeWattage') === 7.0, 'Watts converted to kW');
signal($v, 'charge-timetocomplete', ['value'=>45.5, 'unit'=>'minutes']);
check(value($v, 'ChargeTimeRemainingMinutes') === 45.5, 'Duration stays decimal minutes');
signal($v, 'charge-ischargingportflapopen', ['value'=>true]);
check(value($v, 'ChargingPortFlap') === 'OPEN', 'Charge port value field');
signal($v, 'charge-ischargingportflapopen', ['value'=>false]);
check(value($v, 'ChargingPortFlap') === 'CLOSED', 'Charge port closed');
signal($v, 'closure-sunroof', ['isOpen'=>true]);
check(value($v, 'Sunroof') === 'OPEN', 'Sunroof still uses isOpen');
signal($v, 'internalcombustionengine-oillife', ['value'=>80, 'unit'=>'percent']);
signal($v, 'diagnostics-oillife', ['status'=>'ALERT', 'description'=>'Service required']);
check(value($v, 'OilLife') === 80.0, 'OilLife measurement preserved');
check(value($v, 'DiagnosticsOilLife') === 'ALERT', 'Separate diagnostic status');
check(value($v, 'DiagnosticsOilLifeDescription') === 'Service required', 'Diagnostic description');

$v = vehicle();
signal($v, 'tractionbattery-stateofcharge', ['value'=>80, 'unit'=>'percent']);
signal($v, 'tractionbattery-stateofcharge', ['value'=>20, 'unit'=>'percent'], '2026-10-10T10:00:00.100Z');
check(value($v, 'BatteryLevel') === 80.0, 'Older value within the same second rejected');
$cache = json_decode($v->attributes['LastSuccessfulSignalPayloads'], true);
check($cache['tractionbattery-stateofcharge']['body']['value'] === 80, 'Older signal does not overwrite cache');
signal($v, 'tractionbattery-stateofcharge', ['value'=>null, 'unit'=>'percent'], '2026-10-10T10:01:00Z');
check(value($v, 'BatteryLevel') === 80.0, 'Null with unit is not fabricated as zero');
signal($v, 'tractionbattery-stateofcharge', ['value'=>0, 'unit'=>'percent'], '2026-10-10T10:00:01Z');
check(value($v, 'BatteryLevel') === 0.0, 'Real zero remains valid; null did not advance timestamp');
signal($v, 'location-preciselocation', ['latitude'=>47.0, 'longitude'=>8.0]);
signal($v, 'location-preciselocation', ['latitude'=>47.5, 'longitude'=>null]);
check(value($v, 'Longitude') === 8.0 && value($v, 'Latitude') === 47.5, 'Null coordinate preserves previous value');
$cache = json_decode($v->attributes['LastSuccessfulSignalPayloads'], true);
check((float)$cache['location-preciselocation']['body']['longitude'] === 8.0, 'Cache retains valid partial coordinates');

$v = vehicle();
signal($v, 'wheel-tires', ['unit'=>'kPa', 'values'=>[['row'=>0, 'column'=>0, 'tirePressure'=>250]]]);
check(value($v, 'TireFrontLeft') === 2.5 && !isset($v->variables['TireBackRight']), 'Missing tires not fabricated');
signal($v, 'wheel-tires', ['unit'=>'kPa', 'values'=>[['row'=>2, 'column'=>0, 'tirePressure'=>300]]]);
check(value($v, 'TireRow2Column0') === 3.0, 'Additional wheel rows');
signal($v, 'wheel-tires', ['unit'=>'kPa', 'values'=>[['row'=>0.0, 'column'=>1.0, 'tirePressure'=>260]]]);
check(value($v, 'TireFrontRight') === 2.6, 'Integer-format numeric wheel positions');
signal($v, 'charge-chargelimits', ['values'=>[['type'=>'GLOBAL', 'limit'=>80]]]);
check(!isset($v->variables['ChargeLimit']) && isset($v->variables['ChargeLimitsValues']), 'Optional activeLimit does not create wrong variable type');
signal($v, 'charge-chargelimits', ['activeLimit'=>80, 'values'=>[], 'unit'=>'percent']);
check(value($v, 'ChargeLimit') === 80.0, 'Later activeLimit remains numeric');
$legacy = vehicle();
$legacy->RegisterVariableString('ChargeLimit', 'Legacy limit', '', 123);
$legacyID = $legacy->GetIDForIdent('ChargeLimit');
$legacy->SetValue('ChargeLimit', '{"values":[]}');
signal($legacy, 'charge-chargelimits', ['activeLimit'=>85,'unit'=>'percent']);
check($legacy->GetIDForIdent('ChargeLimit') === $legacyID && value($legacy,'ChargeLimit') === '{"values":[]}', 'Legacy variable and its archive identity preserved');
check(value($legacy,'ChargeLimitPercent') === 85.0, 'Correct numeric variable for legacy JSON installation');
signal($v, 'future-signal', ['value'=>10]);
signal($v, 'future-signal', ['value'=>10.5]);
check(value($v, 'Sig_future_signal') === 10.5, 'Unknown numeric signal keeps fractional precision');

// Checked variables must exist after saving, even without current data or a cache.
$v = vehicle();
$v->properties['VariableSelection'] = json_encode([
    ['Ident'=>'Longitude', 'Active'=>true, 'Name'=>'Längengrad', 'Kind'=>'Signal', 'Source'=>'location-preciselocation / longitude'],
    ['Ident'=>'Latitude', 'Active'=>false, 'Name'=>'Breitengrad', 'Kind'=>'Signal', 'Source'=>'location-preciselocation / latitude']
], JSON_THROW_ON_ERROR);
$v->ApplyChanges();
check($v->GetIDForIdent('Longitude') !== 0, 'Checking longitude creates it without a payload or cache');
check(IPS_GetVariable($v->GetIDForIdent('Longitude'))['VariableType'] === VARIABLETYPE_FLOAT && IPS_GetVariable($v->GetIDForIdent('Longitude'))['VariableProfile'] === 'SMCAR.LatLon', 'Selected longitude has its numeric type and coordinate profile');
check(value($v, 'Longitude') === 0.0 && !$v->GetIDForIdent('Latitude'), 'Selected missing coordinates get their type default; unchecked coordinates stay absent');
check($v->parentRequests === [], 'Saving variable selection does not require a vehicle API call');
signal($v, 'location-preciselocation', ['latitude'=>47.0, 'longitude'=>8.0]);
$id = $v->GetIDForIdent('Longitude');
$v->attributes['LastSuccessfulSignalPayloads'] = '{}';
$v->ApplyChanges();
check($v->GetIDForIdent('Longitude') === $id && value($v, 'Longitude') === 8.0, 'Saving selection preserves existing IDs and real values');
$v->properties['VariableSelection'] = '[{"Ident":"Longitude","Active":false}]';
$v->ApplyChanges();
check(!$v->GetIDForIdent('Longitude'), 'Unchecking longitude removes it');
$v->properties['VariableSelection'] = '[{"Ident":"Longitude","Active":true}]';
$v->ApplyChanges();
check($v->GetIDForIdent('Longitude') !== 0 && value($v, 'Longitude') === 0.0, 'Rechecking longitude recreates it while cache is empty');

$v = vehicle();
signal($v, 'tractionbattery-nominalcapacity', ['capacity'=>null, 'source'=>null, 'availableCapacities'=>[], 'unit'=>'kWh']);
check(value($v, 'BatteryAvailableCapacities') === '[]', 'Rollback restores empty supplemental list handling');
$v->UnregisterVariable('BatteryAvailableCapacities');
$v->attributes['LastSuccessfulSignalPayloads'] = '{}';
$v->properties['VariableSelection'] = '[{"Ident":"BatteryAvailableCapacities","Active":true}]';
$v->ApplyChanges();
check($v->GetIDForIdent('BatteryAvailableCapacities') !== 0 && value($v, 'BatteryAvailableCapacities') === '', 'Selected empty extra is created even without cache data');

$v = vehicle();
signal($v, 'wheel-tires', ['values'=>[['row'=>2, 'column'=>0, 'tirePressure'=>300]], 'unit'=>'kPa']);
$v->properties['VariableSelection'] = '[{"Ident":"TireRow2Column0","Active":false}]';
$v->ApplyChanges();
$v->attributes['LastSuccessfulSignalPayloads'] = '{}';
$v->properties['VariableSelection'] = '[{"Ident":"TireRow2Column0","Active":true}]';
$v->ApplyChanges();
check(IPS_GetVariable($v->GetIDForIdent('TireRow2Column0'))['VariableType'] === VARIABLETYPE_FLOAT && IPS_GetVariable($v->GetIDForIdent('TireRow2Column0'))['VariableProfile'] === 'SMCAR.Pressure', 'Body-dependent tire selection retains type and profile without cached tire data');

$v = vehicle();
signal($v, 'future-number', ['value'=>10.5]);
$v->properties['VariableSelection'] = '[{"Ident":"Sig_future_number","Active":false}]';
$v->ApplyChanges();
$v->attributes['LastSuccessfulSignalPayloads'] = '{}';
$v->properties['VariableSelection'] = '[{"Ident":"Sig_future_number","Active":true}]';
$v->ApplyChanges();
check(IPS_GetVariable($v->GetIDForIdent('Sig_future_number'))['VariableType'] === VARIABLETYPE_FLOAT, 'Unknown numeric signal retains its type when reselected without data');

$v = vehicle();
$v->RegisterVariableInteger('LegacySignal', 'Mein eigener Name', '', 543);
$v->SetValue('LegacySignal', 42);
$v->properties['VariableSelection'] = '[{"Ident":"LegacySignal","Active":false}]';
$v->ApplyChanges();
check(!$v->GetIDForIdent('LegacySignal'), 'Existing signal can be deselected');
$v->properties['VariableSelection'] = '[{"Ident":"LegacySignal","Active":true}]';
$v->ApplyChanges();
$id = $v->GetIDForIdent('LegacySignal');
check(IPS_GetVariable($id)['VariableType'] === VARIABLETYPE_INTEGER && IPS_GetObject($id)['ObjectName'] === 'Mein eigener Name' && IPS_GetObject($id)['ObjectPosition'] === 543, 'A selected legacy variable is recreated with its saved type, name and position');

// New commands must use the documented verbs, bodies and sc-user-id header.
$calls = [
    'StartCharging'=>[], 'StopCharging'=>[], 'SetChargeLimit'=>[80],
    'OpenChargePort'=>[], 'CloseChargePort'=>[], 'LockDoors'=>[], 'UnlockDoors'=>[],
    'SetNavigationDestination'=>[37.7749,-122.4194],
    'SetDailyChargeSchedule'=>[37.7749,-122.4194,'08:00','10:00'],
    'SetWeeklyChargeSchedule'=>[37.7749,-122.4194,['monday'=>['startTime'=>'08:00','stopTime'=>'10:00'],'wednesday'=>['startTime'=>'08:00','stopTime'=>'10:00'],'friday'=>['startTime'=>'08:00','stopTime'=>'10:00']]],
    'SetWorkweekChargeSchedule'=>[37.7749,-122.4194,['startTime'=>'08:00','stopTime'=>'10:00'],['startTime'=>'10:00','stopTime'=>'12:00']],
    'DeleteChargeSchedule'=>['schedule-test']
];
check(count($fixture['commands']) === 12, 'Current V3 action inventory');
foreach (array_values($calls) as $index=>$arguments) {
    $method = array_keys($calls)[$index];
    $wire = $fixture['commands'][$index];
    $v = vehicle(); $s = splitter();
    $v->parentResponder = fn(string $json): string => $s->ForwardData($json);
    TestTransport::$requests = [];
    TestTransport::$reply = commandResponse(202, 'SUCCESS');
    check($v->$method(...$arguments) === true, 'Command success: ' . $method);
    $request = TestTransport::$requests[0];
    $path = str_replace('{scheduleId}', 'schedule-test', $wire['path']);
    check($request['url'] === 'https://vehicle.api.smartcar.com/v3/vehicles/vehicle-test' . $path && $request['method'] === $wire['method'], 'Command path and verb: ' . $method);
    check(($request['content'] === null ? null : json_decode($request['content'], true)) === $wire['body'], 'Command body: ' . $method);
    check(in_array('sc-user-id: user-test', $request['headers'], true), 'Command user header');
    check($request['timeout'] === 300, 'Separate command timeout');
}

$v = vehicle(); $s = splitter();
$v->parentResponder = fn(string $json): string => $s->ForwardData($json);
foreach ([commandResponse(202, 'FAILURE'), commandResponse(403, 'FAILURE'), ['statusCode'=>202,'headers'=>[],'body'=>'{'], null] as $reply) {
    TestTransport::$reply = $reply; TestTransport::$requests = [];
    check($v->OpenChargePort() === false, 'Command failure or incomplete response is not success');
    check(count(TestTransport::$requests) === 1, 'Failed commands are not automatically retried');
}
TestTransport::$reply = commandResponse(200, 'SUCCESS');
check($v->CloseChargePort() === true, 'HTTP 200 SUCCESS accepted');
$s->properties['CommandTimeout'] = 900;
$v->CloseChargePort();
check(end(TestTransport::$requests)['timeout'] === 900, 'Configured command timeout used');
TestTransport::$reply = ['statusCode'=>200,'headers'=>[],'body'=>'{"data":[]}'];
$s->ApiGetSignals('vehicle-test', 'user-test');
check(end(TestTransport::$requests)['timeout'] === 30, 'Signal reads retain short timeout');
check(invoke($s, 'ExtractStatusCode', ['HTTP/1.1 302 Found','HTTP/1.1 202 Accepted']) === 202, 'Final HTTP status block');

$v = vehicle();
$v->properties['Permissions'] = '[]';
check($v->OpenChargePort() === false && $v->parentRequests === [], 'Missing permission prevents action');
foreach ([49, 101] as $percent) rejects(fn()=>$v->SetChargeLimit($percent), 'Charge limit bounds');
foreach (['24:00','08:60','8:00'] as $time) rejects(fn()=>$v->SetDailyChargeSchedule(47,8,$time,'10:00'), 'HH:MM validation');
rejects(fn()=>$v->SetDailyChargeSchedule(91,8,'08:00','10:00'), 'Latitude bounds');
rejects(fn()=>$v->SetWeeklyChargeSchedule(47,8,[]), 'Weekly days cannot be empty');
rejects(fn()=>$v->SetWeeklyChargeSchedule(47,8,['holiday'=>['startTime'=>'08:00','stopTime'=>'10:00']]), 'Unknown weekday rejected');
rejects(fn()=>$v->SetWorkweekChargeSchedule(47,8,['startTime'=>'08:00'],['startTime'=>'08:00','stopTime'=>'10:00']), 'Incomplete window rejected');
rejects(fn()=>$v->DeleteChargeSchedule(''), 'Empty schedule ID rejected');
check($v->parentRequests === [], 'Invalid inputs produce no API request');
rejects(fn()=>$v->SetDailyChargeSchedule(INF,8,'08:00','10:00'), 'Nonfinite coordinates rejected');
rejects(fn()=>$v->SetWeeklyChargeSchedule(47,8,['monday'=>['startTime'=>'08:00','stopTime'=>'10:00','unsupported'=>true]]), 'Unknown window field rejected');
rejects(fn()=>$v->RequestAction('CommandChargeLimit',80.5), 'Fractional UI charge limit rejected');

$v = vehicle();
invoke($v, 'ApplyVehicleAccessCommands');
check(count(invoke($v, 'GetAvailableCommandDefinitions', ['control_charge'])) === 9, 'All charge actions discoverable');
check(isset($v->variables['CommandChargePortOpen'], $v->variables['CommandChargeScheduleDaily'], $v->variables['CommandChargeScheduleDelete']), 'New UI actions registered');
$id = $v->GetIDForIdent('CommandChargeLimit');
check(IPS_GetVariable($id)['VariableType'] === VARIABLETYPE_INTEGER && IPS_GetVariableProfile('SMCAR.ChargeLimit')['ProfileType'] === VARIABLETYPE_INTEGER, 'Charge limit profile type matches');
$v->SetValue('CommandChargeLimit', 80);
$v->parentResponse = ['success'=>false,'statusCode'=>403];
try { $v->RequestAction('CommandChargeLimit', 90); check(false, 'Failed UI action must report failure'); } catch (RuntimeException $error) { check(value($v,'CommandChargeLimit') === 80, 'Failed UI action preserves value'); }
$v->parentResponse = ['success'=>true,'body'=>['data'=>['attributes'=>['status'=>['value'=>'SUCCESS']]]]];
$v->RequestAction('CommandChargeScheduleDaily','{"location":{"latitude":47,"longitude":8},"startTime":"22:00","stopTime":"06:00"}');
check(end($v->parentRequests)['Body']['data']['attributes']['startTime'] === '22:00', 'Overnight schedule through UI');
$v->RequestAction('CommandChargeScheduleWeekly','{"location":{"latitude":47,"longitude":8},"days":{"sunday":{"startTime":"00:00","stopTime":"23:59"}}}');
check(end($v->parentRequests)['Body']['data']['attributes']['days']['sunday']['stopTime'] === '23:59', 'Weekly schedule through UI');
$v->RequestAction('CommandChargeScheduleWorkweek','{"location":{"latitude":47,"longitude":8},"weekdays":{"startTime":"08:00","stopTime":"10:00"},"weekends":{"startTime":"10:00","stopTime":"12:00"}}');
check(end($v->parentRequests)['Path'] === '/charge-schedules/workweek', 'Workweek schedule through UI');
$v->RequestAction('CommandChargeScheduleDelete','schedule/id ?');
check(end($v->parentRequests)['Path'] === '/charge-schedules/schedule%2Fid%20%3F' && end($v->parentRequests)['Method'] === 'DELETE', 'Deletion ID escaped as one path component');
foreach ([50,100] as $percent) {
    $v->SetChargeLimit($percent);
    check(end($v->parentRequests)['Body']['data']['attributes']['percent'] === $percent, 'Charge limit boundary accepted');
}
rejects(fn()=>$v->RequestAction('CommandChargeScheduleWeekly','{'), 'Invalid UI JSON rejected');
rejects(fn()=>$v->RequestAction('CommandChargeScheduleWorkweek','{"location":{"latitude":47,"longitude":8},"weekdays":{},"weekends":{}}'), 'Incomplete UI workweek rejected');
rejects(fn()=>$v->RequestAction('CommandChargeScheduleDaily','{"location":{"latitude":"oops","longitude":8},"startTime":"08:00","stopTime":"10:00"}'), 'Invalid UI coordinates rejected');

echo "$checks checks passed; 93 official signal examples and 12 command contracts exercised. No live API calls.\n";
