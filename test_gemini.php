<?php
// Simple test — just open this file in your browser, no upload needed.

$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=AQ.Ab8RN6J0BMD-1ckIygJZsdoB-UM82HuPa2MYWQ2jp9xssxA5Yg";

$payload = json_encode([
    "contents" => [["parts" => [["text" => "Return ONLY this JSON, no markdown: {\"test\":\"hello\"}"]]]],
    "generationConfig" => ["temperature" => 0.0, "maxOutputTokens" => 100]
]);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$res        = curl_exec($ch);
$curlErr    = curl_error($ch);
$httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);


echo "<h2>Gemini API Test</h2>";
echo "<p><b>HTTP Status Code:</b> " . htmlspecialchars($httpCode) . "</p>";
echo "<p><b>cURL Error (if any):</b> " . htmlspecialchars($curlErr ?: 'none') . "</p>";
echo "<p><b>Raw Response:</b></p>";
echo "<pre style='background:#f0f0f0;padding:15px;border-radius:8px;white-space:pre-wrap;'>" . htmlspecialchars($res ?: '(empty response)') . "</pre>";
?>