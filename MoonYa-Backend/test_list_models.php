<?php
$apiKey = 'sk-WE4wI9Dxe2YXloAEXW28H13drqUJC1zIPjHlpIFVG8iThpWq';
$url = 'https://api.moonshot.cn/v1/models';
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $apiKey]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "HTTP: $httpCode\n";
$data = json_decode($response, true);
if (isset($data['data'])) {
    echo "Available models:\n";
    foreach ($data['data'] as $m) {
        echo "  - " . $m['id'] . "\n";
    }
} else {
    echo $response . "\n";
}
