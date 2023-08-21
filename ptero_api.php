<?php

/**
 * Ptero API
 *
 *
 * @author 404invalid-user <invaliduser@bruvland.com>
 * @copyright 404invalid-user. 2023
 * @version 1.0
 */
class pterodactyl_api
{
  const TIMEOUT = 5;

  private $URI;
  //Stores the api key
  private $token;
  //client/application
  private $apiType;


  /**
   * Make a new instance of the API client
   */
  public function __construct(string $url, string $token, string $apType)
  {
    // Validate the URL format
    if (!filter_var($url, FILTER_VALIDATE_URL) || substr($url, -1) === '/') {
      throw new InvalidArgumentException('Invalid panel URL format must be url starting with http(s):// and not ending in /');
    }

    $this->URI = $url;
    $this->token = $token;

    if ($apType === 'application') {
      $this->apiType = 'application';
    } elseif ($apType === 'client') {
      $this->apiType = 'client';
    } else {
      throw new InvalidArgumentException('Invalid api type client or appliation ');
    }
  }

  private function http_get($url)
  {

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Authorization: Bearer ' . $this->token,));
    $http_result = curl_exec($ch);
    $error       = curl_error($ch);
    $http_code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
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
      'Authorization: Bearer ' . $this->token,
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



  //================
  //public functions
  //================

  public function getUserByEmail(string $email)
  {
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      throw new InvalidArgumentException("Must provide a valid email address.");
    }

    try {
      $currentPage = 0;
      $pagesMax = 0;
      $foundUser = null;


      $fetchServersPage = function ($p) use (&$currentPage, &$pagesMax, &$foundUser, $email) {

        $url = $this->URI . "/api/application/users?page=" . strval($p + 1);

        $response = $this->http_get($url);
        if ($response->statusCode == 404) {
          throw new InvalidArgumentException('Invalid api url users gave 404');
        }

        $currentPage = $response->data->meta->pagination->current_page;
        $pagesMax = $response->data->meta->pagination->total_pages;
        foreach ($response->data->data as $rawUser) {
          $user = $rawUser->attributes;
          if ($user->email === strtolower($email)) {
            $foundUser = $user;
          }
        }
      };
      $fetchServersPage($currentPage);
      if ($foundUser != null || $currentPage == $pagesMax) {
        return $foundUser;
      }

      $fetchServersPage($currentPage);
      for ($i = 0; $i < $pagesMax - 1; $i++) {
        $fetchServersPage($currentPage + 1);
        if ($foundUser != null || $currentPage == $pagesMax) {
          return $foundUser;
        }
      }
      return $foundUser;
    } catch (Exception $e) {
      throw $e->getMessage();
    }
  }




  public function fetchUserServers($id)
  {
    try {
      $currentPage = 0;
      $pagesMax = 0;
      $servers = array();

      $fetchServersPage = function ($p) use (&$currentPage, &$pagesMax, &$servers, $id) {

        $url = $this->URI . "/api/application/servers?include=allocations,subusers&page=" . strval($p + 1);
        $response = $this->http_get($url);
        $currentPage = $response->data->meta->pagination->current_page;
        $pagesMax = $response->data->meta->pagination->total_pages;
        foreach ($response->data->data as $rawServer) {
          $server = $rawServer->attributes;
          if ($server->user === $id || $server->user === (int)$id || $server->user === (string)$id) {
            $servers[] = $server;
          }
          if (in_array($id, $server->users, true) || in_array((int)$id, $server->users, true) || in_array((string)$id, $server->users, true)) {
            $servers[] = $server;
          }
          // $servers[] = $server;
        }
      };

      $fetchServersPage($currentPage);
      for ($i = 0; $i < $pagesMax - 1; $i++) {
        $fetchServersPage($currentPage);
      }
      return $servers;
    } catch (Exception $e) {
      throw $e->getMessage();
    }
  }

  public function fetchServerByIdentifier($id)
  {
    try {
      $currentPage = 0;
      $pagesMax = 0;
      $foundServer = null;

      $fetchServersPage = function ($p) use (&$currentPage, &$pagesMax, &$foundServer, $id) {

        $url = $this->URI . "/api/application/servers?include=allocations,subusers&page=" . strval($p + 1);
        $response = $this->http_get($url);
        $currentPage = $response->data->meta->pagination->current_page;
        $pagesMax = $response->data->meta->pagination->total_pages;
        $response = $this->http_get($url);

        if ($response->statusCode == 404) {
          throw new InvalidArgumentException('Invalid api url servers gave 404');
        }
        foreach ((array)$response->data->data as $rawServer) {
          $server = $rawServer->attributes;
          if ($server->identifier === $id) {
            $foundServer = $server;
          }
        }
      };
      $fetchServersPage($currentPage);
      if ($foundServer != null || $currentPage == $pagesMax) {
        return $foundServer;
      }
      for ($i = 0; $i < $pagesMax - 1; $i++) {
        $fetchServersPage($currentPage + 1);
        if ($foundServer != null || $currentPage == $pagesMax) {
          return $foundServer;
        }
      }
      return $foundServer;
    } catch (Exception $e) {
      throw $e->getMessage();
    }
  }

  public function fetchServer($id)
  {
    $url = $this->URI . "/api/application/servers/" . $id . "?include=allocations,subusers";
    $response = $this->http_get($url);
    return $response;
  }
}

function objectToArray($object)
{
  $array = json_decode(json_encode($object), true);
  foreach ($array as $key => $value) {
    if (is_object($value)) {
      $array[$key] = objectToArray($value);
    }
  }
  return $array;
}
