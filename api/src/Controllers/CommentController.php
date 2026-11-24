<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\Comment;
use App\Models\Publication;

/**
 * Комментарии к публикациям.
 */
class CommentController
{
    /**
     * POST /api/publications/{id}/comments — добавление комментария.
     *
     * @param array<string, int> $params Параметры пути (id публикации)
     * @return void
     */
    public function store(array $params): void
    {
        $data = Request::body();

        $validator = (new Validator($data))->required('text')->length('text', 1, 2000);
        if ($validator->fails()) {
            Response::validationError($validator->errors());
        }

        $publication = Publication::findBasic($params['id']);
        if ($publication === null) {
            Response::error('Публикация не найдена', 404);
        }
        if (!$publication['allow_comments']) {
            Response::error('Комментарии к этой публикации отключены', 403);
        }

        $commentId = Comment::create(Auth::user()['id'], $params['id'], $data['text']);
        $comment = Comment::findById($commentId);
        unset($comment['deleted']);
        $comment['can_delete'] = true;

        Response::json($comment, 201);
    }

    /**
     * DELETE /api/comments/{id} — мягкое удаление автором или администратором.
     *
     * @param array<string, int> $params Параметры пути (id комментария)
     * @return void
     */
    public function destroy(array $params): void
    {
        $comment = Comment::findById($params['id']);

        if ($comment === null || $comment['deleted']) {
            Response::error('Комментарий не найден', 404);
        }
        if (!Auth::canModify($comment['author']['id'])) {
            Response::error('Недостаточно прав', 403);
        }

        Comment::softDelete($params['id']);

        Response::json(['message' => 'Комментарий удалён']);
    }
}
