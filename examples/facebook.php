<?php
require 'vendor/autoload.php';

$app_id     = 'APP_ID';
$app_secret = 'APP_SECRET';

$app_token_url = "https://graph.facebook.com/oauth/access_token?"
    . "client_id=" . $app_id
    . "&client_secret=" . $app_secret
    . "&grant_type=client_credentials";

    $response = file_get_contents($app_token_url);
    $params = null;

parse_str($response, $params);

$access_token = $params['access_token'];

$params = ['q' => 'coffee', 'access_token' => $access_token];

(new Unio)
  ->useSpec('fb')
  ->get('search', $params, function ($response) {
    echo 'Response' . PHP_EOL;
    var_dump($response);
  });