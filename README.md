# Университетский форум — REST API

Серверная часть учебного проекта: публикации, комментарии, лайки, поиск, роли и восстановление пароля.
Фронтенд (Svelte) делается отдельно и работает с API по одному адресу, поэтому CORS не нужен.

**Стек:** PHP 8.4 (чистый, без фреймворков), Apache, MariaDB 12.3, PHPMailer, PHPUnit, Docker Compose, Mailpit (тестовая почта).
**Архитектура:** MVC — `Core` (инфраструктура), `Models` (весь SQL), `Controllers` (приём запроса → проверка → модель → ответ).

```
api/
├── public/index.php      точка входа: настройки, сессия, обработчик ошибок, список маршрутов
├── src/Core/             Database, Router, Request, Response, Auth, Validator, Mailer
├── src/Models/           User, Category, Publication, Comment
├── src/Controllers/      AuthController, PublicationController, CommentController, AdminController
├── tests/ApiTest.php     интеграционные тесты
└── migrations/           схема и начальные данные БД
```

## Запуск на Mac

```bash
cp .env.example .env     # затем впишите свои пароли (если .env уже есть — добавьте новые строки)
docker compose up -d --build
curl http://localhost:8080/api/health     # {"status":"ok","database":"connected"}
```

При каждом старте контейнер `app` выполняет `composer install`, поэтому первый запуск занимает чуть дольше.

Для Mac в `.env` добавьте (в дополнение к `DB_*`):

```ini
APP_URL=http://localhost:5173
APP_DEBUG=true
MAIL_HOST=mailpit
MAIL_PORT=1025
MAIL_FROM=noreply@vov.md
MAIL_FROM_NAME=Forum
```

## Запуск на Raspberry Pi

```bash
git pull
# в .env добавьте:
#   APP_URL=https://forum.vov.md
#   APP_DEBUG=false
#   (и те же MAIL_* строки, что на Mac, либо настройки реального SMTP)
docker compose up -d --build
```

Сайт открывается через Cloudflare Tunnel: https://forum.vov.md → `http://localhost:8080`.
Cookie сессии получает флаг `Secure` автоматически (Cloudflare присылает `X-Forwarded-Proto: https`).

## Тесты

```bash
docker compose exec app vendor/bin/phpunit
```

Тесты отправляют настоящие HTTP-запросы к запущенному API (`TEST_BASE_URL`, по умолчанию `http://localhost`
внутри контейнера). Все созданные данные имеют префикс `test_<случайный суффикс>` и удаляются после прогона,
остальные данные в БД не затрагиваются. Первый администратор создаётся напрямую в БД, потому что через API его
создать нельзя.

## Фронтенд

**Стек:** Svelte 5 + Vite (SPA, без SvelteKit), стили только на SCSS (`sass`), JavaScript + JSDoc, свой роутер.
Всё состояние — на рунах (`$state`, `$derived`, `$props`), авторизация — только cookie-сессия API (в `localStorage` ничего не хранится).

**Разработка** (Node.js 24 нужен только на компьютере разработчика):

```bash
docker compose up -d                       # API на http://localhost:8080
cd frontend && npm install && npm run dev  # сайт на http://localhost:5173
```

Vite проксирует `/api` на `localhost:8080`, поэтому фронт и API живут на одном адресе: CORS не нужен, cookie сессии работают.

**Продакшен:** `docker compose up -d --build` → http://localhost:8080. Фронтенд собирается внутри Docker (первый этап `Dockerfile`),
Node на Raspberry Pi не нужен. Apache отдаёт собранную статику, а любой адрес вроде `/publications/5` — `index.html`
(`FallbackResource`), дальше страницу выбирает роутер.

### Структура `frontend/src`

```
main.js, App.svelte, routes.js     запуск, каркас страницы, таблица маршрутов
styles/                            _tokens.scss (цвета, шрифты), _mixins.scss (рамки, mobile), app.scss (глобальные стили)
lib/router.svelte.js               свой роутер
lib/resource.svelte.js             createResource — загрузка данных страницы
lib/session.svelte.js              текущий пользователь и CSRF-токен
lib/api.js                         fetch: JSON, CSRF-заголовок, ApiError
lib/validation.js, lib/format.js   проверка форм (как на сервере), даты и склонения
lib/components/                    Box, Field, Notice, Pagination, PublicationTable, PublicationForm
pages/                             по одной странице на маршрут
```

### Как работает роутер

- Адрес хранится в реактивном `SvelteURL`: `router.path` и `router.query` — это обычные реактивные значения, всё, что их читает, обновляется само.
- `router.start()` ловит клики по внутренним ссылкам и «Назад/Вперёд» (`popstate`), поэтому в разметке достаточно обычных `<a href="/publications/5">`.
- `matchRoute(routes, path)` ищет маршрут в `routes.js` (`:id` — только цифры) и возвращает страницу и параметры.
- `App.svelte` выводит страницу как компонент из переменной (`<Page params={...} />`) и закрывает защищённые маршруты:
  гостя отправляет на `/login?redirect=…`, не-админу показывает 403. Это удобство, настоящую защиту делает API.
