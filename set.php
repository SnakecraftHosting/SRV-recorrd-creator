<?php
include_once('env.php');

include_once('cloudflare_api.php');
include_once('ptero_api.php');


// CloudFlare username and API token
$cf = new cloudflare_api($CLOUDFLARE_EMAIL, $CLOUDFLARE_TOKEN);
$ptero = new pterodactyl_api($PANEL_URL, $PANEL_TOKEN, 'application');


$basedomain = strtolower($_POST['domain']);
$name = strtolower($_POST['name']);
$serverURL = $_POST['serverurl'];
$email = strtolower($_POST['email']);


function returnError($errormsg)
{
  $error = array('error' => $errormsg);
  header('Content-Type: application/json');
  http_response_code(400);
  return json_encode($error);
}

function isValidPanelServerId($str)
{
  $panelUrl = getenv('PANEL_URL');
  $espstr = explode('/', $str);
  $serverId = end($espstr);
  if (strpos($str, $panelUrl) !== 0 || strlen($serverId) !== 8 || !preg_match('/^[a-zA-Z0-9]{8}$/', $serverId)) {
    return false;
  }
  return true;
}

function getAllocationDetails($allocations, $defaultAllocationId)
{
  foreach ($allocations as $allocation) {
    if ($allocation->attributes->id == $defaultAllocationId) {
      return [
        'ip' => $allocation->attributes->ip,
        'domain' => $allocation->attributes->alias,
        'port' => $allocation->attributes->port
      ];
    }
  }

  return null; // Allocation not found
}

function getServerIP($serverAllocation)
{
  global $DOMAIN_IPS;
  $ip = $serverAllocation['ip'];

  // check if $ip is not 0.0.0.0 or a local ipv4
  if ($ip != '0.0.0.0' && !preg_match('/^(127\.|192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $ip)) {
    return $ip;
  }

  $domain = $serverAllocation['domain'];

  // check if $domain is an IP address
  if (filter_var($domain, FILTER_VALIDATE_IP)) {
    // check if $domain is not 0.0.0.0 or a local ipv4
    if ($domain != '0.0.0.0' && !preg_match('/^(127\.|192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $domain)) {
      return $domain;
    }
  } else {
    // $domain is a domain name
    if (in_array($domain, array_column($DOMAIN_IPS, 'domain'))) {
      // domain found in $DOMAIN_IPS, return corresponding IP
      $key = array_search($domain, array_column($DOMAIN_IPS, 'domain'));
      return $DOMAIN_IPS[$key]['ip'];
    } else {
      // domain not found in $DOMAIN_IPS, do DNS lookup
      $ip = gethostbyname($domain);
      if ($ip != $domain) {
        return $ip;
      }
    }
  }

  // no valid IP found, return null
  return null;
}


function isSubUser($serverSubusers, $userid, $permission = 'allocation.create')
{
  // Check if 'subusers' key exists and its value is an array

  if (isset($serverSubusers) && is_array($serverSubusers->data)) {
    // Loop through the subusers
    foreach ($serverSubusers->data as $subuser) {
      // Check if the user ID matches and if the required permission is in the subuser's permissions array
      if ($subuser->attributes->user_id === $userid) {
        if (in_array($permission, $subuser->attributes->permissions)) {
          // User is a subuser and has the required permission
          return true;
        }
      }
    }
  }
  // User is not a subuser or does not have the required permission
  return false;
}

function create_dns_records($baseDomain, $subdomain, $server_ip, $server_port)
{
  global $cf;
  global $DNS_DOMAINS;

  $domain = strtolower($baseDomain);

  // Check if the base domain exists
  $zone_id = null;
  foreach ($DNS_DOMAINS as $dm) {
    if ($dm['domain'] == $domain) {
      $zone_id = $dm['zoneid'];
      break;
    }
  }
  if (!$zone_id) {
    throw new Exception("Base domain does not exist.");
  }

  $dnsRecord = strtolower($subdomain . "." . $domain);
  // Check if the subdomain exists
  $existing_records = $cf->get_zone_records($zone_id);
  if (isset($existing_records->data->errors[0])) {
    throw new Exception("Cloudflare error");
  }
  foreach ($existing_records->data->result as $record) {
    if ($record->type == 'A' && $record->name == $dnsRecord) {
      throw new Exception("Subdomain already exists.");
    }
  }

  // Create A record

  $cf->create_a_record($zone_id, $dnsRecord, $server_ip);
  // Create SRV record
  $cf->create_srv_record($zone_id, $subdomain, $server_port, 0, "_tcp", "_minecraft", $dnsRecord, 5);
}


if (!isset($basedomain)) {
  echo returnError("Selected domain is not valid.");
  exit();
}
if (!isset($_POST['name']) || !preg_match('/^[a-zA-Z0-9-]{1,255}+$/', $name)) {
  echo returnError("chosen subdomain/server name is not valid must be 2 to 255 charigets long.");
  exit();
}

if (!isset($serverURL) || !isValidPanelServerId($serverURL)) {
  echo returnError("Please Provide a valid server url.");
  exit();
}


if (!isset($_POST['email']) || !preg_match('/^([a-zA-Z0-9_\.-]+)@([\da-z\.-]+)\.([a-z\.]{2,6})$/', $email)) {
  echo returnError("Please Provide a valid email address.");
  exit();
}

try {
  /**
   * @var Object $user
   */
  $user = $ptero->getUserByEmail($email);
  if ($user == null) {
    echo returnError("Cant find server please check your email or server url.");
    exit();
  }
  $explSvid = explode('/', $serverURL);
  $serverID = end($explSvid);
  /**
   * @var Object $server
   */
  $server = $ptero->fetchServerByIdentifier($serverID);

  if ($server === null) {
    echo returnError("Cant find server please check your server url.");
    exit();
  }

  $doBeSubUser = isSubUser($server->relationships->subusers, $user->id);
  if ($server->user != $user->id && !$doBeSubUser) {
    echo returnError("you must be the owner or have the allocation.create permission on this server.");
    exit();
  }
  $serverAllocation = getAllocationDetails($server->relationships->allocations->data, $server->allocation);

  $serveripv4 = getServerIP($serverAllocation, $DOMAIN_IPS);
  if ($serveripv4 == null) {
    echo returnError("server does not have valid ipv4 address");
    exit();
  }


  create_dns_records($basedomain, $name, $serveripv4, $serverAllocation['port']);
} catch (Exception $e) {
  echo returnError($e->getMessage());
  exit();
}

echo "{\"message\": \"success please wait for changes to take affect\"}";
