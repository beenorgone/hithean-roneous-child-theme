<?php

// API key khai trong wp-config.php: define('HITHEAN_GREENSPARK_API_KEY', '...'); — chưa khai thì bỏ qua widget.
$hithean_greenspark_key = defined('HITHEAN_GREENSPARK_API_KEY') ? (string) HITHEAN_GREENSPARK_API_KEY : '';
if ($hithean_greenspark_key === '') {
  return;
}

$curl = curl_init();

curl_setopt_array($curl, [
  CURLOPT_URL => "https://api.getgreenspark.com/v2/widgets/by-percentage-of-revenue-widget?lng=en",
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_ENCODING => "",
  CURLOPT_MAXREDIRS => 10,
  CURLOPT_TIMEOUT => 30,
  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
  CURLOPT_CUSTOMREQUEST => "POST",
  CURLOPT_POSTFIELDS => json_encode([
    'integrationSlug' => 'ivar-viet-nam-trading-and-service-company-limited-96506',
    'color' => 'green',
    'withPopup' => false,
    'style' => 'default',
    'popupTheme' => 'light'
  ]),
  CURLOPT_HTTPHEADER => [
    "accept: application/json",
    "content-type: application/json",
    "x-api-key: " . $hithean_greenspark_key
  ],
]);

$response = curl_exec($curl);
$err = curl_error($curl);

curl_close($curl);

if ($err) {
  echo "cURL Error #:" . $err;
} else {
  echo $response;
}
