<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\Category;
use App\Models\User;

/**
 * Функции администратора: пользователи и разделы форума.
 * Доступ только для роли admin проверяется в Router.
 */
class AdminController
{
    /**
     * GET /api/admin/users — список пользователей с поиском и пагинацией.
     *
     * @param array<string, int> $params Параметры пути
     * @return void
     */
    public function users(array $params): void
    {
        $query = Request::query();

        $validator = (new Validator($query))->length('q', 1, 100)->pagination();
        if ($validator->fails()) {
            Response::validationError($validator->errors());
        }

        $search = ($query['q'] ?? '') === '' ? null : $query['q'];
        $page = (int) ($query['page'] ?? 1);
        $perPage = (int) ($query['per_page'] ?? 10);

        Response::json([
            'items' => User::search($search, $perPage, ($page - 1) * $perPage),
            'page' => $page,
            'per_page' => $perPage,
            'total' => User::countSearch($search),
        ]);
    }

    /**
     * POST /api/admin/users — создание пользователя или администратора.
     *
     * @param array<string, int> $params Параметры пути
     * @return void
     */
    public function createUser(array $params): void
    {
        $data = Request::body();

        $validator = (new Validator($data))->newAccount()->boolean('admin');
        if ($validator->fails()) {
            Response::validationError($validator->errors());
        }

        $userId = User::create($data['username'], $data['email'], $data['password'], $data['admin'] ?? false);

        Response::json(['user' => User::findById($userId)], 201);
    }

    /**
     * PATCH /api/admin/users/{id} — блокировка/разблокировка и выдача/снятие роли администратора.
     *
     * @param array<string, int> $params Параметры пути (id пользователя)
     * @return void
     */
    public function updateUser(array $params): void
    {
        $data = Request::body();

        $validator = (new Validator($data))->boolean('active')->boolean('admin');
        if ($validator->fails()) {
            Response::validationError($validator->errors());
        }
        if (!isset($data['active']) && !isset($data['admin'])) {
            Response::validationError(['active' => 'Укажите хотя бы одно поле: active или admin']);
        }

        if ($params['id'] === Auth::user()['id']) {
            Response::error('Нельзя изменить свою учётную запись', 422);
        }
        if (User::findById($params['id']) === null) {
            Response::error('Пользователь не найден', 404);
        }

        User::updateFlags($params['id'], $data['active'] ?? null, $data['admin'] ?? null);

        Response::json(['user' => User::findById($params['id'])]);
    }

    /**
     * POST /api/admin/categories — создание раздела.
     *
     * @param array<string, int> $params Параметры пути
     * @return void
     */
    public function createCategory(array $params): void
    {
        $data = Request::body();

        $this->validateCategoryName($data, null);

        $categoryId = Category::create($data['name']);

        Response::json(['category' => Category::findById($categoryId)], 201);
    }

    /**
     * PUT /api/admin/categories/{id} — переименование раздела.
     *
     * @param array<string, int> $params Параметры пути (id раздела)
     * @return void
     */
    public function updateCategory(array $params): void
    {
        $data = Request::body();

        $this->validateCategoryName($data, $params['id']);
        if (Category::findById($params['id']) === null) {
            Response::error('Раздел не найден', 404);
        }

        Category::rename($params['id'], $data['name']);

        Response::json(['category' => Category::findById($params['id'])]);
    }

    /**
     * DELETE /api/admin/categories/{id} — удаление раздела без публикаций.
     *
     * @param array<string, int> $params Параметры пути (id раздела)
     * @return void
     */
    public function deleteCategory(array $params): void
    {
        if (Category::findById($params['id']) === null) {
            Response::error('Раздел не найден', 404);
        }
        if (Category::hasPublications($params['id'])) {
            Response::error('В разделе есть публикации', 409);
        }

        Category::delete($params['id']);

        Response::json(['message' => 'Раздел удалён']);
    }

    /**
     * Проверяет название раздела: длина 2–100 и уникальность без учёта регистра.
     * При ошибках отвечает 422.
     *
     * @param array<string, mixed> $data     Тело запроса
     * @param int|null             $exceptId Раздел, который переименовывается (его текущее имя не считается дублем)
     * @return void
     */
    private function validateCategoryName(array $data, ?int $exceptId): void
    {
        $validator = (new Validator($data))
            ->required('name')
            ->length('name', 2, 100)
            ->check('name', fn (string $name): bool => !Category::nameExists($name, $exceptId), 'Раздел с таким названием уже есть');

        if ($validator->fails()) {
            Response::validationError($validator->errors());
        }
    }
}
