<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Комментарии к публикациям (таблица comment). Удаление мягкое: deleted = 1.
 */
class Comment
{
    private const SELECT = "SELECT c.id, c.user_id, c.text, c.deleted,
            DATE_FORMAT(c.created_at, '%Y-%m-%dT%H:%i:%s+00:00') AS created_at,
            u.username AS author_username
        FROM comment c
        JOIN users u ON u.id = c.user_id";

    /**
     * Находит комментарий по id (в том числе удалённый).
     *
     * @param int $id Идентификатор
     * @return array<string, mixed>|null Комментарий с дополнительным флагом deleted или null
     */
    public static function findById(int $id): ?array
    {
        $row = Database::query(self::SELECT . ' WHERE c.id = ?', [$id])->fetch();

        return $row === false ? null : self::format($row) + ['deleted' => (bool) $row['deleted']];
    }

    /**
     * Неудалённые комментарии публикации, сначала старые.
     *
     * @param int $publicationId Идентификатор публикации
     * @return array<int, array<string, mixed>>
     */
    public static function listForPublication(int $publicationId): array
    {
        $rows = Database::query(
            self::SELECT . ' WHERE c.publication_id = ? AND c.deleted = 0 ORDER BY c.created_at, c.id',
            [$publicationId]
        )->fetchAll();

        return array_map(fn (array $row): array => self::format($row), $rows);
    }

    /**
     * Добавляет комментарий.
     *
     * @param int    $userId        Автор
     * @param int    $publicationId Публикация
     * @param string $text          Текст
     * @return int Идентификатор нового комментария
     */
    public static function create(int $userId, int $publicationId, string $text): int
    {
        Database::query(
            'INSERT INTO comment (user_id, publication_id, text) VALUES (?, ?, ?)',
            [$userId, $publicationId, $text]
        );

        return (int) Database::get()->lastInsertId();
    }

    /**
     * Помечает комментарий удалённым.
     *
     * @param int $id Идентификатор
     * @return void
     */
    public static function softDelete(int $id): void
    {
        Database::query('UPDATE comment SET deleted = 1 WHERE id = ?', [$id]);
    }

    /**
     * Приводит строку БД к виду для JSON.
     *
     * @param array<string, mixed> $row Результат запроса SELECT
     * @return array<string, mixed>
     */
    private static function format(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'text' => $row['text'],
            'created_at' => $row['created_at'],
            'author' => ['id' => (int) $row['user_id'], 'username' => $row['author_username']],
        ];
    }
}
