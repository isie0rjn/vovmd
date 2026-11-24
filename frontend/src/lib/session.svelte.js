// Текущий пользователь и CSRF-токен. Авторизация — только cookie-сессия API, ничего не хранится в localStorage.
import { api } from './api.js';

class Session {
	/** @type {{ id: number, username: string, email: string, admin: boolean, active: boolean } | null} */
	user = $state(null);
	/** CSRF-токен, который API требует в заголовке всех запросов, кроме GET */
	csrfToken = $state('');
	/** Загружено ли состояние сессии с сервера (до этого App показывает «Загрузка…») */
	loaded = $state(false);

	isLoggedIn = $derived(this.user !== null);
	isAdmin = $derived(this.user?.admin === true);

	/**
	 * Запрашивает у сервера текущего пользователя и свежий CSRF-токен.
	 * Использует fetch напрямую, а не api.js: api.js сам вызывает refresh() при устаревшем токене,
	 * и через него получился бы бесконечный повтор.
	 */
	async refresh() {
		try {
			const response = await fetch('/api/auth/me', { credentials: 'same-origin' });
			const data = await response.json();
			this.user = data.user;
			this.csrfToken = data.csrf_token;
		} catch {
			// сервер недоступен: остаёмся гостем, api.js покажет понятную ошибку при первом запросе
			this.user = null;
		} finally {
			this.loaded = true;
		}
	}

	/**
	 * Вход по имени пользователя или email.
	 *
	 * @param {string} login Имя пользователя или email
	 * @param {string} password Пароль
	 * @throws {import('./api.js').ApiError} 401 — неверные данные, 403 — аккаунт заблокирован
	 */
	async login(login, password) {
		await api.post('/api/auth/login', { login, password });
		await this.refresh();
	}

	/**
	 * Регистрация; после неё пользователь сразу вошёл.
	 *
	 * @param {{ username: string, email: string, password: string, password_confirmation: string }} data Поля формы
	 * @throws {import('./api.js').ApiError} 422 — ошибки полей в `errors`
	 */
	async register(data) {
		await api.post('/api/auth/register', data);
		await this.refresh();
	}

	/** Выход. Сессия на сервере уничтожается, поэтому нужен новый CSRF-токен. */
	async logout() {
		await api.post('/api/auth/logout');
		await this.refresh();
	}
}

// api.js и session.svelte.js импортируют друг друга, но обращаются друг к другу только внутри функций,
// поэтому порядок загрузки модулей не важен.
export const session = new Session();
