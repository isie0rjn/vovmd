<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Одно PDO-подключение к MariaDB на время обработки запроса.
 */
class Database
{
    private static ?PDO $connection = null;

    /**
     * Возвращает общее подключение, создавая его при первом обращении.
     * Параметры берутся из переменных окружения DB_HOST, DB_NAME, DB_USER, DB_PASS.
     *
     * @return PDO Подключение с исключениями, fetch-режимом FETCH_ASSOC и настоящими подготовленными выражениями
     * @throws \PDOException Если подключиться не удалось
     */
    public static function get(): PDO
    {
        if (self::$connection === null) {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=utf8mb4',
                getenv('DB_HOST'),
                getenv('DB_NAME')
            );

            self::$connection = new PDO($dsn, (string) getenv('DB_USER'), (string) getenv('DB_PASS'), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }

        return self::$connection;
    }

    /**
     * Выполняет подготовленный запрос с позиционными параметрами `?`.
     * Типы параметров определяются автоматически: int и bool передаются как целые числа,
     * поэтому LIMIT и OFFSET работают без подстановки значений в текст запроса.
     *
     * @param string            $sql    SQL с плейсхолдерами `?`
     * @param array<int, mixed> $params Значения параметров по порядку
     * @return \PDOStatement Выполненный запрос
     * @throws \PDOException При ошибке SQL
     */
    public static function query(string $sql, array $params = []): \PDOStatement
    {
        $statement = self::get()->prepare($sql);

        foreach (array_values($params) as $index => $value) {
            $type = match (true) {
                is_int($value), is_bool($value) => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $statement->bindValue($index + 1, is_bool($value) ? (int) $value : $value, $type);
        }

        $statement->execute();

        return $statement;
    }
}
