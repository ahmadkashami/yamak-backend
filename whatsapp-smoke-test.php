<?php
/**
 * WhatsApp smoke test — run with:  php whatsapp-smoke-test.php
 * Sends a real auth-template message and a plain-text message to the test phone.
 * Delete this file after verifying.
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$phone = '96550535103';
$code  = '5103';

/** @var \App\Services\WhatsAppService $wa */
$wa = app(\App\Services\WhatsAppService::class);

echo "Sending auth template to {$phone} with code [{$code}] ...\n";
$result1 = $wa->sendAuthMessage($phone, $code);
if ($result1) {
    echo "  OK: " . json_encode($result1) . "\n";
} else {
    echo "  FAILED — check logs for WhatsApp error details.\n";
}

echo "Sending plain-text message to {$phone} ...\n";
$result2 = $wa->sendMessage($phone, "*{$code}* is your Yamak test code. This is a smoke test — ignore if unexpected.");
if ($result2) {
    echo "  OK: " . json_encode($result2) . "\n";
} else {
    echo "  FAILED — check logs for WhatsApp error details.\n";
}