- Параметры списков (страница, фильтры, поиск) лежат в адресе, поэтому работают «Назад» и ссылки.

### Стиль и адаптивность

Веб начала 2000-х: серый фон, синие шапки блоков, подчёркнутые ссылки, объёмные кнопки «как в Windows», счётчик посещений
(зелёные цифры на чёрном), метка «NEW!», кнопки 88×31 в подвале. Без скруглений, теней, градиентов и эмодзи.
На экране до 640px: контейнер на всю ширину, шрифт 15px, поля ввода 16px (iOS не увеличивает страницу),
кнопки форм на всю ширину, ссылки меню не ниже 40px, таблицы превращаются в карточки (подписи из `data-label`).

### Страница → эндпоинты API

| Страница | Адрес | Эндпоинты |
|---|---|---|
| Главная | `/` | `GET /api/stats`, `/api/categories`, `/api/publications` (последние и `sort=popular`) |
| Лента | `/publications` | `GET /api/categories`, `/api/publications` |
| Тема | `/publications/:id` | `GET /api/publications/{id}`, `POST …/like`, `POST …/comments`, `DELETE /api/comments/{id}`, `DELETE /api/publications/{id}` |
| Новая тема | `/publications/new` | `GET /api/categories`, `POST /api/publications` |
| Изменить тему | `/publications/:id/edit` | `GET /api/publications/{id}`, `GET /api/categories`, `PUT /api/publications/{id}` |
| Поиск | `/search` | `GET /api/categories`, `GET /api/search` |
| Вход, выход | `/login` | `POST /api/auth/login`, `POST /api/auth/logout`, `GET /api/auth/me` |
| Регистрация | `/register` | `POST /api/auth/register` |
| Забыли пароль | `/forgot-password` | `POST /api/auth/forgot-password` |
| Новый пароль | `/reset-password?token=…` | `POST /api/auth/reset-password` |
| Админка | `/admin` | — |
| Пользователи | `/admin/users` | `GET`, `POST /api/admin/users`, `PATCH /api/admin/users/{id}` |
| Разделы | `/admin/categories` | `GET /api/categories`, `POST`, `PUT`, `DELETE /api/admin/categories/…` |

## Письма (сброс пароля)

В разработке письма никуда не уходят — их ловит Mailpit: **http://localhost:8025**.
Для реальной отправки заполните `MAIL_HOST`, `MAIL_PORT`, `MAIL_USER`, `MAIL_PASS`, `MAIL_ENCRYPTION` (`tls` или `ssl`).

## Эндпоинты

Доступ: **гость** — без входа; **вошёл** — нужна сессия; **автор/админ** — автор объекта или администратор; **админ** — только администратор.
Все запросы, кроме GET, требуют заголовок `X-CSRF-Token` (токен отдаёт `GET /api/auth/me`).

| Метод | Путь | Доступ | Описание |
|---|---|---|---|
| GET | `/api/health` | гость | проверка работы API и БД |
| GET | `/api/auth/me` | гость | текущий пользователь (или `null`) и `csrf_token` |
| GET | `/api/categories` | гость | все разделы |
| GET | `/api/stats` | гость | число пользователей, публикаций, комментариев, лайков |
| GET | `/api/publications` | гость | лента: `sort=latest\|popular`, `category_id`, `page`, `per_page` |
| GET | `/api/publications/{id}` | гость | публикация целиком с комментариями |
| GET | `/api/search` | гость | поиск: `q`, `category_id`, `type`, `author`, `date_from`, `date_to`, `page`, `per_page` |
| POST | `/api/auth/register` | гость | регистрация и сразу вход |
| POST | `/api/auth/login` | гость | вход по username или email |
| POST | `/api/auth/logout` | любой | выход |
| POST | `/api/auth/forgot-password` | гость | письмо со ссылкой сброса (ответ всегда одинаковый) |
| POST | `/api/auth/reset-password` | гость | новый пароль по токену из письма |
| POST | `/api/publications` | вошёл | создать публикацию |
| PUT | `/api/publications/{id}` | автор/админ | изменить публикацию (все поля обязательны) |
| DELETE | `/api/publications/{id}` | автор/админ | удалить публикацию |
| POST | `/api/publications/{id}/like` | вошёл | поставить или убрать лайк |
| POST | `/api/publications/{id}/comments` | вошёл | добавить комментарий |
| DELETE | `/api/comments/{id}` | автор/админ | удалить комментарий (мягко, `deleted = 1`) |
| GET | `/api/admin/users` | админ | пользователи: `q`, `page`, `per_page` |
| POST | `/api/admin/users` | админ | создать пользователя или администратора |
| PATCH | `/api/admin/users/{id}` | админ | блокировка/разблокировка, выдача/снятие роли |
| POST | `/api/admin/categories` | админ | создать раздел |
| PUT | `/api/admin/categories/{id}` | админ | переименовать раздел |
| DELETE | `/api/admin/categories/{id}` | админ | удалить раздел (409, если в нём есть публикации) |

