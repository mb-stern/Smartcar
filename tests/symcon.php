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
echo "$count official signal examples and integer charge-limit registration passed with SymconStubs. No live Symcon service.\n";
