<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Таблица маршрутов и диспетчер. Здесь же в одном месте проверяются CSRF-токен,
 * вход в аккаунт и роль администратора.
 */
class Router
{
    /** @var array<int, array{method: string, regex: string, handler: array{0: class-string, 1: string}, auth: bool, admin: bool}> */
    private array $routes = [];

    /**
     * Регистрирует GET-маршрут.
     *
     * @param string                          $path    Путь, например /api/publications/{id} ({id} — только цифры)
     * @param array{0: class-string, 1: string} $handler [класс контроллера, метод]
     * @param bool                            $auth    Нужен вход в аккаунт
     * @param bool                            $admin   Нужна роль администратора
     * @return void
     */
    public function get(string $path, array $handler, bool $auth = false, bool $admin = false): void
    {
        $this->add('GET', $path, $handler, $auth, $admin);
    }

    /**
     * Регистрирует POST-маршрут. Параметры те же, что у get().
     *
     * @param string                          $path
     * @param array{0: class-string, 1: string} $handler
     * @param bool                            $auth
     * @param bool                            $admin
     * @return void
     */
    public function post(string $path, array $handler, bool $auth = false, bool $admin = false): void
    {
        $this->add('POST', $path, $handler, $auth, $admin);
    }

    /**
     * Регистрирует PUT-маршрут. Параметры те же, что у get().
     *
     * @param string                          $path
     * @param array{0: class-string, 1: string} $handler
     * @param bool                            $auth
     * @param bool                            $admin
     * @return void
     */
    public function put(string $path, array $handler, bool $auth = false, bool $admin = false): void
    {
        $this->add('PUT', $path, $handler, $auth, $admin);
    }

    /**
     * Регистрирует PATCH-маршрут. Параметры те же, что у get().
     *
     * @param string                          $path
     * @param array{0: class-string, 1: string} $handler
     * @param bool                            $auth
     * @param bool                            $admin
     * @return void
     */
    public function patch(string $path, array $handler, bool $auth = false, bool $admin = false): void
    {
        $this->add('PATCH', $path, $handler, $auth, $admin);
    }

    /**
     * Регистрирует DELETE-маршрут. Параметры те же, что у get().
     *
     * @param string                          $path
     * @param array{0: class-string, 1: string} $handler
     * @param bool                            $auth
     * @param bool                            $admin
     * @return void
     */
    public function delete(string $path, array $handler, bool $auth = false, bool $admin = false): void
    {
        $this->add('DELETE', $path, $handler, $auth, $admin);
    }

    /**
     * Находит маршрут, выполняет проверки доступа и вызывает контроллер.
     * Неизвестный путь даёт 404, известный путь с другим методом — 405.
     * Для всех методов, кроме GET, проверяется CSRF-токен.
     *
     * @param string $method HTTP-метод
     * @param string $path   Путь запроса без query-строки
     * @return void
     */
    public function dispatch(string $method, string $path): void
    {
        $allowedMethods = [];

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }

            if ($route['method'] !== $method) {
                $allowedMethods[] = $route['method'];
                continue;
            }

            $params = array_map('intval', array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY));
            $this->checkAccess($method, $route['auth'], $route['admin']);

            [$controllerClass, $action] = $route['handler'];
            (new $controllerClass())->$action($params);

            return;
        }

        if ($allowedMethods !== []) {
            header('Allow: ' . implode(', ', array_unique($allowedMethods)));
            Response::error('Метод не поддерживается', 405);
        }

        Response::error('Не найдено', 404);
    }

    /**
     * Проверяет CSRF-токен и права доступа к маршруту.
     *
     * @param string $method HTTP-метод
     * @param bool   $auth   Нужен вход в аккаунт
     * @param bool   $admin  Нужна роль администратора
     * @return void
     */
    private function checkAccess(string $method, bool $auth, bool $admin): void
    {
        if ($method !== 'GET') {
            Auth::verifyCsrf();
        }

        if (!$auth && !$admin) {
            return;
        }

        $user = Auth::user();

        if ($user === null) {
            Response::error('Войдите в аккаунт', 401);
        }

        if ($admin && !$user['admin']) {
            Response::error('Недостаточно прав', 403);
        }
    }

    /**
     * Добавляет маршрут в таблицу, превращая {имя} в регулярное выражение для цифр.
     *
     * @param string                          $method  HTTP-метод
     * @param string                          $path    Путь с параметрами {имя}
     * @param array{0: class-string, 1: string} $handler Контроллер и метод
     * @param bool                            $auth    Нужен вход
     * @param bool                            $admin   Нужна роль администратора
     * @return void
     */
    private function add(string $method, string $path, array $handler, bool $auth, bool $admin): void
    {
        $pattern = preg_replace('#\{(\w+)\}#', '(?P<$1>\d+)', $path);

        $this->routes[] = [
            'method' => $method,
            'regex' => '#^' . $pattern . '$#',
            'handler' => $handler,
            'auth' => $auth,
            'admin' => $admin,
        ];
    }
}
