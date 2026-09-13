<?php
// includes/mail.php - Wrapper for mailer.php and SMS functionality
require_once __DIR__ . '/mailer.php';

if (!function_exists('send_mail')) {
    function send_mail(string $to, string $subject, string $html, array $opts = []): bool {
        return send_mail_html($to, $subject, $html, $opts);
    }
}

if (!function_exists('send_sms')) {
    function send_sms(string $phone, string $message): bool {
        $phone = trim($phone);
        $message = trim($message);
        if (empty($phone) || empty($message)) {
            return false;
        }
        
        // Log the SMS for local testing/dry-run
        $logDir = __DIR__ . '/../logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }
        $logFile = $logDir . '/sms.log';
        $logEntry = "[" . date('Y-m-d H:i:s') . "] TO: $phone | MSG: $message\n";
        @file_put_contents($logFile, $logEntry, FILE_APPEND);
        
        // Also save to a separate file in logs/sms/ so it's easily inspectable
        $smsDir = $logDir . '/sms';
        if (!is_dir($smsDir)) {
            @mkdir($smsDir, 0777, true);
        }
        $cleanPhone = preg_replace('~[^0-9+]+~', '_', $phone);
        $smsFile = $smsDir . '/' . date('Ymd_His') . '_' . $cleanPhone . '.txt';
        @file_put_contents($smsFile, "TO: $phone\nDATE: " . date('d.m.Y H:i:s') . "\nMESSAGE:\n$message\n");
        
        // Extensible Live SMS API integrations (e.g. Sms77 or Twilio)
        // If credentials are defined in config.php, we can call them!
        if (defined('SMS_PROVIDER')) {
            if (SMS_PROVIDER === 'sms77' && defined('SMS_API_KEY')) {
                // Sms77 Integration
                $url = "https://gateway.sms77.io/api/sms?key=" . urlencode(SMS_API_KEY) . "&to=" . urlencode($phone) . "&text=" . urlencode($message) . "&type=direct";
                $res = @file_get_contents($url);
                return ($res !== false);
            }
            if (SMS_PROVIDER === 'twilio' && defined('TWILIO_SID') && defined('TWILIO_AUTH_TOKEN') && defined('TWILIO_NUMBER')) {
                // Twilio Integration
                $url = "https://api.twilio.com/2010-04-01/Accounts/" . TWILIO_SID . "/Messages.json";
                $data = [
                    'To' => $phone,
                    'From' => TWILIO_NUMBER,
                    'Body' => $message
                ];
                $ctx = stream_context_create([
                    'http' => [
                        'method' => 'POST',
                        'header' => "Authorization: Basic " . base64_encode(TWILIO_SID . ":" . TWILIO_AUTH_TOKEN) . "\r\nContent-type: application/x-www-form-urlencoded\r\n",
                        'content' => http_build_query($data)
                    ]
                ]);
                $res = @file_get_contents($url, false, $ctx);
                return ($res !== false);
            }
        }
        
        return true; // Return true as it was successfully simulated/logged
    }
}
