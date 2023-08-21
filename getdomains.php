<?php
include_once('env.php');

$domain_array = array();
foreach ($DNS_DOMAINS as $dns_domain) {
    $domain_array[] = $dns_domain['domain'];
}

$json_array = json_encode($domain_array);

echo $json_array;