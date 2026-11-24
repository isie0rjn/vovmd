// Таблица маршрутов: адрес → страница и требования к доступу. Порядок важен: берётся первое совпадение.
// `:id` совпадает только с цифрами, поэтому /publications/new не путается с /publications/:id.
import HomePage from './pages/HomePage.svelte';
import FeedPage from './pages/FeedPage.svelte';
import PublicationPage from './pages/PublicationPage.svelte';
import PublicationNewPage from './pages/PublicationNewPage.svelte';
import PublicationEditPage from './pages/PublicationEditPage.svelte';
import SearchPage from './pages/SearchPage.svelte';
import LoginPage from './pages/LoginPage.svelte';
import RegisterPage from './pages/RegisterPage.svelte';
import ForgotPasswordPage from './pages/ForgotPasswordPage.svelte';
import ResetPasswordPage from './pages/ResetPasswordPage.svelte';
import AdminPage from './pages/AdminPage.svelte';
import AdminUsersPage from './pages/AdminUsersPage.svelte';
import AdminCategoriesPage from './pages/AdminCategoriesPage.svelte';
import ErrorPage from './pages/ErrorPage.svelte';

/**
 * @typedef {object} Route
 * @property {string} path Путь с параметрами `:id`
 * @property {import('svelte').Component<any>} page Компонент страницы
 * @property {boolean} [auth] Только для вошедших
 * @property {boolean} [admin] Только для администраторов
 * @property {boolean} [guestOnly] Вошедшего пользователя отправляют на главную
 * @property {Record<string, any>} [props] Дополнительные props страницы
 */

/** @type {Route[]} */
export const routes = [
	{ path: '/', page: HomePage },
	{ path: '/publications', page: FeedPage },
	{ path: '/publications/new', page: PublicationNewPage, auth: true },
	{ path: '/publications/:id', page: PublicationPage },
	{ path: '/publications/:id/edit', page: PublicationEditPage, auth: true },
	{ path: '/search', page: SearchPage },
	{ path: '/login', page: LoginPage, guestOnly: true },
	{ path: '/register', page: RegisterPage, guestOnly: true },
	{ path: '/forgot-password', page: ForgotPasswordPage },
	{ path: '/reset-password', page: ResetPasswordPage },
	{ path: '/admin', page: AdminPage, admin: true },
	{ path: '/admin/users', page: AdminUsersPage, admin: true },
	{ path: '/admin/categories', page: AdminCategoriesPage, admin: true },
	// любой другой адрес
	{ path: '*', page: ErrorPage, props: { status: 404 } }
];
