<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Разделы форума (таблица category).
 */
class Category
{
    /**
     * Все разделы по алфавиту.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public static function all(): array
    {
        $rows = Database::query('SELECT id, name FROM category ORDER BY name')->fetchAll();

        return array_map(fn (array $row): array => self::format($row), $rows);
    }

    /**
     * Находит раздел по id.
     *
     * @param int $id Идентификатор
     * @return array{id: int, name: string}|null
     */
    public static function findById(int $id): ?array
    {
        $row = Database::query('SELECT id, name FROM category WHERE id = ?', [$id])->fetch();

        return $row === false ? null : self::format($row);
    }

    /**
     * Есть ли раздел с таким названием (без учёта регистра).
     *
     * @param string   $name     Название
     * @param int|null $exceptId Не учитывать раздел с этим id (при переименовании)
     * @return bool
     */
    public static function nameExists(string $name, ?int $exceptId = null): bool
    {
        return Database::query(
            'SELECT 1 FROM category WHERE name = ? AND id <> ?',
            [$name, $exceptId ?? 0]
        )->fetch() !== false;
    }

    /**
     * Создаёт раздел.
     *
     * @param string $name Название
     * @return int Идентификатор нового раздела
     */
    public static function create(string $name): int
    {
        Database::query('INSERT INTO category (name) VALUES (?)', [$name]);

        return (int) Database::get()->lastInsertId();
    }

    /**
     * Переименовывает раздел.
     *
     * @param int    $id   Идентификатор
     * @param string $name Новое название
     * @return void
     */
    public static function rename(int $id, string $name): void
    {
        Database::query('UPDATE category SET name = ? WHERE id = ?', [$name, $id]);
    }

    /**
     * Есть ли в разделе публикации.
     *
     * @param int $id Идентификатор раздела
     * @return bool
     */
    public static function hasPublications(int $id): bool
    {
        return Database::query('SELECT 1 FROM publication WHERE category_id = ? LIMIT 1', [$id])->fetch() !== false;
    }

    /**
     * Удаляет раздел.
     *
     * @param int $id Идентификатор
     * @return void
     */
    public static function delete(int $id): void
    {
        Database::query('DELETE FROM category WHERE id = ?', [$id]);
    }

    /**
     * Приводит строку БД к виду для JSON.
     *
     * @param array<string, mixed> $row Строка таблицы category
     * @return array{id: int, name: string}
     */
    private static function format(array $row): array
    {
        return ['id' => (int) $row['id'], 'name' => $row['name']];
    }
}
