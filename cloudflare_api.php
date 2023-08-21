<?php

/**
 * CloudFlare API
 *
 *
 * @author 404invalid-user <invaliduser@bruvland.com>
 * @copyright 404invalid-user. 2023
 * @version 1.0
 */
class cloudflare_api
{
  //auth type key (not supported) token
  private $auth_method;

  const TIMEOUT = 5;


  //Stores the api key
  private $host_key;

  //Stores the token auth (better)
  private $email;
  private $api_token;

  /**
   * Make a new instance of the API client
   */
  public function __construct()
  {
    $parameters = func_get_args();
    switch (func_num_args()) {
      case 1:
        //a host API
        $this->host_key  = $parameters[0];
        $this->auth_method = 'key';
        echo "Error: cloudflare api you are using key method not token key method is not supported";
        break;
      case 2:
        //a user request
        $this->email     = $parameters[0];
        $this->api_token = $parameters[1];
        $this->auth_method = 'token';
        break;
    }
  }

  private function http_get($url)
  {
    $headers = array(
      'Authorization: Bearer ' . $this->api_token,
      'Content-Type: application/json'
    );

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $http_result = curl_exec($ch);
    $error       = curl_error($ch);
    $http_code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return (object) array(
      'statusCode' => $http_code,
      'data' => json_decode($http_result),
      'error' => $error
    );
  }


  private function http_post($url, $data)
  {
    $headers = array(
      'Authorization: Bearer ' . $this->api_token,
      'Content-Type: application/json'
    );
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_VERBOSE, 0);
    curl_setopt($ch, CURLOPT_FORBID_REUSE, true);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $http_result = curl_exec($ch);
    $error       = curl_error($ch);
    $http_code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return (object) array(
      'statusCode' => $http_code,
      'data' => json_decode($http_result),
      'error' => $error
    );
  }

  function get_zone_records($zone)
  {
    //https://api.cloudflare.com/client/v4/zones/{zone_identifier}/dns_records
    $result = $this->http_get("https://api.cloudflare.com/client/v4/zones/" . $zone . "/dns_records?per_page=1002");
    return $result;
  }
  function create_a_record($zone_id, $name, $ip_address)
  {
    if (!preg_match('/^(?!-)[A-Za-z0-9-]{1,63}(?<!-)(\.[A-Za-z0-9-]{1,63})*\.[A-Za-z]{2,63}$/', $name)) {
      throw new Exception("Invalid A DNS record name: {$name}");
    }
    if (!filter_var($ip_address, FILTER_VALIDATE_IP)) {
      throw new Exception("Invalid IP address: {$ip_address}");
    }

    $url = "https://api.cloudflare.com/client/v4/zones/{$zone_id}/dns_records";
    $data = array(
      'type' => 'A',
      'name' => $name,
      'content' => $ip_address,
      'ttl' => 1,
      'proxied' => false
    );
    $response = $this->http_post($url, json_encode($data), 'USER');
    return $response->statusCode;
  }

  function create_srv_record($zone_id, $name, $port, $priority, $protocol, $service, $target, $weight)
  {
    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9-]{0,61}[a-zA-Z0-9]\.?$/', $name)) {
      throw new Exception("Invalid SRV record name: {$name}");
    }

    if (!is_numeric($port) || $port < 0 || $port > 65535) {
      throw new Exception("Invalid SRV record port: {$port}");
    }
    if (!is_numeric($priority) || $priority < 0 || $priority > 65535) {
      throw new Exception("Invalid SRV record priority: {$priority}");
    }
    if (!preg_match('/^_(tcp|udp|tls)$/', $protocol)) {
      throw new Exception("Invalid protocol");
    }
    if (!preg_match('/^[a-zA-Z0-9-_.]+$/', $target)) {
      throw new Exception("Invalid SRV record target: {$target}");
    }
    if (!is_numeric($weight) || $weight < 0 || $weight > 65535) {
      throw new Exception("Invalid SRV record weight: {$weight}");
    }

    $url = "https://api.cloudflare.com/client/v4/zones/{$zone_id}/dns_records";
    $data = array(
      'type' => 'SRV',
      'ttl' => 1,
      'data' => array(
        'name' => $name,
        'port' => $port,
        'priority' => $priority,
        'proto' => $protocol,
        'service' => $service,
        'target' => $target,
        'weight' => $weight
      )
    );
    $response = $this->http_post($url, json_encode($data));
    return $response->statusCode;
  }
}
