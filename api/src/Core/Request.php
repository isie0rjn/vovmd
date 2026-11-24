<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Данные входящего запроса: JSON-тело и query-параметры.
 */
class Request
{
    /**
     * Возвращает HTTP-метод запроса в верхнем регистре.
     *
     * @return string
     */
    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /**
     * Возвращает путь запроса без query-строки, без завершающего слэша.
     *
     * @return string Например, /api/publications/5
     */
    public static function path(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';

        return $path === '/' ? $path : rtrim($path, '/');
    }

    /**
     * Читает JSON-тело запроса. Пустое тело считается пустым объектом.
     * Все строки очищаются: trim и удаление управляющих символов, кроме \n и \t.
     *
     * @return array<string, mixed>
     */
    public static function body(): array
    {
        $raw = file_get_contents('php://input');

        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $data = json_decode($raw, true);

        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            Response::error('Некорректный формат запроса', 400);
        }

        return self::clean($data);
    }

    /**
     * Возвращает query-параметры (?a=1&b=2), строки очищены.
     *
     * @return array<string, mixed>
     */
    public static function query(): array
    {
        return self::clean($_GET);
    }

    /**
     * Возвращает значение заголовка запроса.
     *
     * @param string $name Имя заголовка, например X-CSRF-Token
     * @return string Значение или пустая строка
     */
    public static function header(string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        return (string) ($_SERVER[$key] ?? '');
    }

    /**
     * Рекурсивно очищает строки: trim и удаление управляющих символов, кроме \n и \t.
     *
     * @param array<mixed> $data Исходные данные
     * @return array<mixed>
     */
    private static function clean(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $value = preg_replace('/[\x00-\x08\x0B\x0C\x0D-\x1F\x7F]/u', '', $value) ?? '';
                $data[$key] = trim($value);
            } elseif (is_array($value)) {
                $data[$key] = self::clean($value);
            }
        }

        return $data;
    }
}
