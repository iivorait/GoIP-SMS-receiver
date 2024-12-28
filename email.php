<?php

/**
 * This file sends the SMS message in an email. You can replace this easily with any other implementation.
 * The variables $subject and $fullMsg contain the email subject and email body from the receive.php.
 * Use these environment variables for configuring the email:
 *   SMTP_HOST = hostname/IP of the SMTP server
 *   SMTP_PORT = SMTP port, 25/465/587 etc., 25 by default
 *   SMTP_USERNAME = for logging into the SMTP server, optional if using an unauthenticated connection
 *   SMTP_PASSWORD = password to the SMTP server, optional
 *   SMTP_SECURE = encryption setting, optional. Possible values are empty, 'tls' and 'ssl'
 *   SMTP_FROM = the sender email address
 *   SMTP_TO = the recipient email address
 *   SMTP_PREFACE = text to be added in the beginning of the message body, optional
 */

require_once 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if(empty($mail)) {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = getenv('SMTP_HOST');  
    $mail->Port = getenv('SMTP_PORT') ?: '25';

    if(empty(getenv('SMTP_USERNAME'))) { // unauthenticated SMTP
        $mail->SMTPAuth = false;
    } else {
        $mail->Username = getenv('SMTP_USERNAME');
        $mail->Password = getenv('SMTP_PASSWORD');
    }
    
    if(!empty(getenv('SMTP_SECURE'))) { // PHPMailer::ENCRYPTION_STARTTLS = 'tls' and PHPMailer::ENCRYPTION_SMTPS = 'ssl'
        $mail->SMTPSecure = getenv('SMTP_SECURE');
    }
    
    $mail->setFrom(getenv('SMTP_FROM'));
    $mail->addAddress(getenv('SMTP_TO'));
}

try {
    $mail->isHTML(false);
    $mail->Subject = $subject;
    $mail->Body = getenv('SMTP_PREFACE') ?: '' . $fullMsg;
    $mail->send();
    echo "Email message sent successfully!\n";
} catch (Exception $e) {
    echo "Email message could not be sent. Error: {$mail->ErrorInfo} \n";
}
