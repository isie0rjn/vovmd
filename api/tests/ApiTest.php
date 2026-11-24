<?php

declare(strict_types=1);

namespace Tests;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Интеграционные тесты REST API. Тесты отправляют настоящие HTTP-запросы к запущенному серверу
 * (адрес берётся из TEST_BASE_URL, по умолчанию http://localhost) и проверяют ответы и содержимое БД.
 *
 * Все данные, созданные тестами, имеют префикс test_<случайный суффикс> и удаляются в tearDownAfterClass.
 */
final class ApiTest extends TestCase
{
    private const PASSWORD = 'Passw0rd123';
    private const ADMIN_PASSWORD = 'AdminPass123';

    private static PDO $pdo;
    private static string $prefix;
    private static string $baseUrl;

    /** @var array<string, string> Имя клиента => путь к файлу с cookie */
    private static array $cookieJars = [];

    /** @var array{id: int, username: string, email: string} */
    private static array $userA;
    /** @var array{id: int, username: string, email: string} */
    private static array $userB;
    /** @var array{id: int, username: string, email: string} */
    private static array $admin;

    /** @var array<int, int> Идентификаторы стартовых разделов */
    private static array $seedCategoryIds;

    /**
     * Готовит данные: администратора (напрямую в БД), двух обычных пользователей (через API)
     * и список стартовых разделов.
     */
    public static function setUpBeforeClass(): void
    {
        self::$baseUrl = rtrim((string) (getenv('TEST_BASE_URL') ?: 'http://localhost'), '/');
        self::$prefix = 'test_' . bin2hex(random_bytes(3));

        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST'), getenv('DB_NAME'));
        self::$pdo = new PDO($dsn, (string) getenv('DB_USER'), (string) getenv('DB_PASS'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $adminName = self::$prefix . '_admin';
        self::$pdo->prepare('INSERT INTO users (username, email, password_hash, admin) VALUES (?, ?, ?, 1)')
            ->execute([$adminName, $adminName . '@example.com', password_hash(self::ADMIN_PASSWORD, PASSWORD_DEFAULT)]);
        self::$admin = ['id' => (int) self::$pdo->lastInsertId(), 'username' => $adminName, 'email' => $adminName . '@example.com'];
        self::login('admin', $adminName, self::ADMIN_PASSWORD);

        self::$userA = self::registerUser('a', 'alice');
        self::$userB = self::registerUser('b', 'bob');

        $categories = self::call('guest', 'GET', '/api/categories')['body']['items'];
        self::$seedCategoryIds = array_column($categories, 'id');
    }

    /**
     * Удаляет всё, что создали тесты: комментарии и лайки, публикации, разделы, пользователей.
     * Чужие данные не затрагиваются: отбор идёт по префиксу test_<суффикс>.
     */
    public static function tearDownAfterClass(): void
    {
        $like = str_replace('_', '\_', self::$prefix) . '%';
        $testUsers = 'SELECT id FROM (SELECT id FROM users WHERE username LIKE ?) AS t';

        foreach (
            [
                "DELETE FROM comment WHERE user_id IN ($testUsers)",
                "DELETE FROM `like` WHERE user_id IN ($testUsers)",
                "DELETE FROM publication WHERE user_id IN ($testUsers)",
                'DELETE FROM category WHERE name LIKE ?',
                'DELETE FROM users WHERE username LIKE ?',
            ] as $sql
        ) {
            self::$pdo->prepare($sql)->execute([$like]);
        }

        foreach (self::$cookieJars as $file) {
            @unlink($file);
        }
    }

    // ------------------------------------------------------------------ Общее

    /**
     * Неизвестный путь возвращает 404 в формате JSON.
     */
    public function testUnknownRouteReturns404Json(): void
    {
        $response = self::call('guest', 'GET', '/api/no-such-route');

        $this->assertSame(404, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
    }

    /**
     * Известный путь с неподходящим методом возвращает 405.
     */
    public function testWrongMethodReturns405(): void
    {
        $response = self::call('guest', 'POST', '/api/categories', []);

        $this->assertSame(405, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
    }

    /**
     * Невалидный JSON в теле запроса возвращает 400.
     */
    public function testInvalidJsonReturns400(): void
    {
        $response = self::call('guest', 'POST', '/api/auth/login', null, ['rawBody' => '{"login": ']);

        $this->assertSame(400, $response['status']);
        $this->assertSame('Некорректный формат запроса', $response['body']['error']);
    }

    // -------------------------------------------------------------- Публичное

    /**
     * Список разделов содержит пять стартовых категорий.
     */
    public function testCategoriesContainSeedCategories(): void
    {
        $response = self::call('guest', 'GET', '/api/categories');
        $names = array_column($response['body']['items'], 'name');

        $this->assertSame(200, $response['status']);
        foreach (['Учёба', 'Экзамены и сессия', 'Общежитие', 'Мероприятия', 'Разное'] as $expected) {
            $this->assertContains($expected, $names);
        }
    }

    /**
     * Статистика возвращает четыре числа.
     */
    public function testStatsReturnsNumbers(): void
    {
        $response = self::call('guest', 'GET', '/api/stats');

        $this->assertSame(200, $response['status']);
        foreach (['users', 'publications', 'comments', 'likes'] as $key) {
            $this->assertIsInt($response['body'][$key], $key);
        }
    }

    /**
     * Лента отдаёт структуру с пагинацией и поддерживает sort=popular.
     */
    public function testFeedReturnsPaginationAndSupportsPopularSort(): void
    {
        $publication = $this->createPublication('a');
        self::call('b', 'POST', '/api/publications/' . $publication['id'] . '/like');

        $latest = self::call('guest', 'GET', '/api/publications?per_page=5');
        $popular = self::call('guest', 'GET', '/api/publications?sort=popular&per_page=50');

        $this->assertSame(200, $latest['status']);
        $this->assertSame(1, $latest['body']['page']);
        $this->assertSame(5, $latest['body']['per_page']);
        $this->assertIsInt($latest['body']['total']);
        $this->assertLessThanOrEqual(5, count($latest['body']['items']));
        $this->assertArrayNotHasKey('email', $latest['body']['items'][0]['author']);

        $this->assertSame(200, $popular['status']);
        $likes = array_column($popular['body']['items'], 'likes_count');
        $sorted = $likes;
        rsort($sorted);
        $this->assertSame($sorted, $likes, 'Публикации должны идти по убыванию числа лайков');
    }

    /**
     * Неверные параметры пагинации дают 422.
     */
    public function testFeedRejectsInvalidPagination(): void
    {
        $response = self::call('guest', 'GET', '/api/publications?per_page=100&page=0');

        $this->assertSame(422, $response['status']);
        $this->assertArrayHasKey('per_page', $response['body']['errors']);
        $this->assertArrayHasKey('page', $response['body']['errors']);
    }

    /**
     * Несуществующая публикация возвращает 404.
     */
    public function testMissingPublicationReturns404(): void
    {
        $response = self::call('guest', 'GET', '/api/publications/999999999');

        $this->assertSame(404, $response['status']);
    }

    // ------------------------------------------------------------ Сессии и CSRF

    /**
     * Для гостя /me возвращает user: null и непустой CSRF-токен.
     */
    public function testMeForGuestReturnsNullUserAndCsrfToken(): void
    {
        $response = self::call('fresh-guest', 'GET', '/api/auth/me');

        $this->assertSame(200, $response['status']);
        $this->assertNull($response['body']['user']);
        $this->assertNotEmpty($response['body']['csrf_token']);
    }

    /**
     * POST без заголовка X-CSRF-Token отклоняется с 403.
     */
    public function testPostWithoutCsrfTokenIsForbidden(): void
    {
        $response = self::call('guest', 'POST', '/api/auth/login', ['login' => 'x', 'password' => 'y'], ['csrf' => null]);

        $this->assertSame(403, $response['status']);
        $this->assertSame('Сессия устарела, обновите страницу', $response['body']['error']);
    }

    /**
     * POST с неверным CSRF-токеном отклоняется с 403.
     */
    public function testPostWithWrongCsrfTokenIsForbidden(): void
    {
        $response = self::call('guest', 'POST', '/api/auth/login', ['login' => 'x', 'password' => 'y'], ['csrf' => 'wrong-token']);

        $this->assertSame(403, $response['status']);
    }

    // -------------------------------------------------------------- Регистрация

    /**
     * Пустые поля регистрации дают 422 с ошибками username, email и password.
     */
    public function testRegisterWithEmptyFieldsReturnsErrors(): void
    {
        $response = self::call('guest', 'POST', '/api/auth/register', []);

        $this->assertSame(422, $response['status']);
        foreach (['username', 'email', 'password'] as $field) {
            $this->assertArrayHasKey($field, $response['body']['errors']);
        }
    }

    /**
     * Плохой email, короткий пароль и несовпадение подтверждения дают ошибки по нужным полям.
     */
    public function testRegisterRejectsBadEmailShortPasswordAndMismatch(): void
    {
        $badEmail = self::call('guest', 'POST', '/api/auth/register', [
            'username' => self::$prefix . '_x1', 'email' => 'not-an-email',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ]);
        $shortPassword = self::call('guest', 'POST', '/api/auth/register', [
            'username' => self::$prefix . '_x2', 'email' => self::$prefix . '_x2@example.com',
            'password' => 'abc1', 'password_confirmation' => 'abc1',
        ]);
        $mismatch = self::call('guest', 'POST', '/api/auth/register', [
            'username' => self::$prefix . '_x3', 'email' => self::$prefix . '_x3@example.com',
            'password' => self::PASSWORD, 'password_confirmation' => 'Another123',
        ]);
        $badUsername = self::call('guest', 'POST', '/api/auth/register', [
            'username' => 'bad name!', 'email' => self::$prefix . '_x4@example.com',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ]);

        $this->assertSame(422, $badEmail['status']);
        $this->assertSame(['email'], array_keys($badEmail['body']['errors']));
        $this->assertSame(422, $shortPassword['status']);
        $this->assertSame(['password'], array_keys($shortPassword['body']['errors']));
        $this->assertSame(422, $mismatch['status']);
        $this->assertSame(['password_confirmation'], array_keys($mismatch['body']['errors']));
        $this->assertSame(['username'], array_keys($badUsername['body']['errors']));
    }

    /**
     * Успешная регистрация: 201, пользователь сразу вошёл, пароль и хеш в ответе отсутствуют.
     */
    public function testRegisterSuccessLogsInAndHidesPassword(): void
    {
        $name = self::$prefix . '_newbie';
        $response = self::call('newbie', 'POST', '/api/auth/register', [
            'username' => $name, 'email' => $name . '@example.com',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ]);
        $me = self::call('newbie', 'GET', '/api/auth/me');

        $this->assertSame(201, $response['status']);
        $this->assertSame($name, $response['body']['user']['username']);
        $this->assertFalse($response['body']['user']['admin']);
        $this->assertTrue($response['body']['user']['active']);
        $this->assertArrayNotHasKey('password', $response['body']['user']);
        $this->assertArrayNotHasKey('password_hash', $response['body']['user']);
        $this->assertStringNotContainsString('password', $response['raw']);
        $this->assertSame($name, $me['body']['user']['username']);
    }

    /**
     * Повторный username или email (в том числе в другом регистре) даёт 422.
     */
    public function testRegisterRejectsDuplicateUsernameAndEmail(): void
    {
        $fresh = self::$prefix . '_dupcheck';
        $sameUsername = self::call('guest', 'POST', '/api/auth/register', [
            'username' => strtoupper(self::$userA['username']), 'email' => $fresh . '@example.com',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ]);
        $sameEmail = self::call('guest', 'POST', '/api/auth/register', [
            'username' => $fresh, 'email' => strtoupper(self::$userA['email']),
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ]);

        $this->assertSame(422, $sameUsername['status']);
        $this->assertArrayHasKey('username', $sameUsername['body']['errors']);
        $this->assertSame(422, $sameEmail['status']);
        $this->assertArrayHasKey('email', $sameEmail['body']['errors']);
    }

    /**
     * В БД лежит хеш пароля, а не сам пароль.
     */
    public function testPasswordIsStoredAsHash(): void
    {
        $statement = self::$pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $statement->execute([self::$userA['id']]);
        $hash = (string) $statement->fetchColumn();

        $this->assertNotSame(self::PASSWORD, $hash);
        $this->assertTrue(password_verify(self::PASSWORD, $hash));
    }

    // ------------------------------------------------------------- Вход и выход

    /**
     * Неверный пароль и несуществующий пользователь дают одинаковый 401.
     */
    public function testLoginWithWrongCredentialsReturns401(): void
    {
        $wrongPassword = self::call('guest', 'POST', '/api/auth/login', [
            'login' => self::$userA['username'], 'password' => 'WrongPass123',
        ]);
        $unknownUser = self::call('guest', 'POST', '/api/auth/login', [
            'login' => self::$prefix . '_ghost', 'password' => 'WrongPass123',
        ]);

        $this->assertSame(401, $wrongPassword['status']);
        $this->assertSame('Неверный логин или пароль', $wrongPassword['body']['error']);
        $this->assertSame(401, $unknownUser['status']);
        $this->assertSame($wrongPassword['body'], $unknownUser['body']);
    }

    /**
     * Вход работает и по username, и по email.
     */
    public function testLoginByUsernameAndByEmail(): void
    {
        $byUsername = self::call('login-name', 'POST', '/api/auth/login', [
            'login' => self::$userA['username'], 'password' => self::PASSWORD,
        ]);
        $byEmail = self::call('login-email', 'POST', '/api/auth/login', [
            'login' => strtoupper(self::$userA['email']), 'password' => self::PASSWORD,
        ]);

        $this->assertSame(200, $byUsername['status']);
        $this->assertSame(self::$userA['id'], $byUsername['body']['user']['id']);
        $this->assertSame(200, $byEmail['status']);
        $this->assertSame(self::$userA['id'], $byEmail['body']['user']['id']);
    }

    /**
     * После входа идентификатор сессии меняется (защита от фиксации сессии).
     */
    public function testLoginChangesSessionCookie(): void
    {
        self::call('cookie-check', 'GET', '/api/auth/me');
        $before = self::sessionCookie('cookie-check');

        self::call('cookie-check', 'POST', '/api/auth/login', [
            'login' => self::$userA['username'], 'password' => self::PASSWORD,
        ]);
        $after = self::sessionCookie('cookie-check');

        $this->assertNotSame('', $before);
        $this->assertNotSame('', $after);
        $this->assertNotSame($before, $after);
    }

    /**
     * После выхода /me снова возвращает user: null, а защищённые маршруты закрыты.
     */
    public function testLogoutEndsSession(): void
    {
        self::login('logout-check', self::$userA['username'], self::PASSWORD);
        $this->assertNotNull(self::call('logout-check', 'GET', '/api/auth/me')['body']['user']);

        $logout = self::call('logout-check', 'POST', '/api/auth/logout');
        $me = self::call('logout-check', 'GET', '/api/auth/me');
        $create = self::call('logout-check', 'POST', '/api/publications', self::publicationData());

        $this->assertSame(200, $logout['status']);
        $this->assertNull($me['body']['user']);
        $this->assertSame(401, $create['status']);
    }

    // -------------------------------------------------------------- Публикации

    /**
     * Гость не может создавать публикации: 401.
     */
    public function testGuestCannotCreatePublication(): void
    {
        $response = self::call('guest', 'POST', '/api/publications', self::publicationData());

        $this->assertSame(401, $response['status']);
        $this->assertSame('Войдите в аккаунт', $response['body']['error']);
    }

    /**
     * Каждое поле формы публикации проверяется на сервере.
     */
    public function testPublicationFieldsAreValidated(): void
    {
        $allInvalid = self::call('a', 'POST', '/api/publications', [
            'title' => 'abc', 'text' => 'short', 'category_id' => 999999999,
            'type' => 'gossip', 'allow_comments' => 'yes',
        ]);
        $empty = self::call('a', 'POST', '/api/publications', []);
        $stringBoolean = self::call('a', 'POST', '/api/publications', self::publicationData(['allow_comments' => 'true']));

        $this->assertSame(422, $allInvalid['status']);
        $this->assertEqualsCanonicalizing(
            ['title', 'text', 'category_id', 'type', 'allow_comments'],
            array_keys($allInvalid['body']['errors'])
        );
        $this->assertSame(422, $empty['status']);
        $this->assertCount(5, $empty['body']['errors']);
        $this->assertSame(['allow_comments'], array_keys($stringBoolean['body']['errors']));
    }

    /**
     * Созданная публикация возвращается в полном формате и видна в ленте и на странице публикации.
     */
    public function testCreatedPublicationAppearsInFeedAndDetails(): void
    {
        $created = self::call('a', 'POST', '/api/publications', self::publicationData(['title' => 'Вопрос про сессию']));
        $id = $created['body']['id'];

        $details = self::call('guest', 'GET', '/api/publications/' . $id);
        $feed = self::call('guest', 'GET', '/api/publications?per_page=50');
        $feedIds = array_column($feed['body']['items'], 'id');

        $this->assertSame(201, $created['status']);
        $this->assertTrue($created['body']['can_edit']);
        $this->assertSame(200, $details['status']);
        $this->assertSame('Вопрос про сессию', $details['body']['title']);
        $this->assertSame(self::$userA['username'], $details['body']['author']['username']);
        $this->assertSame([], $details['body']['comments']);
        $this->assertFalse($details['body']['can_edit']);
        $this->assertFalse($details['body']['liked_by_me']);
        $this->assertContains($id, $feedIds);
    }

    /**
     * HTML в заголовке сохраняется и возвращается без изменений (экранирует фронтенд).
     */
    public function testScriptTagInTitleIsStoredUnchanged(): void
    {
        $title = '<script>alert(1)</script>';
        $created = $this->createPublication('a', ['title' => $title]);

        $details = self::call('guest', 'GET', '/api/publications/' . $created['id']);

        $this->assertSame($title, $created['title']);
        $this->assertSame($title, $details['body']['title']);
    }

    /**
     * Чужую публикацию нельзя изменить или удалить (403), автор может изменить (200) и удалить.
     */
    public function testOnlyAuthorCanEditAndDeletePublication(): void
    {
        $publication = $this->createPublication('a');
        $url = '/api/publications/' . $publication['id'];

        $foreignPut = self::call('b', 'PUT', $url, self::publicationData(['title' => 'Чужая правка']));
        $foreignDelete = self::call('b', 'DELETE', $url);
        $ownPut = self::call('a', 'PUT', $url, self::publicationData(['title' => 'Новый заголовок', 'type' => 'announcement']));
        $ownDelete = self::call('a', 'DELETE', $url);

        $this->assertSame(403, $foreignPut['status']);
        $this->assertSame(403, $foreignDelete['status']);
        $this->assertSame(200, $ownPut['status']);
        $this->assertSame('Новый заголовок', $ownPut['body']['title']);
        $this->assertSame('announcement', $ownPut['body']['type']);
        $this->assertSame(200, $ownDelete['status']);
        $this->assertSame(404, self::call('guest', 'GET', $url)['status']);
    }

    // ------------------------------------------------------------------- Лайки

    /**
     * Лайк ставится и снимается повторным запросом, гость получает 401.
     */
    public function testLikeTogglesAndRequiresLogin(): void
    {
        $publication = $this->createPublication('a');
        $url = '/api/publications/' . $publication['id'] . '/like';

        $guest = self::call('guest', 'POST', $url);
        $first = self::call('b', 'POST', $url);
        $likedByMe = self::call('b', 'GET', '/api/publications/' . $publication['id']);
        $second = self::call('b', 'POST', $url);

        $this->assertSame(401, $guest['status']);
        $this->assertSame(['liked' => true, 'likes_count' => 1], $first['body']);
        $this->assertTrue($likedByMe['body']['liked_by_me']);
        $this->assertSame(['liked' => false, 'likes_count' => 0], $second['body']);
    }

    // ------------------------------------------------------------ Комментарии

    /**
     * Комментарий создаётся (201), пустой текст отклоняется (422).
     */
    public function testAddCommentAndRejectEmptyText(): void
    {
        $publication = $this->createPublication('a');
        $url = '/api/publications/' . $publication['id'] . '/comments';

        $created = self::call('b', 'POST', $url, ['text' => 'Хороший вопрос']);
        $empty = self::call('b', 'POST', $url, ['text' => '   ']);
        $guest = self::call('guest', 'POST', $url, ['text' => 'Привет']);
        $details = self::call('a', 'GET', '/api/publications/' . $publication['id']);

        $this->assertSame(201, $created['status']);
        $this->assertSame('Хороший вопрос', $created['body']['text']);
        $this->assertSame(self::$userB['username'], $created['body']['author']['username']);
        $this->assertSame(422, $empty['status']);
        $this->assertArrayHasKey('text', $empty['body']['errors']);
        $this->assertSame(401, $guest['status']);
        $this->assertSame(1, $details['body']['comments_count']);
        $this->assertFalse($details['body']['comments'][0]['can_delete']);
    }

    /**
     * Если у публикации отключены комментарии, добавить комментарий нельзя: 403.
     */
    public function testCommentsToggleOffReturns403(): void
    {
        $publication = $this->createPublication('a', ['allow_comments' => false]);

        $response = self::call('b', 'POST', '/api/publications/' . $publication['id'] . '/comments', ['text' => 'Можно?']);
        $missing = self::call('b', 'POST', '/api/publications/999999999/comments', ['text' => 'Можно?']);

        $this->assertSame(403, $response['status']);
        $this->assertSame('Комментарии к этой публикации отключены', $response['body']['error']);
        $this->assertSame(404, $missing['status']);
    }

    /**
     * Чужой комментарий удалить нельзя (403); автор удаляет — комментарий пропадает, счётчик уменьшается.
     */
    public function testOnlyCommentAuthorCanDeleteComment(): void
    {
        $publication = $this->createPublication('a');
        $comment = self::call('b', 'POST', '/api/publications/' . $publication['id'] . '/comments', ['text' => 'Удалю позже']);
        $commentUrl = '/api/comments/' . $comment['body']['id'];
        $publicationUrl = '/api/publications/' . $publication['id'];

        $foreign = self::call('a', 'DELETE', $commentUrl);
        $before = self::call('guest', 'GET', $publicationUrl);
        $own = self::call('b', 'DELETE', $commentUrl);
        $after = self::call('guest', 'GET', $publicationUrl);
        $again = self::call('b', 'DELETE', $commentUrl);

        $this->assertSame(403, $foreign['status']);
        $this->assertSame(1, $before['body']['comments_count']);
        $this->assertSame(200, $own['status']);
        $this->assertSame(0, $after['body']['comments_count']);
        $this->assertSame([], $after['body']['comments']);
        $this->assertSame(404, $again['status']);
    }

    // ------------------------------------------------------------------- Поиск

    /**
     * Поиск находит публикацию по слову, а фильтры по разделу, типу, автору и дате сужают результат.
     */
    public function testSearchByWordAndFilters(): void
    {
        $word = 'findme' . substr(self::$prefix, 5);
        $first = $this->createPublication('a', [
            'title' => "Заголовок $word", 'type' => 'question', 'category_id' => self::$seedCategoryIds[0],
        ]);
        $second = $this->createPublication('b', [
            'text' => "Текст публикации со словом $word внутри", 'type' => 'announcement', 'category_id' => self::$seedCategoryIds[1],
        ]);

        $ids = fn (string $query): array => array_column(self::call('guest', 'GET', '/api/search?' . $query)['body']['items'], 'id');
        $today = gmdate('Y-m-d');
        $tomorrow = gmdate('Y-m-d', time() + 86400);

        $this->assertEqualsCanonicalizing([$first['id'], $second['id']], $ids("q=$word"));
        $this->assertSame([$first['id']], $ids("q=$word&type=question"));
        $this->assertSame([$second['id']], $ids("q=$word&category_id=" . self::$seedCategoryIds[1]));
        $this->assertSame([$second['id']], $ids("q=$word&author=" . self::$userB['username']));
        $this->assertCount(2, $ids("q=$word&date_from=$today&date_to=$today"));
        $this->assertSame([], $ids("q=$word&date_from=$tomorrow"));
        $this->assertContains($first['id'], $ids('author=' . self::$userA['username'] . '&per_page=50'));
    }

    /**
     * Некорректные параметры поиска дают 422.
     */
    public function testSearchRejectsInvalidParameters(): void
    {
        $shortQuery = self::call('guest', 'GET', '/api/search?q=ab');
        $badDate = self::call('guest', 'GET', '/api/search?date_from=2026-13-45');
        $reversed = self::call('guest', 'GET', '/api/search?date_from=2026-05-02&date_to=2026-05-01');
        $badType = self::call('guest', 'GET', '/api/search?type=gossip');
        $badCategory = self::call('guest', 'GET', '/api/search?category_id=abc');

        $this->assertSame(422, $shortQuery['status']);
        $this->assertArrayHasKey('q', $shortQuery['body']['errors']);
        $this->assertSame(422, $badDate['status']);
        $this->assertArrayHasKey('date_from', $badDate['body']['errors']);
        $this->assertSame(422, $reversed['status']);
        $this->assertArrayHasKey('date_to', $reversed['body']['errors']);
        $this->assertArrayHasKey('type', $badType['body']['errors']);
        $this->assertArrayHasKey('category_id', $badCategory['body']['errors']);
    }

    /**
     * SQL-инъекция в параметре поиска не вызывает ошибку и не возвращает все записи.
     */
    public function testSearchIsNotVulnerableToSqlInjection(): void
    {
        $this->createPublication('a');

        $injection = self::call('guest', 'GET', '/api/search?' . http_build_query(['q' => "' OR 1=1 --"]));
        $author = self::call('guest', 'GET', '/api/search?' . http_build_query(['author' => "' OR '1'='1"]));
        $feed = self::call('guest', 'GET', '/api/publications');

        $this->assertSame(200, $injection['status']);
        $this->assertLessThan($feed['body']['total'], $injection['body']['total']);
        $this->assertSame(200, $author['status']);
        $this->assertSame(0, $author['body']['total']);
    }

    // ------------------------------------------------------------ Администратор

    /**
     * Админка закрыта для гостя (401) и обычного пользователя (403), админ видит список пользователей.
     */
    public function testAdminAreaRequiresAdminRole(): void
    {
        $guest = self::call('guest', 'GET', '/api/admin/users');
        $user = self::call('a', 'GET', '/api/admin/users');
        $admin = self::call('admin', 'GET', '/api/admin/users?q=' . self::$userA['username']);

        $this->assertSame(401, $guest['status']);
        $this->assertSame(403, $user['status']);
        $this->assertSame(403, self::call('a', 'POST', '/api/admin/categories', ['name' => 'Хитрость'])['status']);
        $this->assertSame(200, $admin['status']);
        $this->assertSame(1, $admin['body']['total']);
        $this->assertSame(self::$userA['id'], $admin['body']['items'][0]['id']);
        $this->assertArrayHasKey('publications_count', $admin['body']['items'][0]);
        $this->assertArrayNotHasKey('password_hash', $admin['body']['items'][0]);
    }

    /**
     * Админ создаёт нового администратора, тот входит и открывает админку.
     */
    public function testAdminCanCreateAnotherAdmin(): void
    {
        $name = self::$prefix . '_admin2';
        $payload = ['username' => $name, 'email' => $name . '@example.com', 'password' => self::PASSWORD, 'admin' => true];

        $created = self::call('admin', 'POST', '/api/admin/users', $payload);
        $invalid = self::call('admin', 'POST', '/api/admin/users', ['username' => 'x', 'admin' => 'yes']);
        $login = self::call('admin2', 'POST', '/api/auth/login', ['login' => $name, 'password' => self::PASSWORD]);
        $panel = self::call('admin2', 'GET', '/api/admin/users');

        $this->assertSame(201, $created['status']);
        $this->assertTrue($created['body']['user']['admin']);
        $this->assertSame(422, $invalid['status']);
        $this->assertArrayHasKey('admin', $invalid['body']['errors']);
        $this->assertSame(200, $login['status']);
        $this->assertSame(200, $panel['status']);
    }

    /**
     * Блокировка действует сразу: сессия теряет доступ, вход закрыт, после разблокировки вход снова работает.
     */
    public function testBlockedUserLosesAccessImmediately(): void
    {
        $victim = self::registerUser('victim', 'victim');
        $url = '/api/admin/users/' . $victim['id'];

        $this->assertSame(201, self::call('victim', 'POST', '/api/publications', self::publicationData())['status']);

        $block = self::call('admin', 'PATCH', $url, ['active' => false]);
        $withOldSession = self::call('victim', 'POST', '/api/publications', self::publicationData());
        $newLogin = self::call('victim-new', 'POST', '/api/auth/login', ['login' => $victim['username'], 'password' => self::PASSWORD]);

        $unblock = self::call('admin', 'PATCH', $url, ['active' => true]);
        $loginAgain = self::call('victim-new', 'POST', '/api/auth/login', ['login' => $victim['username'], 'password' => self::PASSWORD]);

        $this->assertSame(200, $block['status']);
        $this->assertFalse($block['body']['user']['active']);
        $this->assertSame(401, $withOldSession['status']);
        $this->assertSame(403, $newLogin['status']);
        $this->assertSame('Аккаунт заблокирован', $newLogin['body']['error']);
        $this->assertSame(200, $unblock['status']);
        $this->assertSame(200, $loginAgain['status']);
    }

    /**
     * Роль администратора можно выдать и снять; пустой запрос и неизвестный пользователь отклоняются.
     */
    public function testAdminCanGrantAndRevokeAdminRole(): void
    {
        $target = self::registerUser('target', 'target');
        $url = '/api/admin/users/' . $target['id'];

        $grant = self::call('admin', 'PATCH', $url, ['admin' => true]);
        $revoke = self::call('admin', 'PATCH', $url, ['admin' => false]);
        $empty = self::call('admin', 'PATCH', $url, []);
        $missing = self::call('admin', 'PATCH', '/api/admin/users/999999999', ['active' => false]);

        $this->assertTrue($grant['body']['user']['admin']);
        $this->assertTrue($grant['body']['user']['active']);
        $this->assertFalse($revoke['body']['user']['admin']);
        $this->assertSame(422, $empty['status']);
        $this->assertSame(404, $missing['status']);
    }

    /**
     * Администратор не может изменить свою учётную запись: 422.
     */
    public function testAdminCannotChangeOwnAccount(): void
    {
        $response = self::call('admin', 'PATCH', '/api/admin/users/' . self::$admin['id'], ['active' => false]);

        $this->assertSame(422, $response['status']);
        $this->assertSame('Нельзя изменить свою учётную запись', $response['body']['error']);
    }

    /**
     * CRUD разделов: создание, проверка имени, переименование, защита раздела с публикациями, удаление.
     */
    public function testAdminManagesCategories(): void
    {
        $name = self::$prefix . '_category';

        $created = self::call('admin', 'POST', '/api/admin/categories', ['name' => $name]);
        $id = $created['body']['category']['id'];
        $url = '/api/admin/categories/' . $id;

        $empty = self::call('admin', 'POST', '/api/admin/categories', ['name' => '']);
        $duplicate = self::call('admin', 'POST', '/api/admin/categories', ['name' => strtoupper($name)]);
        $renamed = self::call('admin', 'PUT', $url, ['name' => $name . '_renamed']);
        $sameName = self::call('admin', 'PUT', $url, ['name' => $name . '_renamed']);

        $publication = $this->createPublication('a', ['category_id' => $id]);
        $deleteBusy = self::call('admin', 'DELETE', $url);
        self::call('a', 'DELETE', '/api/publications/' . $publication['id']);
        $deleted = self::call('admin', 'DELETE', $url);
        $deletedAgain = self::call('admin', 'DELETE', $url);

        $this->assertSame(201, $created['status']);
        $this->assertSame(422, $empty['status']);
        $this->assertArrayHasKey('name', $empty['body']['errors']);
        $this->assertSame(422, $duplicate['status']);
        $this->assertSame('Раздел с таким названием уже есть', $duplicate['body']['errors']['name']);
        $this->assertSame(200, $renamed['status']);
        $this->assertSame($name . '_renamed', $renamed['body']['category']['name']);
        $this->assertSame(200, $sameName['status']);
        $this->assertSame(409, $deleteBusy['status']);
        $this->assertSame('В разделе есть публикации', $deleteBusy['body']['error']);
        $this->assertSame(200, $deleted['status']);
        $this->assertSame(404, $deletedAgain['status']);
    }

    /**
     * Админ может удалить чужую публикацию и чужой комментарий, а также изменить чужую публикацию.
     */
    public function testAdminCanModerateForeignContent(): void
    {
        $publication = $this->createPublication('a');
        $publicationUrl = '/api/publications/' . $publication['id'];
        $comment = self::call('b', 'POST', $publicationUrl . '/comments', ['text' => 'Нарушение правил']);

        $details = self::call('admin', 'GET', $publicationUrl);
        $edit = self::call('admin', 'PUT', $publicationUrl, self::publicationData(['title' => 'Отредактировано модератором']));
        $deleteComment = self::call('admin', 'DELETE', '/api/comments/' . $comment['body']['id']);
        $deletePublication = self::call('admin', 'DELETE', $publicationUrl);

        $this->assertTrue($details['body']['can_delete']);
        $this->assertTrue($details['body']['comments'][0]['can_delete']);
        $this->assertSame(200, $edit['status']);
        $this->assertSame(200, $deleteComment['status']);
        $this->assertSame(200, $deletePublication['status']);
        $this->assertSame(404, self::call('guest', 'GET', $publicationUrl)['status']);
    }

    // ----------------------------------------------------------- Сброс пароля

    /**
     * forgot-password даёт одинаковый ответ для известного и неизвестного email.
     */
    public function testForgotPasswordGivesSameResponseForAnyEmail(): void
    {
        $unknown = self::call('guest', 'POST', '/api/auth/forgot-password', ['email' => self::$prefix . '_nobody@example.com']);
        $known = self::call('guest', 'POST', '/api/auth/forgot-password', ['email' => self::$userA['email']]);
        $invalid = self::call('guest', 'POST', '/api/auth/forgot-password', ['email' => 'not-an-email']);

        $this->assertSame(200, $unknown['status']);
        $this->assertSame(200, $known['status']);
        $this->assertSame($unknown['body'], $known['body']);
        $this->assertSame(422, $invalid['status']);
    }

    /**
     * Для известного email в password_reset появляется запись, старый токен гасится новым.
     */
    public function testForgotPasswordCreatesTokenRecord(): void
    {
        $user = self::registerUser('forgetful', 'forgetful');

        self::call('guest', 'POST', '/api/auth/forgot-password', ['email' => $user['email']]);
        self::call('guest', 'POST', '/api/auth/forgot-password', ['email' => $user['email']]);

        $statement = self::$pdo->prepare('SELECT COUNT(*) AS total, SUM(used_at IS NULL) AS active FROM password_reset WHERE user_id = ?');
        $statement->execute([$user['id']]);
        $row = $statement->fetch();

        $this->assertSame(2, (int) $row['total']);
        $this->assertSame(1, (int) $row['active']);
    }

    /**
     * Валидный токен меняет пароль: новый работает, старый — нет.
     */
    public function testResetPasswordWithValidToken(): void
    {
        $user = self::registerUser('resetter', 'resetter');
        $token = bin2hex(random_bytes(32));
        self::insertResetToken($user['id'], $token, '1 HOUR');
        $newPassword = 'NewPassw0rd456';

        $weak = self::call('guest', 'POST', '/api/auth/reset-password', [
            'token' => $token, 'password' => 'short', 'password_confirmation' => 'short',
        ]);
        $reset = self::call('guest', 'POST', '/api/auth/reset-password', [
            'token' => $token, 'password' => $newPassword, 'password_confirmation' => $newPassword,
        ]);
        $newLogin = self::call('reset-new', 'POST', '/api/auth/login', ['login' => $user['username'], 'password' => $newPassword]);
        $oldLogin = self::call('reset-old', 'POST', '/api/auth/login', ['login' => $user['username'], 'password' => self::PASSWORD]);

        $this->assertSame(422, $weak['status']);
        $this->assertArrayHasKey('password', $weak['body']['errors']);
        $this->assertSame(200, $reset['status']);
        $this->assertSame('Пароль изменён, теперь можно войти', $reset['body']['message']);
        $this->assertSame(200, $newLogin['status']);
        $this->assertSame(401, $oldLogin['status']);
    }

    /**
     * Использованный токен повторно принять нельзя: 422.
     */
    public function testUsedResetTokenIsRejected(): void
    {
        $user = self::registerUser('reuse', 'reuse');
        $token = bin2hex(random_bytes(32));
        self::insertResetToken($user['id'], $token, '1 HOUR');
        $payload = ['token' => $token, 'password' => 'NewPassw0rd456', 'password_confirmation' => 'NewPassw0rd456'];

        $first = self::call('guest', 'POST', '/api/auth/reset-password', $payload);
        $second = self::call('guest', 'POST', '/api/auth/reset-password', $payload);

        $this->assertSame(200, $first['status']);
        $this->assertSame(422, $second['status']);
        $this->assertSame('Ссылка недействительна или устарела', $second['body']['errors']['token']);
    }

    /**
     * Просроченный и несуществующий токены отклоняются: 422.
     */
    public function testExpiredAndUnknownResetTokensAreRejected(): void
    {
        $user = self::registerUser('expired', 'expired');
        $token = bin2hex(random_bytes(32));
        self::insertResetToken($user['id'], $token, '-1 MINUTE');
        $payload = ['password' => 'NewPassw0rd456', 'password_confirmation' => 'NewPassw0rd456'];

        $expired = self::call('guest', 'POST', '/api/auth/reset-password', ['token' => $token] + $payload);
        $unknown = self::call('guest', 'POST', '/api/auth/reset-password', ['token' => bin2hex(random_bytes(32))] + $payload);

        $this->assertSame(422, $expired['status']);
        $this->assertArrayHasKey('token', $expired['body']['errors']);
        $this->assertSame(422, $unknown['status']);
        $this->assertArrayHasKey('token', $unknown['body']['errors']);
        $this->assertSame(200, self::call('expired-login', 'POST', '/api/auth/login', [
            'login' => $user['username'], 'password' => self::PASSWORD,
        ])['status']);
    }

    // ------------------------------------------------------------- Помощники

    /**
     * Корректные данные формы публикации, часть полей можно переопределить.
     *
     * @param array<string, mixed> $overrides Переопределяемые поля
     * @return array<string, mixed>
     */
    private static function publicationData(array $overrides = []): array
    {
        return $overrides + [
            'title' => 'Тестовая публикация ' . bin2hex(random_bytes(2)),
            'text' => 'Это текст тестовой публикации, он достаточно длинный.',
            'category_id' => self::$seedCategoryIds[0],
            'type' => 'discussion',
            'allow_comments' => true,
        ];
    }

    /**
     * Создаёт публикацию от имени клиента и проверяет, что ответ 201.
     *
     * @param string               $client    Имя клиента
     * @param array<string, mixed> $overrides Переопределяемые поля формы
     * @return array<string, mixed> Публикация из ответа
     */
    private function createPublication(string $client, array $overrides = []): array
    {
        $response = self::call($client, 'POST', '/api/publications', self::publicationData($overrides));
        $this->assertSame(201, $response['status'], $response['raw']);

        return $response['body'];
    }

    /**
     * Регистрирует пользователя через API (клиент остаётся вошедшим).
     *
     * @param string $client Имя клиента
     * @param string $label  Часть имени пользователя
     * @return array{id: int, username: string, email: string}
     */
    private static function registerUser(string $client, string $label): array
    {
        $username = self::$prefix . '_' . $label;
        $response = self::call($client, 'POST', '/api/auth/register', [
            'username' => $username,
            'email' => $username . '@example.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ]);

        if ($response['status'] !== 201) {
            throw new \RuntimeException('Не удалось зарегистрировать тестового пользователя: ' . $response['raw']);
        }

        return ['id' => $response['body']['user']['id'], 'username' => $username, 'email' => $username . '@example.com'];
    }

    /**
     * Входит в аккаунт от имени клиента.
     *
     * @param string $client   Имя клиента
     * @param string $login    Имя пользователя или email
     * @param string $password Пароль
     * @return void
     */
    private static function login(string $client, string $login, string $password): void
    {
        $response = self::call($client, 'POST', '/api/auth/login', ['login' => $login, 'password' => $password]);

        if ($response['status'] !== 200) {
            throw new \RuntimeException('Не удалось войти тестовым пользователем: ' . $response['raw']);
        }
    }

    /**
     * Вставляет в БД токен сброса пароля (в таблице хранится только его SHA-256).
     *
     * @param int    $userId   Пользователь
     * @param string $token    Токен в открытом виде
     * @param string $lifetime Срок жизни относительно NOW(), например '1 HOUR' или '-1 MINUTE'
     * @return void
     */
    private static function insertResetToken(int $userId, string $token, string $lifetime): void
    {
        self::$pdo->prepare('INSERT INTO password_reset (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ' . $lifetime . '))')
            ->execute([$userId, hash('sha256', $token)]);
    }

    /**
     * Читает идентификатор сессии из cookie-файла клиента.
     *
     * @param string $client Имя клиента
     * @return string Значение cookie forum_session или пустая строка
     */
    private static function sessionCookie(string $client): string
    {
        $content = (string) file_get_contents(self::$cookieJars[$client]);

        return preg_match('/forum_session\s+(\S+)/', $content, $matches) === 1 ? $matches[1] : '';
    }

    /**
     * Отправляет HTTP-запрос к API от имени клиента (у каждого клиента свой cookie-файл).
     * Для запросов не-GET сам получает CSRF-токен через /api/auth/me.
     *
     * Параметры $options:
     *  - csrf: не задан — подставить настоящий токен; null — не отправлять заголовок; строка — отправить её;
     *  - rawBody: готовая строка тела вместо JSON.
     *
     * @param string                    $client  Имя клиента (guest, a, b, admin, ...)
     * @param string                    $method  HTTP-метод
     * @param string                    $path    Путь, например /api/publications
     * @param array<string, mixed>|null $body    Тело запроса (будет закодировано в JSON)
     * @param array<string, mixed>      $options Дополнительные параметры, см. выше
     * @return array{status: int, body: mixed, raw: string} Код ответа, декодированный JSON и исходный текст
     */
    private static function call(string $client, string $method, string $path, ?array $body = null, array $options = []): array
    {
        self::$cookieJars[$client] ??= (string) tempnam(sys_get_temp_dir(), 'forum_test_');

        $headers = ['Accept: application/json'];
        if ($method !== 'GET') {
            $token = array_key_exists('csrf', $options) ? $options['csrf'] : self::csrfToken($client);
            if ($token !== null) {
                $headers[] = 'X-CSRF-Token: ' . $token;
            }
            $headers[] = 'Content-Type: application/json';
        }

        $curl = curl_init(self::$baseUrl . $path);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_COOKIEFILE => self::$cookieJars[$client],
            CURLOPT_COOKIEJAR => self::$cookieJars[$client],
            CURLOPT_TIMEOUT => 30,
        ]);
        if (isset($options['rawBody'])) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $options['rawBody']);
        } elseif ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }

        $raw = curl_exec($curl);
        if ($raw === false) {
            throw new \RuntimeException('HTTP-запрос не удался: ' . curl_error($curl));
        }
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        return ['status' => $status, 'body' => json_decode($raw, true), 'raw' => $raw];
    }

    /**
     * Получает актуальный CSRF-токен клиента.
     *
     * @param string $client Имя клиента
     * @return string
     */
    private static function csrfToken(string $client): string
    {
        return self::call($client, 'GET', '/api/auth/me')['body']['csrf_token'];
    }
}
