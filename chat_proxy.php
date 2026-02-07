<?php
/**
 * Coze API Streaming Proxy
 * Handles communication with Coze V3 Chat API
 */
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/init.php';

// Disable buffering to ensure streaming works
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', 1);
}
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);
for ($i = 0; $i < ob_get_level(); $i++) { ob_end_flush(); }
ob_implicit_flush(1);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Nginx specific

$config = include 'coze_config.php';

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['message'])) {
    echo "data: " . json_encode(['event' => 'error', 'data' => 'Invalid input']) . "\n\n";
    exit;
}

$userMessage = $input['message'];
$userId = isset($input['user_id']) ? $input['user_id'] : 'guest_user';
// Support resuming conversation
$conversationId = isset($input['conversation_id']) && !empty($input['conversation_id']) ? $input['conversation_id'] : null;

// --- Security & Logging Start ---

// 1. Sensitive Word Check (Python Service)
// Replaces PHP in-memory check to avoid memory exhaustion
$blocked = false;
$blockReason = '';

if (strlen($userMessage) > 0) {
    $pythonUrl = 'http://127.0.0.1:6001/check';
    $payload = json_encode(['text' => $userMessage]);

    $ch = curl_init($pythonUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2); // 2s timeout

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpCode === 200 && $response) {
        $result = json_decode($response, true);
        if (isset($result['blocked']) && $result['blocked'] === true) {
            $blocked = true;
            $matchedWord = isset($result['word']) ? $result['word'] : 'unknown';
            $blockReason = "Matched: " . $matchedWord;
        }
    } else {
        // Log failure but don't block user (Fail Open)
        // Or implement usage of local PHP as backup?
        // Given PHP memory issues, local backup might crash process. Better to just log.
        error_log("Sensitive Word Python Service Failed: Code $httpCode, Error: $curlError");
    }
}

// 2. Enhanced Logging (详细日志)
$logDir = dirname(__FILE__) . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
    // Ensure permissions if we just created it
    @chmod($logDir, 0777);
}

// Helper to get IP
$userIp = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : $_SERVER['REMOTE_ADDR'];

$logEntry = sprintf(
    "[%s] IP: %s | UserID: %s | Status: %s\nInput: %s\n%s\n-----------------------------------\n",
    date('Y-m-d H:i:s'),
    $userIp,
    $userId,
    $blocked ? "BLOCKED ($blockReason)" : "ALLOWED",
    // Truncate message for neatness but keep enough context
    str_replace(["\r", "\n"], ' ', substr($userMessage, 0, 500)),
    $blocked ? "Action: Request terminated." : "Action: Forwarding to Coze/API."
);

// Try to write log
@file_put_contents($logDir . '/' . date('Y-m-d') . '.log', $logEntry, FILE_APPEND);


if ($blocked) {
    // Return friendly error message (SSE Format)
    // Use 'conversation.message.delta' so frontend renders it as answer
    echo "event: conversation.message.delta\n";
    echo "data: " . json_encode([
        'conversation_id' => $conversationId ?: '',
        'role' => 'assistant',
        'content' => '很抱歉，您的输入包含潜在的敏感或违规内容，系统已拦截。请调整措辞后重试。',
        'content_type' => 'text',
        'type' => 'answer'
    ]) . "\n\n";

    // End the stream
    echo "event: done\n";
    echo "data: " . json_encode(['event' => 'done']) . "\n\n";
    exit;
}

// --- Security & Logging End ---

// Initialize API URL
$apiUrl = $config['base_url'] . '/chat';

if ($conversationId) {
    // If conversation_id is present, append as query param
    $apiUrl .= '?conversation_id=' . urlencode($conversationId);
}

// DEBUG: Log request to file (Legacy removed in favor of enhanced logging)
// See logs/ directory

// Prepare payload
// Only send the current user message. History is handled by conversation_id on the server side.
$payload = [
    'bot_id' => $config['bot_id'],
    'user_id' => $userId,
    'stream' => true,
    'auto_save_history' => true,
    'additional_messages' => [
        [
            'role' => 'user',
            'content' => $userMessage,
            'content_type' => 'text'
        ]
    ]
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $apiUrl);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $config['pat_token'],
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, false); // Important for manual stream handling

// Callback for streaming
curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) {
    echo $data;
    flush();
    return strlen($data);
});

curl_exec($ch);

if (curl_errno($ch)) {
    echo "data: " . json_encode(['event' => 'error', 'data' => curl_error($ch)]) . "\n\n";
    file_put_contents(dirname(__FILE__) . '/debug_chat.log', "CURL Error: " . curl_error($ch) . "\n", FILE_APPEND);
}

curl_close($ch);
?>
