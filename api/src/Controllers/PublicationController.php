<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\Category;
use App\Models\Publication;

/**
 * Публикации: лента, просмотр, создание, правка, удаление, лайки, поиск, а также
 * публичные справочники (разделы, статистика, проверка состояния).
 */
class PublicationController
{
    private const TYPES = ['question', 'discussion', 'announcement'];

    /**
     * GET /api/health — проверка работы API и подключения к БД.
     *
     * @param array<string, int> $params Параметры пути
     * @return void
     */
    public function health(array $params): void
    {
        Database::query('SELECT 1');

        Response::json(['status' => 'ok', 'database' => 'connected']);
    }

    /**
     * GET /api/categories — все разделы по алфавиту.
     *
     * @param array<string, int> $params Параметры пути
     * @return void
     */
    public function categories(array $params): void
    {
        Response::json(['items' => Category::all()]);
    }

    /**
     * GET /api/stats — общая статистика форума.
     *
     * @param array<string, int> $params Параметры пути
     * @return void
     */
    public function stats(array $params): void
    {
        Response::json(Publication::stats());
    }

    /**
     * GET /api/publications — лента с сортировкой, фильтром по разделу и пагинацией.
     *
     * @param array<string, int> $params Параметры пути
     * @return void
     */
    public function index(array $params): void
    {
        $query = Request::query();

        $validator = (new Validator($query))
            ->in('sort', ['latest', 'popular'])
            ->integer('category_id')
            ->pagination();
        if ($validator->fails()) {
            Response::validationError($validator->errors());
        }

        $filters = ['category_id' => $query['category_id'] ?? null];
        $this->respondWithPage($filters, $query['sort'] ?? 'latest', $query);
    }

    /**
     * GET /api/search — поиск по тексту, разделу, типу, автору и датам.
     *
     * @param array<string, int> $params Параметры пути
     * @return void
     */
    public function search(array $params): void
    {
        $query = Request::query();

        $validator = (new Validator($query))
            ->length('q', 3, 100)
            ->integer('category_id')
            ->in('type', self::TYPES)
            ->length('author', 1, 255)
            ->date('date_from')
            ->date('date_to')
            ->notBefore('date_to', 'date_from', 'Конечная дата не может быть раньше начальной')
            ->pagination();
        if ($validator->fails()) {
            Response::validationError($validator->errors());
        }

        $filters = [];
        foreach (['q', 'category_id', 'type', 'author', 'date_from', 'date_to'] as $name) {
            $filters[$name] = ($query[$name] ?? '') === '' ? null : $query[$name];
        }

        $this->respondWithPage($filters, isset($filters['q']) ? 'relevance' : 'latest', $query);
    }

    /**
     * GET /api/publications/{id} — публикация целиком с комментариями.
     *
     * @param array<string, int> $params Параметры пути (id)
     * @return void
     */
    public function show(array $params): void
    {
        Response::json($this->loadFullPublication($params['id']));
    }

    /**
     * POST /api/publications — создание публикации.
     *
     * @param array<string, int> $params Параметры пути
     * @return void
     */
    public function store(array $params): void
    {
        $data = $this->readPublicationData();

        $publicationId = Publication::create(Auth::user()['id'], $data);

        Response::json($this->loadFullPublication($publicationId), 201);
    }

    /**
     * PUT /api/publications/{id} — изменение публикации автором или администратором.
     *
     * @param array<string, int> $params Параметры пути (id)
     * @return void
     */
    public function update(array $params): void
    {
        $data = $this->readPublicationData();

        $this->requireOwnPublication($params['id']);
        Publication::update($params['id'], $data);

        Response::json($this->loadFullPublication($params['id']));
    }

    /**
     * DELETE /api/publications/{id} — удаление публикации автором или администратором.
     *
     * @param array<string, int> $params Параметры пути (id)
     * @return void
     */
    public function destroy(array $params): void
    {
        $this->requireOwnPublication($params['id']);
        Publication::delete($params['id']);

        Response::json(['message' => 'Публикация удалена']);
    }

    /**
     * POST /api/publications/{id}/like — поставить или убрать лайк.
     *
     * @param array<string, int> $params Параметры пути (id)
     * @return void
     */
    public function like(array $params): void
    {
        if (Publication::findBasic($params['id']) === null) {
            Response::error('Публикация не найдена', 404);
        }

        Response::json(Publication::toggleLike($params['id'], Auth::user()['id']));
    }

    /**
     * Отправляет страницу публикаций в формате {items, page, per_page, total}.
     *
     * @param array<string, mixed> $filters Фильтры для Publication::search()
     * @param string               $sort    latest, popular или relevance
     * @param array<string, mixed> $query   Query-параметры (из них берутся page и per_page)
     * @return void
     */
    private function respondWithPage(array $filters, string $sort, array $query): void
    {
        $page = (int) ($query['page'] ?? 1);
        $perPage = (int) ($query['per_page'] ?? 10);
        $viewerId = Auth::user()['id'] ?? 0;

        Response::json([
            'items' => Publication::search($filters, $sort, $viewerId, $perPage, ($page - 1) * $perPage),
            'page' => $page,
            'per_page' => $perPage,
            'total' => Publication::count($filters),
        ]);
    }

    /**
     * Читает и проверяет поля формы публикации. При ошибках отвечает 422.
     *
     * @return array<string, mixed> title, text, category_id (int), type, allow_comments (bool)
     */
    private function readPublicationData(): array
    {
        $data = Request::body();

        $validator = (new Validator($data))
            ->required('title')
            ->length('title', 5, 255)
            ->required('text')
            ->length('text', 10, 10000)
            ->required('category_id')
            ->integer('category_id')
            ->check('category_id', fn ($id): bool => Category::findById((int) $id) !== null, 'Выберите существующий раздел')
            ->required('type')
            ->in('type', self::TYPES)
            ->required('allow_comments')
            ->boolean('allow_comments');
        if ($validator->fails()) {
            Response::validationError($validator->errors());
        }

        $data['category_id'] = (int) $data['category_id'];

        return $data;
    }

    /**
     * Проверяет, что публикация существует и текущий пользователь — её автор или администратор.
     *
     * @param int $publicationId Идентификатор публикации
     * @return void
     */
    private function requireOwnPublication(int $publicationId): void
    {
        $publication = Publication::findBasic($publicationId);

        if ($publication === null) {
            Response::error('Публикация не найдена', 404);
        }
        if (!Auth::canModify($publication['user_id'])) {
            Response::error('Недостаточно прав', 403);
        }
    }

    /**
     * Загружает публикацию целиком и добавляет флаги прав текущего пользователя.
     * Если публикации нет, отвечает 404.
     *
     * @param int $publicationId Идентификатор публикации
     * @return array<string, mixed>
     */
    private function loadFullPublication(int $publicationId): array
    {
        $publication = Publication::findById($publicationId, Auth::user()['id'] ?? 0);

        if ($publication === null) {
            Response::error('Публикация не найдена', 404);
        }

        $canModify = Auth::canModify($publication['author']['id']);
        $publication['can_edit'] = $canModify;
        $publication['can_delete'] = $canModify;

        foreach ($publication['comments'] as $index => $comment) {
            $publication['comments'][$index]['can_delete'] = Auth::canModify($comment['author']['id']);
        }

        return $publication;
    }
}
