<?php
declare(strict_types=1);

// Optional verification with the official symcon/SymconStubs dependency.
$stubDirectory = getenv('SYMCON_STUBS');
if (!$stubDirectory || !is_file($stubDirectory . '/autoload.php')) {
    fwrite(STDERR, "Set SYMCON_STUBS to a checkout of https://github.com/symcon/SymconStubs.\n");
    exit(2);
}
require $stubDirectory . '/autoload.php';
require dirname(__DIR__) . '/SmartcarVehicle/module.php';
IPS\Kernel::reset();
foreach (['~UnixTimestamp'=>VARIABLETYPE_INTEGER, '~Switch'=>VARIABLETYPE_BOOLEAN, '~Lock'=>VARIABLETYPE_BOOLEAN, '~Temperature'=>VARIABLETYPE_FLOAT] as $name=>$type) {
    IPS\ProfileManager::createVariableProfile($name, $type);
}
class SymconTestVehicle extends SmartcarVehicle
{
    // The official doubles require an explicit test clock.
    protected function getTime(): int { return 1791626400; }
}
function invoke(object $object, string $method, mixed ...$arguments): mixed
{
    return (new ReflectionMethod($object, $method))->invoke($object, ...$arguments);
}
$fixture = json_decode(file_get_contents(__DIR__ . '/fixtures/smartcar-v3.json'), true, 512, JSON_THROW_ON_ERROR);
$count = 0;
foreach ($fixture['signals'] as $example) {
    $instanceID = IPS\ObjectManager::registerObject(1);
    $v = new SymconTestVehicle($instanceID);
    $v->Create();
    invoke($v, 'CreateProfile');
    $v->ProcessWebhookSignals(json_encode(['data'=>['signals'=>[
        ['code'=>$example['code'], 'body'=>$example['body'], 'status'=>['value'=>'SUCCESS']]
    ]]], JSON_THROW_ON_ERROR));
    if (count(IPS_GetChildrenIDs($instanceID)) < 2) {
        throw new RuntimeException('No signal data for ' . $example['code']);
    }
    $count++;
}
$v = new SymconTestVehicle(IPS\ObjectManager::registerObject(1));
$v->Create();
invoke($v, 'CreateProfile');
$definition = invoke($v, 'GetCommandDefinition', 'charge-set-limit');
invoke($v, 'RegisterOrUpdateTypedVariable', $definition['ident'], $definition['name'], 80, $definition['type'], $definition['profile']);
$id = invoke($v, 'GetIDForIdent', 'CommandChargeLimit');
if (IPS_GetVariable($id)['VariableType'] !== VARIABLETYPE_INTEGER || IPS_GetVariableProfile($definition['profile'])['ProfileType'] !== VARIABLETYPE_INTEGER || GetValue($id) !== 80) {
    throw new RuntimeException('Charge limit profile registration failed');
}
IPS_SetName($id, 'Mein Ladelimit');
IPS_SetPosition($id, 321);
invoke($v, 'RegisterVariableInteger', $definition['ident'], $definition['name'], $definition['profile']);
if (invoke($v, 'GetIDForIdent', 'CommandChargeLimit') !== $id || IPS_GetObject($id)['ObjectName'] !== 'Mein Ladelimit' || IPS_GetObject($id)['ObjectPosition'] !== 321 || GetValue($id) !== 80) {
    throw new RuntimeException('Profile update changed the existing variable identity or user configuration');
}
$instanceID = IPS\ObjectManager::registerObject(1);
IPS\InstanceManager::createInstance($instanceID, [
    'Class'=>SymconTestVehicle::class,
    'ModuleID'=>'{1E1B7C9A-2D4F-4E8A-9C3B-7F6D5A4E2B10}',
    'ModuleName'=>'SmartcarVehicle',
    'ModuleType'=>3
]);
$v = IPS\InstanceManager::getInstanceInterface($instanceID);
$v->SetProperty('VehicleID', 'vehicle-test');
$v->SetProperty('VariableSelection', '[{"Ident":"Longitude","Active":true,"Name":"Längengrad","Kind":"Signal","Source":"location-preciselocation / longitude"}]');
$v->ApplyChanges();
$id = invoke($v, 'GetIDForIdent', 'Longitude');
$variable = IPS_GetVariable($id);
if ($variable['VariableType'] !== VARIABLETYPE_FLOAT || ($variable['VariableCustomProfile'] ?: $variable['VariableProfile']) !== 'SMCAR.LatLon' || GetValue($id) !== 0.0) {
    throw new RuntimeException('Selected longitude was not created with its numeric profile without data');
}
IPS_SetName($id, 'Mein Längengrad');
IPS_SetPosition($id, 321);
SetValue($id, 8.5);
$v->ApplyChanges();
if (invoke($v, 'GetIDForIdent', 'Longitude') !== $id || GetValue($id) !== 8.5 || IPS_GetObject($id)['ObjectName'] !== 'Mein Längengrad') {
    throw new RuntimeException('Saving checkbox selection changed an existing value or name');
}
$v->SetProperty('VariableSelection', '[{"Ident":"Longitude","Active":false}]');
$v->ApplyChanges();
$v->SetProperty('VariableSelection', '[{"Ident":"Longitude","Active":true}]');
$v->ApplyChanges();
$id = invoke($v, 'GetIDForIdent', 'Longitude');
if (IPS_GetVariable($id)['VariableType'] !== VARIABLETYPE_FLOAT || IPS_GetObject($id)['ObjectName'] !== 'Mein Längengrad' || IPS_GetObject($id)['ObjectPosition'] !== 321) {
    throw new RuntimeException('Rechecking longitude did not restore its type and user configuration without cache data');
}
echo "$count official signal examples, integer charge-limit registration and checkbox selection passed with SymconStubs. No live Symcon service.\n";
