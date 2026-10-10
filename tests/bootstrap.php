<?php
declare(strict_types=1);
// Minimal Symcon doubles for deterministic regressions, not a Symcon runtime.
// HTTP transport is replaced below; no live vehicle action or token request is possible.
const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_INTEGER = 1;
const VARIABLETYPE_FLOAT = 2;
const VARIABLETYPE_STRING = 3;
$objects = []; $instances = []; $deliveries = []; $nextObject = 10000;
function IPS_GetInstance(int $id): array { global $instances; return $instances[$id] ?? ['ConnectionID'=>1]; }
function IPS_GetInstanceListByModuleID(string $id): array { global $instances; return array_keys($instances); }
function IPS_GetProperty(int $id, string $key): mixed { global $instances; return $instances[$id][$key] ?? ''; }
function IPS_GetVariable(int $id): array { global $objects; return $objects[$id]; }
function IPS_GetObject(int $id): array { global $objects; return $objects[$id]; }
function IPS_GetChildrenIDs(int $id): array { global $objects; return array_keys(array_filter($objects, static fn($object) => ($object['ParentID'] ?? 0) === $id)); }
function IPS_VariableExists(int $id): bool { global $objects; return isset($objects[$id]); }
function GetValue(int $id): mixed { global $objects; return $objects[$id]['value']; }
function IPS_SetName(int $id, string $name): void { global $objects; $objects[$id]['ObjectName']=$name; }
function SMCARV_ProcessWebhookSignals(int $id, string $json): void { global $deliveries; $deliveries[]=$id; }
class IPSModuleStrict {
    public int $InstanceID=20;
    public array $properties=[], $attributes=[], $variables=[], $debug=[], $parentRequests=[];
    public mixed $parentResponse=null;
    public mixed $parentResponder=null;
    public function __construct() { static $nextInstanceID=20; $this->InstanceID=$nextInstanceID++; }
    public function Create(): void {}
    public function ApplyChanges(): void {}
    public function RegisterHook(string $hook): void {}
    public function RegisterPropertyString(string $key,string $value): void { $this->properties[$key]=$value; }
    public function RegisterPropertyInteger(string $key,int $value): void { $this->properties[$key]=$value; }
    public function RegisterPropertyBoolean(string $key,bool $value): void { $this->properties[$key]=$value; }
    public function RegisterAttributeString(string $key,string $value): void { $this->attributes[$key]=$value; }
    public function RegisterAttributeInteger(string $key,int $value): void { $this->attributes[$key]=$value; }
    public function ReadPropertyString(string $key): string { if (!array_key_exists($key,$this->properties)) throw new RuntimeException("Unregistered property: $key"); return (string)$this->properties[$key]; }
    public function ReadPropertyInteger(string $key): int { return (int)$this->properties[$key]; }
    public function ReadPropertyBoolean(string $key): bool { return (bool)$this->properties[$key]; }
    public function ReadAttributeString(string $key): string { return (string)($this->attributes[$key]??''); }
    public function ReadAttributeInteger(string $key): int { return (int)($this->attributes[$key]??0); }
    public function WriteAttributeString(string $key,string $value): void { $this->attributes[$key]=$value; }
    public function WriteAttributeInteger(string $key,int $value): void { $this->attributes[$key]=$value; }
    public function RegisterTimer(string $key,int $time,string $script): void {}
    public function SetTimerInterval(string $key,int $time): void {}
    public function SetStatus(int $status): void {}
    public function SendDebug(string $key,mixed $value,int $format): void { $this->debug[]=[$key,$value]; }
    public function SendDataToParent(string $json): string { $this->parentRequests[]=json_decode($json,true); return $this->parentResponder !== null ? ($this->parentResponder)($json) : json_encode($this->parentResponse); }
    public function GetIDForIdent(string $ident): int { return $this->variables[$ident]??0; }
    private function register(string $ident,string $name,string $profile,int $type,int $position): void {
        global $objects,$nextObject,$profiles;
        if ($profile !== '' && (!isset($profiles[$profile]) || $profiles[$profile]['ProfileType'] !== $type)) throw new RuntimeException('Invalid profile type for '.$ident);
        if (!$this->GetIDForIdent($ident)) {
            $id=$nextObject++; $this->variables[$ident]=$id;
            $objects[$id]=['ObjectName'=>$name,'ObjectIdent'=>$ident,'ParentID'=>$this->InstanceID,'VariableType'=>$type,'VariableProfile'=>$profile,'ObjectPosition'=>$position,'value'=>[false,0,0.0,''][$type]];
        } else {
            $objects[$this->GetIDForIdent($ident)]['VariableProfile']=$profile;
        }
    }
    public function UnregisterVariable(string $ident): void { global $objects; unset($objects[$this->GetIDForIdent($ident)], $this->variables[$ident]); }
    public function RegisterVariableBoolean(string $ident,string $name,string $profile='',int $position=0): void { $this->register($ident,$name,$profile,0,$position); }
    public function RegisterVariableInteger(string $ident,string $name,string $profile='',int $position=0): void { $this->register($ident,$name,$profile,1,$position); }
    public function RegisterVariableFloat(string $ident,string $name,string $profile='',int $position=0): void { $this->register($ident,$name,$profile,2,$position); }
    public function RegisterVariableString(string $ident,string $name,string $profile='',int $position=0): void { $this->register($ident,$name,$profile,3,$position); }
    public function SetValue(string $ident,mixed $value): void { global $objects; $objects[$this->variables[$ident]]['value']=$value; }
    public function EnableAction(string $ident): void {}
    public function UpdateFormField(string $name,string $key,string $value): void {}
}

