<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Публикации (таблица publication), лайки (like), поиск и статистика.
 * Таблица `like` и колонка `text` совпадают с ключевыми словами SQL, поэтому всегда в обратных кавычках.
 */
class Publication
{
    private const EXCERPT_LENGTH = 300;

    private const SELECT = "SELECT p.id, p.title, p.`text`, p.type, p.allow_comments,
            DATE_FORMAT(p.created_at, '%Y-%m-%dT%H:%i:%s+00:00') AS created_at,
            u.id AS author_id, u.username AS author_username,
            c.id AS category_id, c.name AS category_name,
            (SELECT COUNT(*) FROM `like` l WHERE l.publication_id = p.id) AS likes_count,
            (SELECT COUNT(*) FROM comment cm WHERE cm.publication_id = p.id AND cm.deleted = 0) AS comments_count,
            EXISTS(SELECT 1 FROM `like` lm WHERE lm.publication_id = p.id AND lm.user_id = ?) AS liked_by_me
        FROM publication p
        JOIN users u ON u.id = p.user_id
        JOIN category c ON c.id = p.category_id";

    /**
     * Лента или результаты поиска с пагинацией.
     *
     * @param array<string, mixed> $filters  Необязательные фильтры: q, category_id, type, author, date_from, date_to
     * @param string               $sort     latest — новые сверху; popular — по числу лайков; relevance — по релевантности q
     * @param int                  $viewerId Кто смотрит (для liked_by_me), 0 — гость
     * @param int                  $limit    Сколько записей вернуть
     * @param int                  $offset   Сколько записей пропустить
     * @return array<int, array<string, mixed>> Публикации в «списочном» формате (с excerpt)
     */
    public static function search(array $filters, string $sort, int $viewerId, int $limit, int $offset): array
    {
        [$where, $params] = self::buildWhere($filters);
        $orderBy = 'p.created_at DESC, p.id DESC';
        $orderParams = [];

        if ($sort === 'popular') {
            $orderBy = 'likes_count DESC, ' . $orderBy;
        } elseif ($sort === 'relevance' && isset($filters['q'])) {
            $orderBy = 'MATCH(p.title, p.`text`) AGAINST (? IN NATURAL LANGUAGE MODE) DESC, ' . $orderBy;
            $orderParams = [$filters['q']];
        }

        $rows = Database::query(
            self::SELECT . $where . ' ORDER BY ' . $orderBy . ' LIMIT ? OFFSET ?',
            [$viewerId, ...$params, ...$orderParams, $limit, $offset]
        )->fetchAll();

        return array_map(fn (array $row): array => self::formatListItem($row), $rows);
    }

    /**
     * Сколько публикаций подходит под фильтры (для пагинации).
     *
     * @param array<string, mixed> $filters Те же фильтры, что у search()
     * @return int
     */
    public static function count(array $filters): int
    {
        [$where, $params] = self::buildWhere($filters);

        return (int) Database::query(
            'SELECT COUNT(*) FROM publication p JOIN users u ON u.id = p.user_id' . $where,
            $params
        )->fetchColumn();
    }

    /**
     * Находит публикацию целиком вместе с неудалёнными комментариями.
     *
     * @param int $id       Идентификатор публикации
     * @param int $viewerId Кто смотрит (для liked_by_me), 0 — гость
     * @return array<string, mixed>|null Публикация с полями text и comments или null
     */
    public static function findById(int $id, int $viewerId): ?array
    {
        $row = Database::query(self::SELECT . ' WHERE p.id = ?', [$viewerId, $id])->fetch();

        if ($row === false) {
            return null;
        }

        return self::formatListItem($row) + [
            'text' => $row['text'],
            'comments' => Comment::listForPublication($id),
        ];
    }

