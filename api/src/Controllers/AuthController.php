<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Mailer;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\User;

/**
 * Регистрация, вход, выход, текущий пользователь и восстановление пароля.
 */
class AuthController
{
    /** Хеш-пустышка: проверяется, когда пользователь не найден, чтобы время ответа не выдавало существование логина. */
    private const DUMMY_HASH = '$2y$12$6RRErF0NQEBvESNva/NYruT2/mvG4a8jpM6CvJPoWVqUlonybWQOK';

    /**
     * GET /api/auth/me — текущий пользователь (или null) и CSRF-токен.
     *
     * @param array<string, int> $params Параметры пути
     * @return void
     */
    public function me(array $params): void
    {
        Response::json(['user' => Auth::user(), 'csrf_token' => Auth::csrfToken()]);
    }

    /**
     * POST /api/auth/register — регистрация и немедленный вход.
     *
     * @param array<string, int> $params Параметры пути
     * @return void
     */
    public function register(array $params): void
    {
        $data = Request::body();

        $validator = (new Validator($data))
            ->newAccount()
            ->required('password_confirmation')
            ->same('password_confirmation', 'password', 'Пароли не совпадают');
        if ($validator->fails()) {
            Response::validationError($validator->errors());
        }

        $userId = User::create($data['username'], $data['email'], $data['password']);
        Auth::login($userId);

        Response::json(['user' => Auth::user()], 201);
    }

    /**
     * POST /api/auth/login — вход по username или email.
     *
     * @param array<string, int> $params Параметры пути
     * @return void
     */
    public function login(array $params): void
    {
        $data = Request::body();

        $validator = (new Validator($data))
            ->required('login')->length('login', 1, 255)
            ->required('password')->length('password', 1, 1000);
        if ($validator->fails()) {
            Response::validationError($validator->errors());
        }

        $user = User::findByLogin((string) $data['login']);
        $hash = $user['password_hash'] ?? self::DUMMY_HASH;
        $passwordIsCorrect = password_verify($data['password'], $hash);

        if ($user === null || !$passwordIsCorrect) {
            Response::error('Неверный логин или пароль', 401);
        }
        if (!(bool) $user['active']) {
            Response::error('Аккаунт заблокирован', 403);
        }

        Auth::login((int) $user['id']);

        Response::json(['user' => Auth::user()]);
    }

    /**
     * POST /api/auth/logout — выход из аккаунта.
     *
     * @param array<string, int> $params Параметры пути
     * @return void
     */
    public function logout(array $params): void
    {
        Auth::logout();

        Response::json(['message' => 'Вы вышли из аккаунта']);
    }

    /**
     * POST /api/auth/forgot-password — отправляет письмо со ссылкой для сброса пароля.
     * Ответ всегда одинаковый, чтобы нельзя было узнать, какие email зарегистрированы.
     *
     * @param array<string, int> $params Параметры пути
     * @return void
     */
    public function forgotPassword(array $params): void
    {
        $data = Request::body();

        $validator = (new Validator($data))->required('email')->email('email');
        if ($validator->fails()) {
            Response::validationError($validator->errors());
        }

        $user = User::findActiveByEmail($data['email']);
        if ($user !== null) {
            $this->sendResetLink($user);
        }

        Response::json([
            'message' => 'Если такой email зарегистрирован, мы отправили на него ссылку для сброса пароля',
        ]);
    }

    /**
     * POST /api/auth/reset-password — устанавливает новый пароль по токену из письма.
     *
     * @param array<string, int> $params Параметры пути
     * @return void
     */
    public function resetPassword(array $params): void
    {
        $data = Request::body();

        $validator = (new Validator($data))
            ->required('token')
            ->length('token', 1, 255)
            ->required('password')
            ->password('password')
            ->required('password_confirmation')
            ->same('password_confirmation', 'password', 'Пароли не совпадают');
        if ($validator->fails()) {
            Response::validationError($validator->errors());
        }

        $tokenHash = hash('sha256', (string) $data['token']);
        if (!User::resetPassword($tokenHash, $data['password'])) {
            Response::validationError(['token' => 'Ссылка недействительна или устарела']);
        }

        Response::json(['message' => 'Пароль изменён, теперь можно войти']);
    }

    /**
     * Создаёт токен сброса и отправляет письмо. Ошибка отправки только пишется в лог.
     *
     * @param array<string, mixed> $user Пользователь (id, email)
     * @return void
     */
    private function sendResetLink(array $user): void
    {
        $token = bin2hex(random_bytes(32));
        User::createResetToken($user['id'], hash('sha256', $token));

        $link = rtrim(getenv('APP_URL') ?: 'http://localhost:5173', '/') . '/reset-password?token=' . $token;
        $body = "Здравствуйте, {$user['username']}!\n\n"
            . "Чтобы задать новый пароль, перейдите по ссылке (действует 60 минут):\n{$link}\n\n"
            . "Если вы не запрашивали сброс пароля, просто проигнорируйте это письмо.\n";

        try {
            Mailer::send($user['email'], 'Восстановление пароля на форуме', $body);
        } catch (\Throwable $error) {
            error_log('Не удалось отправить письмо сброса пароля: ' . $error->getMessage());
        }
    }
}