$profiles = [];
function IPS_VariableProfileExists(string $name): bool { global $profiles; return isset($profiles[$name]); }
function IPS_CreateVariableProfile(string $name, int $type): void { global $profiles; $profiles[$name] = ['ProfileType'=>$type]; }
function IPS_GetVariableProfile(string $name): array { global $profiles; return $profiles[$name]; }
function IPS_SetVariableProfileText(string $name, string $prefix, string $suffix): void { global $profiles; $profiles[$name]['Suffix']=$suffix; }
function IPS_SetVariableProfileDigits(string $name, int $digits): void {}
function IPS_SetVariableProfileValues(string $name, float $minimum, float $maximum, float $step): void { global $profiles; $profiles[$name]['Minimum']=$minimum; $profiles[$name]['Maximum']=$maximum; }
function IPS_SetVariableProfileAssociation(string $name, mixed $value, string $label, string $icon, int $color): void {}
foreach (['~UnixTimestamp'=>1, '~Switch'=>0, '~Lock'=>0, '~Temperature'=>2, '~Intensity.100'=>1] as $name=>$type) IPS_CreateVariableProfile($name,$type);
require dirname(__DIR__) . '/SmartcarVehicle/module.php';
require dirname(__DIR__) . '/SmartcarConfigurator/module.php';
$source = file_get_contents(dirname(__DIR__) . '/SmartcarSplitter/module.php');
$start = strpos($source, '    private function HttpRequestRaw(');
$end = strpos($source, '    private function ExtractStatusCode(', $start);
if ($start === false || $end === false) throw new RuntimeException('Mock transport boundary missing');
$mock = '    private function HttpRequestRaw(string $context, string $method, string $url, array $headers, ?string $content=null, int $timeout=30): ?array { return TestTransport::request($method,$url,$headers,$content,$timeout); }' . "\n\n";
eval(substr(substr_replace($source,$mock,$start,$end-$start),5));
class TestTransport {
    public static array $requests=[];
    public static ?array $reply=null;
    public static function request(string $method,string $url,array $headers,?string $content,int $timeout): ?array {
        self::$requests[]=compact('method','url','headers','content','timeout');
        return self::$reply;
    }
}
