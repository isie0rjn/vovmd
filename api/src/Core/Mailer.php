<?php

declare(strict_types=1);

namespace App\Core;

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Отправка писем через SMTP (по умолчанию Mailpit). Настройки берутся из переменных MAIL_*.
 */
class Mailer
{
    /**
     * Отправляет письмо в виде простого текста.
     *
     * @param string $to      Адрес получателя
     * @param string $subject Тема письма
     * @param string $body    Текст письма
     * @return void
     * @throws Exception Если письмо не удалось отправить
     */
    public static function send(string $to, string $subject, string $body): void
    {
        $encryption = (string) getenv('MAIL_ENCRYPTION');
        $username = (string) getenv('MAIL_USER');

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = getenv('MAIL_HOST') ?: 'mailpit';
        $mail->Port = (int) (getenv('MAIL_PORT') ?: 1025);
        $mail->SMTPAuth = $username !== '';
        $mail->Username = $username;
        $mail->Password = (string) getenv('MAIL_PASS');
        $mail->SMTPSecure = $encryption;
        $mail->SMTPAutoTLS = $encryption !== '';
        $mail->Timeout = 10;
        $mail->CharSet = PHPMailer::CHARSET_UTF8;

        $mail->setFrom(getenv('MAIL_FROM') ?: 'noreply@vov.md', getenv('MAIL_FROM_NAME') ?: 'Forum');
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->isHTML(false);

        $mail->send();
    }
}
