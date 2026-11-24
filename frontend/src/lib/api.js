// Обёртка над fetch для общения с REST API: JSON, CSRF-заголовок, единый формат ошибок.
import { session } from './session.svelte.js';
import { buildUrl } from './router.svelte.js';

/** Сообщение сервера, когда CSRF-токен устарел */
const STALE_SESSION_MESSAGE = 'Сессия устарела, обновите страницу';

/** Ошибка запроса к API */
export class ApiError extends Error {
	/**
	 * @param {number} status HTTP-код (0 — сервер недоступен)
	 * @param {string} message Понятное пользователю сообщение
	 * @param {Record<string, string>} [errors] Ошибки по полям (для кода 422)
	 */
	constructor(status, message, errors = {}) {
		super(message);
		this.status = status;
		this.errors = errors;
	}
}

/**
 * Отправляет запрос к API и возвращает разобранный JSON.
 *
 * @param {string} method HTTP-метод
 * @param {string} path Путь, например `/api/publications`
 * @param {{ query?: Record<string, any>, body?: any }} [options] query — параметры адреса, body — тело (будет JSON)
 * @param {boolean} [isRetry] Повторный запрос после обновления сессии
 * @returns {Promise<any>} Тело успешного ответа
 * @throws {ApiError} Если ответ неуспешный или сервер недоступен
 */
async function request(method, path, { query, body } = {}, isRetry = false) {
	/** @type {Record<string, string>} */
	const headers = { Accept: 'application/json' };

	if (method !== 'GET') {
		headers['X-CSRF-Token'] = session.csrfToken;
		if (body !== undefined) headers['Content-Type'] = 'application/json';
	}

	let response;
	try {
		response = await fetch(buildUrl(path, query), {
			method,
			headers,
			credentials: 'same-origin',
			body: body === undefined ? undefined : JSON.stringify(body)
		});
	} catch {
		throw new ApiError(0, 'Сервер недоступен, попробуйте позже');
	}

	const data = await response.json().catch(() => null);
	if (response.ok) return data;

	const message = data?.error ?? 'Не удалось выполнить запрос';

	// CSRF-токен устарел: один раз обновляем сессию и повторяем запрос
	if (response.status === 403 && message === STALE_SESSION_MESSAGE && !isRetry) {
		await session.refresh();
		return request(method, path, { query, body }, true);
	}

	// сессия закончилась на сервере: считаем пользователя гостем (App отправит на страницу входа)
	if (response.status === 401 && session.user !== null) {
		session.user = null;
	}

	throw new ApiError(response.status, message, data?.errors ?? {});
}

export const api = {
	/** @param {string} path @param {Record<string, any>} [query] */
	get: (path, query) => request('GET', path, { query }),
	/** @param {string} path @param {any} [body] */
	post: (path, body) => request('POST', path, { body }),
	/** @param {string} path @param {any} [body] */
	put: (path, body) => request('PUT', path, { body }),
	/** @param {string} path @param {any} [body] */
	patch: (path, body) => request('PATCH', path, { body }),
	/** @param {string} path */
	del: (path) => request('DELETE', path)
};
