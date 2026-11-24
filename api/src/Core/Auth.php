<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\User;

/**
 * Работа с сессией: старт, текущий пользователь, вход, выход и CSRF-токен.
 * В $_SESSION хранятся только user_id и csrf_token.
 */
class Auth
{
    private static bool $userLoaded = false;

    /** @var array<string, mixed>|null */
    private static ?array $user = null;

    /**
     * Запускает сессию с безопасными параметрами cookie.
     * Флаг Secure включается только для HTTPS (в том числе за Cloudflare по X-Forwarded-Proto).
     *
     * @return void
     */
    public static function start(): void
    {
        $isHttps = ($_SERVER['HTTPS'] ?? 'off') !== 'off'
            || strtolower(Request::header('X-Forwarded-Proto')) === 'https';

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cache_limiter', '');

        session_name('forum_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    /**
     * Возвращает вошедшего пользователя. Данные берутся из БД на каждом запросе, поэтому
     * блокировка администратором действует сразу: у заблокированного сессия очищается.
     *
     * @return array<string, mixed>|null Пользователь или null для гостя
     */
    public static function user(): ?array
    {
        if (self::$userLoaded) {
            return self::$user;
        }

        self::$userLoaded = true;

        if (isset($_SESSION['user_id'])) {
            $user = User::findById((int) $_SESSION['user_id']);

            if ($user === null || !$user['active']) {
                unset($_SESSION['user_id']);
            } else {
                self::$user = $user;
            }
        }

        return self::$user;
    }

    /**
     * Выполняет вход: меняет идентификатор сессии (защита от фиксации сессии) и запоминает пользователя.
     *
     * @param int $userId Идентификатор пользователя
     * @return void
     */
    public static function login(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        self::$user = User::findById($userId);
        self::$userLoaded = true;
    }

    /**
     * Выполняет выход: очищает сессию, удаляет cookie и уничтожает сессию на сервере.
     *
     * @return void
     */
    public static function logout(): void
    {
        $_SESSION = [];

        $cookie = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $cookie['path'],
            'secure' => $cookie['secure'],
            'httponly' => $cookie['httponly'],
            'samesite' => $cookie['samesite'],
        ]);

        session_destroy();
        self::$user = null;
        self::$userLoaded = true;
    }

    /**
     * Возвращает CSRF-токен сессии, создавая его при первом обращении.
     *
     * @return string Токен из 64 hex-символов
     */
    public static function csrfToken(): string
    {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    /**
     * Проверяет заголовок X-CSRF-Token. При несовпадении отвечает 403 и завершает работу.
     *
     * @return void
     */
    public static function verifyCsrf(): void
    {
        $expected = (string) ($_SESSION['csrf_token'] ?? '');
        $received = Request::header('X-CSRF-Token');

        if ($expected === '' || !hash_equals($expected, $received)) {
            Response::error('Сессия устарела, обновите страницу', 403);
        }
    }

    /**
     * Может ли текущий пользователь менять объект, принадлежащий автору: сам автор или администратор.
     *
     * @param int $authorId Идентификатор автора
     * @return bool
     */
    public static function canModify(int $authorId): bool
    {
        $user = self::user();

        return $user !== null && ($user['admin'] || $user['id'] === $authorId);
    }
}