### Формат ошибок

```json
{"error": "Сообщение на русском"}
```

Ошибки проверки данных (422) содержат ещё и сообщения по полям:

```json
{"error": "Проверьте введённые данные", "errors": {"title": "Минимум 5 символов"}}
```

| Код | Когда |
|---|---|
| 400 | тело запроса — не валидный JSON |
| 401 | нужно войти в аккаунт |
| 403 | нет прав, неверный CSRF-токен, аккаунт заблокирован |
| 404 | маршрут или объект не найден |
| 405 | у маршрута нет такого метода |
| 409 | конфликт (удаление раздела с публикациями) |
| 422 | данные не прошли проверку |
| 500 | внутренняя ошибка (подробности в `docker compose logs app`; при `APP_DEBUG=true` в ответе есть поле `debug`) |

Списки возвращаются в виде `{"items": [...], "page": 1, "per_page": 10, "total": 42}`; `per_page` — от 1 до 50.

## Быстрая проверка через curl

```bash
# 1. Получить CSRF-токен; cookie сессии сохраняется в файл jar
TOKEN=$(curl -s -c jar -b jar http://localhost:8080/api/auth/me | python3 -c 'import sys,json; print(json.load(sys.stdin)["csrf_token"])')

# 2. Зарегистрироваться (после регистрации вход выполняется сразу)
curl -s -c jar -b jar -X POST http://localhost:8080/api/auth/register \
  -H "X-CSRF-Token: $TOKEN" -H 'Content-Type: application/json' \
  -d '{"username":"vovan","email":"v@example.com","password":"Passw0rd123","password_confirmation":"Passw0rd123"}'

# 3. Или войти, если пользователь уже есть
curl -s -c jar -b jar -X POST http://localhost:8080/api/auth/login \
  -H "X-CSRF-Token: $TOKEN" -H 'Content-Type: application/json' \
  -d '{"login":"vovan","password":"Passw0rd123"}'

# 4. Создать публикацию
curl -s -c jar -b jar -X POST http://localhost:8080/api/publications \
  -H "X-CSRF-Token: $TOKEN" -H 'Content-Type: application/json' \
  -d '{"title":"Когда экзамен по сетям?","text":"Подскажите дату и аудиторию.","category_id":1,"type":"question","allow_comments":true}'

# 5. Посмотреть ленту
curl -s http://localhost:8080/api/publications
```

Первого администратора через API создать нельзя. Выдайте роль напрямую в БД:

```bash
docker compose exec db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" -e "UPDATE users SET admin = 1 WHERE username = \"vovan\""'
```

Дальше администратор создаёт других администраторов через `POST /api/admin/users`.

## Безопасность

| Требование | Как закрыто |
|---|---|
| Пароли только в виде хеша | `password_hash(PASSWORD_DEFAULT)` при регистрации, смене пароля и создании пользователя админом; проверка через `password_verify`. Пароль и хеш не попадают ни в ответы, ни в логи |
| Сессии | Встроенные сессии PHP: cookie `forum_session`, `HttpOnly`, `SameSite=Lax`, `Secure` при HTTPS, `use_strict_mode`, `use_only_cookies`. В сессии только `user_id` и `csrf_token`. При входе и регистрации `session_regenerate_id(true)`; при выходе сессия и cookie уничтожаются |
| Нельзя обойти аутентификацию | Права проверяет `Router` до вызова контроллера (флаги `auth`/`admin`). Пользователь на каждом запросе заново загружается из БД: блокировка администратором действует сразу. Право «автор или админ» проверяется в контроллере (`Auth::canModify`) |
| CSRF | Токен `random_bytes(32)` хранится в сессии, отдаётся в `/api/auth/me`; каждый POST/PUT/PATCH/DELETE обязан прислать `X-CSRF-Token` (сравнение `hash_equals`). Проверка в одном месте — `Router::checkAccess` |
| SQL-инъекции | Только подготовленные выражения PDO (`EMULATE_PREPARES = false`) через `Database::query`; значения никогда не подставляются в текст запроса. `LIMIT/OFFSET` передаются как `PARAM_INT`; в `LIKE` экранируются `%` и `_`; порядок сортировки выбирается из фиксированного списка |
| Валидация всех входных данных | `Validator` проверяет тело запроса и query-параметры на сервере (тип, длина, формат, допустимые значения, уникальность). Строки очищаются от управляющих символов и обрезаются (`Request`) |
| XSS | API отдаёт только JSON (`Content-Type: application/json`, `X-Content-Type-Options: nosniff`) и хранит исходный текст; экранирование при выводе — на стороне фронтенда (Svelte) |
| Перебор логинов | Одинаковый ответ 401 для неверного пароля и несуществующего пользователя; `forgot-password` всегда отвечает одинаково |
| Сброс пароля | Токен `random_bytes(32)`, в БД только его SHA-256, срок 60 минут, одноразовый; новый запрос гасит прежние токены |
| Ошибки сервера | Подробности только в лог (`error_log`); клиент получает общее сообщение, `display_errors` выключен |
