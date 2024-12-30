<?php

/**
 * Receiver for GoIP SMS messages
 * 
 * GoIP sends the SMS messages via UDP in the following format:
 *   RECEIVE:$receiveId;id:$id;password:$password;srcnum:$srcnum;msg:$msg
 *   example: RECEIVE:1734804704;id:goip01;password:xxxxxx;srcnum:+358401234567;msg:Hello
 * The messages are parsed and forwarded to an email address, but you can easily replace the email implementation with something else (database, MQTT etc.)
 * 
 * Requirements:
 * - PHP Sockets extension must be enabled
 */

$port = getenv('RECEIVE_PORT') ?: '44444' ; // SMS Server Port
$validId = getenv('RECEIVE_ID') ?: 'goip01'; // SMS Client ID
$validPassword = getenv('RECEIVE_PASSWORD');
$logVerbosity = getenv('RECEIVE_LOG') ?: 'debug'; // "debug" for verbose logs
$waitForMultipart = getenv('RECEIVE_WAIT') ?: '60'; // "60" is a safe option, but a bit slow

error_reporting(E_ALL);
set_time_limit(0);

if (($sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP)) === false) {
    die("socket_create() failed: " . socket_strerror(socket_last_error()) . "\n");
}

// Bind the socket to 0.0.0.0 so we can listen for datagrams from any IP
$bindOK = socket_bind($sock, '0.0.0.0', $port);
if ($bindOK === false) {
    die("socket_bind() failed: " . socket_strerror(socket_last_error($sock)) . "\n");
}

echo "GoIP SMS Receiver is listening on port $port...\n\n";

while (true) {
    $buffer = '';
    $remoteIp = '';
    $remotePort = 0;
    
    // Block until we receive something
    $bytesReceived = socket_recvfrom($sock, $buffer, 2048, 0, $remoteIp, $remotePort);
    if ($bytesReceived === false) {
        echo "socket_recvfrom() failed: " . socket_strerror(socket_last_error($sock)) . "\n";
        continue; 
    }

    if($logVerbosity == "debug") {
        echo "Received from $remoteIp:$remotePort --> $buffer\n";
    }

    // Parse the message (see example from top of this file). Split by semicolon first, then parse each segment by splitting with colon
    $params = explode(';', $buffer);
    $parsed = [];
    $receiveId = null;

    // Process only RECEIVE messages
	if(substr($params[0], 0, 8) != "RECEIVE:") {
        // Respond to "req" keepalive messages
        processKeepalive($sock, $remoteIp, $remotePort, $params, $logVerbosity);
        
        // Check if SMS messages need to be sent forward
        // This works because GoIP is chatty with CELLINFO etc. messages that trigger this function every now and then
        purgeOldMessages($waitForMultipart);
		continue;
	}

    foreach ($params as $param) {
        $param = trim($param); // e.g. "id:goipid1"
        list($key, $value) = explode(':', $param, 2);
        $parsed[strtolower($key)] = $value;
    }

    // Extract data from the parsed array for convenience
    $receiveId = isset($parsed['receive']) ? $parsed['receive'] : '';
    $goipId = isset($parsed['id']) ? $parsed['id'] : '';
    $password = isset($parsed['password']) ? $parsed['password'] : '';
    $srcnum = isset($parsed['srcnum']) ? $parsed['srcnum'] : '';
    $msg = isset($parsed['msg']) ? $parsed['msg'] : '';

    // Validate the ID and password
    if ($goipId === $validId && $password === $validPassword) {
        // Here you might store $srcnum and $msg in a database, etc. or maybe later in the purgeOldMessages function
        processInboundMessage($goipId, $srcnum, $msg);
        $ackMsg = "RECEIVE $receiveId OK";
    } else {
        $ackMsg = "RECEIVE $receiveId ERROR invalid credentials";
    }

    if($logVerbosity == "debug") {
        echo "Sending $ackMsg to $remoteIp:$remotePort \n";
    }

    // Send the response back to the GoIP to stop the retransmissions
    socket_sendto($sock, $ackMsg, strlen($ackMsg), 0, $remoteIp, $remotePort);
    purgeOldMessages($waitForMultipart);
}

socket_close($sock);

/**
 * Answer to the GoIP keepalive messages (show SMS = Y on the GoIP Status --> Summary page)
 * example request: req:19;id:goip01;pass:XXX;num:;signal:15;gsm_status:LOGIN;voip_status:LOGIN;voip_state:IDLE;remain_time:-1;imei:123455;imsi:345667;iccid:678890;pro:dna;
 * example response: reg:19;status:200;
 */
function processKeepalive($sock, $remoteIp, $remotePort, $params, $logVerbosity) {
    if(substr($params[0], 0, 4) == "req:") {
        $msg = str_replace("req", "reg", $params[0]) . ";status:200;";
        if($logVerbosity == "debug") {
            echo "Sending keepalive response: $msg to $remoteIp:$remotePort \n";
        }
        socket_sendto($sock, $msg, strlen($msg), 0, $remoteIp, $remotePort);
    }
}

/**
 * Global array holding queued messages
 * Format:
 *   $messageBuffer = [
 *     "{$id}|{$srcnum}" => [
 *       'last_received' => <timestamp of the latest part>,
 *       'parts'        => [ "part1", "part2", ... ]
 *     ]
 *   ];
 */
$messageBuffer = [];

/**
 * Store/queue the newly arrived message part
 *
 * @param string $id     The GoIP ID
 * @param string $srcnum Source mobile phone number
 * @param string $msg    The message part itself
 */
function processInboundMessage($id, $srcnum, $msg)
{
    global $messageBuffer;

    // Generate a key (id|srcnum) to uniquely identify the conversation (the GoIP $receiveId is unique per part of message)
    $key = $id . '|' . $srcnum;

    if (!isset($messageBuffer[$key])) {
        $messageBuffer[$key] = [
            'last_received' => time(),
            'parts'        => []
        ];
    }

    $messageBuffer[$key]['last_received'] = time();
    $messageBuffer[$key]['parts'][] = $msg;
}


/**
 * Purge old messages / send them forward. 
 * Sometimes GoIP sends the message parts back to back, but sometimes there is even 
 * a 30 second delay between the parts, so we need to wait for all parts to arrive
 * 
 * @param string $timeToWait How long to wait for the next part to arrive
 */
function purgeOldMessages($timeToWait)
{
    global $messageBuffer;
    
    if(empty($messageBuffer)) {
        return;
    }

    foreach ($messageBuffer as $key => $data) {
        $timeDiff = time() - $data['last_received'];

        // If it's been over $timeToWait seconds since the last message part, send it forward
        if ($timeDiff > $timeToWait) {
            $subject = "SMS Message from $key";
            $fullMsg = implode('', $data['parts']);
            echo "Sending message " . $subject . " : " . $fullMsg . "\n";
            
            // "call" an external script to process the message - replace this with your own implementation if wanted
            include("email.php"); 
            
            unset($messageBuffer[$key]); // Remove this entry from the buffer
        }
    }
}
