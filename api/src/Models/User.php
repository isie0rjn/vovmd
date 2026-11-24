<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Пользователи (таблица users) и токены восстановления пароля (password_reset).
 */
class User
{
    /**
     * Находит пользователя по id.
     *
     * @param int $id Идентификатор
     * @return array<string, mixed>|null Публичные данные пользователя или null
     */
    public static function findById(int $id): ?array
    {
        $row = Database::query('SELECT * FROM users WHERE id = ?', [$id])->fetch();

        return $row === false ? null : self::format($row);
    }

    /**
     * Находит пользователя для входа по username или email.
     *
     * @param string $login Имя пользователя или email
     * @return array<string, mixed>|null Строка БД вместе с password_hash или null
     */
    public static function findByLogin(string $login): ?array
    {
        $row = Database::query(
            'SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1',
            [$login, mb_strtolower($login)]
        )->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Находит активного пользователя по email.
     *
     * @param string $email Адрес электронной почты
     * @return array<string, mixed>|null Публичные данные пользователя или null
     */
    public static function findActiveByEmail(string $email): ?array
    {
        $row = Database::query(
            'SELECT * FROM users WHERE email = ? AND active = 1',
            [mb_strtolower($email)]
        )->fetch();

        return $row === false ? null : self::format($row);
    }

    /**
     * Занято ли имя пользователя (без учёта регистра).
     *
     * @param string $username Имя пользователя
     * @return bool
     */
    public static function usernameExists(string $username): bool
    {
        return Database::query('SELECT 1 FROM users WHERE username = ?', [$username])->fetch() !== false;
    }

    /**
     * Зарегистрирован ли email (без учёта регистра).
     *
     * @param string $email Адрес электронной почты
     * @return bool
     */
    public static function emailExists(string $email): bool
    {
        return Database::query('SELECT 1 FROM users WHERE email = ?', [mb_strtolower($email)])->fetch() !== false;
    }

    /**
     * Создаёт пользователя. Пароль сохраняется только в виде хеша, email — в нижнем регистре.
     *
     * @param string $username Имя пользователя
     * @param string $email    Адрес электронной почты
     * @param string $password Пароль в открытом виде (будет захеширован)
     * @param bool   $isAdmin  Выдать роль администратора
     * @return int Идентификатор нового пользователя
     */
    public static function create(string $username, string $email, string $password, bool $isAdmin = false): int
    {
        Database::query(
            'INSERT INTO users (username, email, password_hash, admin) VALUES (?, ?, ?, ?)',
            [$username, mb_strtolower($email), password_hash($password, PASSWORD_DEFAULT), $isAdmin]
        );

        return (int) Database::get()->lastInsertId();
    }

    /**
     * Меняет флаги active и/или admin. Неуказанные флаги остаются прежними.
     *
     * @param int       $id     Идентификатор пользователя
     * @param bool|null $active Новое значение active или null, если не менять
     * @param bool|null $admin  Новое значение admin или null, если не менять
     * @return void
     */
    public static function updateFlags(int $id, ?bool $active, ?bool $admin): void
    {
        Database::query(
            'UPDATE users SET active = COALESCE(?, active), admin = COALESCE(?, admin) WHERE id = ?',
            [$active, $admin, $id]
        );
    }

    /**
     * Список пользователей для админки с числом публикаций.
     *
     * @param string|null $search Часть username или email
     * @param int         $limit  Сколько записей вернуть
     * @param int         $offset Сколько записей пропустить
     * @return array<int, array<string, mixed>>
     */
    public static function search(?string $search, int $limit, int $offset): array
    {
        $rows = Database::query(
            'SELECT u.*, (SELECT COUNT(*) FROM publication p WHERE p.user_id = u.id) AS publications_count
             FROM users u
             WHERE (? IS NULL OR u.username LIKE ? OR u.email LIKE ?)
             ORDER BY u.id
             LIMIT ? OFFSET ?',
            [$search, self::likePattern($search), self::likePattern($search), $limit, $offset]
        )->fetchAll();

        return array_map(
            fn (array $row): array => self::format($row) + ['publications_count' => (int) $row['publications_count']],
            $rows
        );
    }

    /**
     * Сколько пользователей подходит под поиск (для пагинации).
     *
     * @param string|null $search Часть username или email
     * @return int
     */
    public static function countSearch(?string $search): int
    {
        return (int) Database::query(
            'SELECT COUNT(*) FROM users u WHERE (? IS NULL OR u.username LIKE ? OR u.email LIKE ?)',
            [$search, self::likePattern($search), self::likePattern($search)]
        )->fetchColumn();
    }

    /**
     * Создаёт токен сброса пароля. Прежние неиспользованные токены пользователя гасятся.
     * В БД хранится только хеш токена, срок действия — 60 минут.
     *
     * @param int    $userId    Идентификатор пользователя
     * @param string $tokenHash SHA-256 от токена
     * @return void
     */
    public static function createResetToken(int $userId, string $tokenHash): void
    {
        Database::query('UPDATE password_reset SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$userId]);
        Database::query(
            'INSERT INTO password_reset (user_id, token_hash, expires_at)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 60 MINUTE))',
            [$userId, $tokenHash]
        );
    }

    /**
     * Меняет пароль по токену сброса и помечает токен использованным (одной транзакцией).
     *
     * @param string $tokenHash   SHA-256 от токена из письма
     * @param string $newPassword Новый пароль в открытом виде (будет захеширован)
     * @return bool false, если токен не найден, уже использован, просрочен или аккаунт заблокирован
     * @throws \PDOException При ошибке SQL
     */
    public static function resetPassword(string $tokenHash, string $newPassword): bool
    {
        $pdo = Database::get();
        $pdo->beginTransaction();

        $reset = Database::query(
            'SELECT r.id, r.user_id FROM password_reset r
             JOIN users u ON u.id = r.user_id
             WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > NOW() AND u.active = 1
             FOR UPDATE',
            [$tokenHash]
        )->fetch();

        if ($reset === false) {
            $pdo->rollBack();
            return false;
        }

        Database::query(
            'UPDATE users SET password_hash = ? WHERE id = ?',
            [password_hash($newPassword, PASSWORD_DEFAULT), $reset['user_id']]
        );
        Database::query('UPDATE password_reset SET used_at = NOW() WHERE id = ?', [$reset['id']]);
        $pdo->commit();

        return true;
    }

    /**
     * Приводит строку БД к публичному виду: без хеша пароля, с настоящими числами и булевыми значениями.
     *
     * @param array<string, mixed> $row Строка таблицы users
     * @return array<string, mixed>
     */
    private static function format(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'username' => $row['username'],
            'email' => $row['email'],
            'admin' => (bool) $row['admin'],
            'active' => (bool) $row['active'],
        ];
    }

    /**
     * Готовит шаблон для LIKE: экранирует % и _ и добавляет % по краям.
     *
     * @param string|null $search Строка поиска
     * @return string|null Шаблон или null, если поиск не задан
     */
    private static function likePattern(?string $search): ?string
    {
        return $search === null ? null : '%' . addcslashes($search, '%_\\') . '%';
    }
}
