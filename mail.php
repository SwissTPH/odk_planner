<?php
if (!defined('MAGIC')) die('!?');

require 'lib/PHPMailerAutoload.php';

# returns "success" or error string
function send_mail($options, $debug=false) {
    global $config;
    $mail = new PHPMailer;
    $mail->IsSMTP();
    $mail->SMTPDebug = 1 * $debug; // debugging: 1 = errors and messages, 2 = messages only
    $mail->SMTPAuth = $config->settings['smtp_auth'];
    $mail->SMTPSecure = $config->settings['smtp_secure'];
    $mail->Host = $config->settings['smtp_server'];
    $mail->Port = $config->settings['smtp_port'];

    $mail->Username = $config->settings['smtp_user'];
    $mail->Password = $config->settings['smtp_pass'];

    $mail->AddAddress($options['recipient']);
    $mail->From = $mail->Username;
    $mail->FromName = 'odk_planner';
    $mail->SetFrom('odk_planner <' . $mail->Username . '>');
    $mail->Subject = $options['subject'];

    if (array_key_exists('message_html', $options)) {
        $mail->IsHTML(true);
        $mail->Body = $options['message_html'];
        $mail->AltBody = $options['message_plain'];
    } else {
        $mail->IsHTML(false);
        $mail->Body = $options['message_plain'];
    }

    if (array_key_exists('file_attachments', $options)) {
        foreach($options['file_attachments'] as $name=>$path) {
            $mail->addAttachment($path, $name);
        }
    }

    if (array_key_exists('string_attachments', $options)) {
        foreach($options['string_attachments'] as $name=>$string) {
            $mail->addStringAttachment($string, $name);
        }
    }

    if ($mail->Send())
        return "success";

    return $mail->ErrorInfo . ' -- username/password mismatch? try running "cron.php -d"';
}

