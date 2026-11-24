<?php

declare(strict_types=1);

/**
 * Точка входа REST API: настройки, сессия, обработчик ошибок и список всех маршрутов.
 */

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\CommentController;
use App\Controllers\PublicationController;
use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

require __DIR__ . '/../vendor/autoload.php';

ini_set('display_errors', '0');
header_remove('X-Powered-By');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

// Любое необработанное исключение: подробности в лог, клиенту — общее сообщение
set_exception_handler(function (Throwable $error): never {
    error_log((string) $error);

    $extra = getenv('APP_DEBUG') === 'true' ? ['debug' => $error->getMessage()] : [];
    Response::error('Внутренняя ошибка сервера', 500, $extra);
});

Auth::start();

$router = new Router();

// --- Публичные ---
$router->get('/api/health', [PublicationController::class, 'health']);
$router->get('/api/auth/me', [AuthController::class, 'me']);
$router->get('/api/categories', [PublicationController::class, 'categories']);
$router->get('/api/stats', [PublicationController::class, 'stats']);
$router->get('/api/publications', [PublicationController::class, 'index']);
$router->get('/api/publications/{id}', [PublicationController::class, 'show']);
$router->get('/api/search', [PublicationController::class, 'search']);

// --- Аккаунт ---
$router->post('/api/auth/register', [AuthController::class, 'register']);
$router->post('/api/auth/login', [AuthController::class, 'login']);
$router->post('/api/auth/logout', [AuthController::class, 'logout']);
$router->post('/api/auth/forgot-password', [AuthController::class, 'forgotPassword']);
$router->post('/api/auth/reset-password', [AuthController::class, 'resetPassword']);

// --- Пользователь (нужен вход) ---
$router->post('/api/publications', [PublicationController::class, 'store'], auth: true);
$router->put('/api/publications/{id}', [PublicationController::class, 'update'], auth: true);
$router->delete('/api/publications/{id}', [PublicationController::class, 'destroy'], auth: true);
$router->post('/api/publications/{id}/like', [PublicationController::class, 'like'], auth: true);
$router->post('/api/publications/{id}/comments', [CommentController::class, 'store'], auth: true);
$router->delete('/api/comments/{id}', [CommentController::class, 'destroy'], auth: true);

// --- Администратор ---
$router->get('/api/admin/users', [AdminController::class, 'users'], admin: true);
$router->post('/api/admin/users', [AdminController::class, 'createUser'], admin: true);
$router->patch('/api/admin/users/{id}', [AdminController::class, 'updateUser'], admin: true);
$router->post('/api/admin/categories', [AdminController::class, 'createCategory'], admin: true);
$router->put('/api/admin/categories/{id}', [AdminController::class, 'updateCategory'], admin: true);
$router->delete('/api/admin/categories/{id}', [AdminController::class, 'deleteCategory'], admin: true);

$router->dispatch(Request::method(), Request::path());
