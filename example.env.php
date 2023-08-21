<?php
$CLOUDFLARE_TOKEN = "";

$PANEL_URL = "https://panel.ptero.lan";
//panels applcation token
$PANEL_TOKEN = "";

//find your zone id by going to your domain on cloudflare and look on the right its important to add the domain name and zone in the same position
$DNS_DOMAINS = array(
    array(
        'domain' => 'mymc-srv.com',
        'zoneid' => 'ZONE ID'
    ),
);

$DOMAIN_IPS = array(
array('domain'=> 'example.sch', 'ip' => 'PUB.LIC.23.23')
);
