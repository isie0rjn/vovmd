<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Отправка JSON-ответов. Каждый метод завершает выполнение скрипта.
 */
class Response
{
    /**
     * Отправляет успешный ответ.
     *
     * @param mixed $data   Данные для JSON
     * @param int   $status HTTP-код ответа
     * @return never
     */
    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    /**
     * Отправляет ответ с ошибкой в формате {"error": "..."}.
     *
     * @param string                $message Понятное пользователю сообщение
     * @param int                   $status  HTTP-код ответа
     * @param array<string, mixed>  $extra   Дополнительные поля ответа (например, debug)
     * @return never
     */
    public static function error(string $message, int $status, array $extra = []): never
    {
        self::json(['error' => $message] + $extra, $status);
    }

    /**
     * Отправляет ответ 422 со списком ошибок по полям.
     *
     * @param array<string, string> $errors Сообщения об ошибках, ключ — имя поля
     * @return never
     */
    public static function validationError(array $errors): never
    {
        self::json(['error' => 'Проверьте введённые данные', 'errors' => $errors], 422);
    }
}