    /**
     * Краткие данные публикации для проверок прав и доступности комментариев.
     *
     * @param int $id Идентификатор публикации
     * @return array{id: int, user_id: int, allow_comments: bool}|null
     */
    public static function findBasic(int $id): ?array
    {
        $row = Database::query('SELECT id, user_id, allow_comments FROM publication WHERE id = ?', [$id])->fetch();

        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'allow_comments' => (bool) $row['allow_comments'],
        ];
    }

    /**
     * Создаёт публикацию (поле image остаётся NULL).
     *
     * @param int                  $userId Автор
     * @param array<string, mixed> $data   title, text, category_id, type, allow_comments
     * @return int Идентификатор новой публикации
     */
    public static function create(int $userId, array $data): int
    {
        Database::query(
            'INSERT INTO publication (user_id, title, `text`, category_id, type, allow_comments)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, $data['title'], $data['text'], $data['category_id'], $data['type'], $data['allow_comments']]
        );

        return (int) Database::get()->lastInsertId();
    }

    /**
     * Обновляет поля публикации.
     *
     * @param int                  $id   Идентификатор публикации
     * @param array<string, mixed> $data title, text, category_id, type, allow_comments
     * @return void
     */
    public static function update(int $id, array $data): void
    {
        Database::query(
            'UPDATE publication SET title = ?, `text` = ?, category_id = ?, type = ?, allow_comments = ? WHERE id = ?',
            [$data['title'], $data['text'], $data['category_id'], $data['type'], $data['allow_comments'], $id]
        );
    }

    /**
     * Удаляет публикацию. Комментарии и лайки удаляются каскадом на уровне БД.
     *
     * @param int $id Идентификатор публикации
     * @return void
     */
    public static function delete(int $id): void
    {
        Database::query('DELETE FROM publication WHERE id = ?', [$id]);
    }

    /**
     * Ставит лайк, а если он уже есть — снимает.
     *
     * @param int $publicationId Идентификатор публикации
     * @param int $userId        Кто лайкает
     * @return array{liked: bool, likes_count: int}
     */
    public static function toggleLike(int $publicationId, int $userId): array
    {
        $removed = Database::query(
            'DELETE FROM `like` WHERE publication_id = ? AND user_id = ?',
            [$publicationId, $userId]
        )->rowCount();

        if ($removed === 0) {
            Database::query(
                'INSERT IGNORE INTO `like` (publication_id, user_id) VALUES (?, ?)',
                [$publicationId, $userId]
            );
        }

        $count = Database::query('SELECT COUNT(*) FROM `like` WHERE publication_id = ?', [$publicationId])->fetchColumn();

        return ['liked' => $removed === 0, 'likes_count' => (int) $count];
    }

    /**
     * Общая статистика форума: активные пользователи, публикации, неудалённые комментарии, лайки.
     *
     * @return array{users: int, publications: int, comments: int, likes: int}
     */
    public static function stats(): array
    {
        $row = Database::query(
            'SELECT (SELECT COUNT(*) FROM users WHERE active = 1) AS users,
                    (SELECT COUNT(*) FROM publication) AS publications,
                    (SELECT COUNT(*) FROM comment WHERE deleted = 0) AS comments,
                    (SELECT COUNT(*) FROM `like`) AS likes'
        )->fetch();

        return array_map('intval', $row);
    }

    /**
     * Собирает условие WHERE и параметры по заданным фильтрам.
     * Значения попадают только в параметры запроса, в текст SQL — только фиксированные фрагменты.
     *
     * @param array<string, mixed> $filters Фильтры (отсутствующие или null игнорируются)
     * @return array{0: string, 1: array<int, mixed>} Строка WHERE (с пробелом в начале) и параметры
     */
    private static function buildWhere(array $filters): array
    {
        $conditions = [];
        $params = [];

        if (isset($filters['q'])) {
            $conditions[] = 'MATCH(p.title, p.`text`) AGAINST (? IN NATURAL LANGUAGE MODE)';
            $params[] = $filters['q'];
        }
        if (isset($filters['category_id'])) {
            $conditions[] = 'p.category_id = ?';
            $params[] = (int) $filters['category_id'];
        }
        if (isset($filters['type'])) {
            $conditions[] = 'p.type = ?';
            $params[] = $filters['type'];
        }
        if (isset($filters['author'])) {
            $conditions[] = 'u.username = ?';
            $params[] = $filters['author'];
        }
        if (isset($filters['date_from'])) {
            $conditions[] = 'p.created_at >= ?';
            $params[] = $filters['date_from'];
        }
        if (isset($filters['date_to'])) {
            $conditions[] = 'p.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
            $params[] = $filters['date_to'];
        }

        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

        return [$where, $params];
    }

    /**
     * Приводит строку БД к «списочному» формату публикации.
     *
     * @param array<string, mixed> $row Результат запроса SELECT
     * @return array<string, mixed>
     */
    private static function formatListItem(array $row): array
    {
        $text = (string) $row['text'];
        $excerpt = mb_strlen($text) > self::EXCERPT_LENGTH
            ? mb_substr($text, 0, self::EXCERPT_LENGTH) . '…'
            : $text;

        return [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'excerpt' => $excerpt,
            'type' => $row['type'],
            'allow_comments' => (bool) $row['allow_comments'],
            'created_at' => $row['created_at'],
            'author' => ['id' => (int) $row['author_id'], 'username' => $row['author_username']],
            'category' => ['id' => (int) $row['category_id'], 'name' => $row['category_name']],
            'likes_count' => (int) $row['likes_count'],
            'comments_count' => (int) $row['comments_count'],
            'liked_by_me' => (bool) $row['liked_by_me'],
        ];
    }
}
