// Свой роутер на рунах: History API + реактивный адрес SvelteURL.
// Роутер ничего не знает о страницах, поэтому циклических импортов нет.
import { SvelteURL } from 'svelte/reactivity';

class Router {
	/** Реактивный текущий адрес: всё, что его читает, обновляется само при смене адреса */
	url = new SvelteURL(window.location.href);

	/** Путь без query-строки, например `/publications/5` */
	get path() {
		return this.url.pathname;
	}

	/** Параметры адреса (`?page=2`), реактивные */
	get query() {
		return this.url.searchParams;
	}

	/**
	 * Переходит на другой адрес без перезагрузки страницы.
	 *
	 * @param {string} to Адрес вида `/publications/5?x=1`
	 * @param {{ replace?: boolean }} [options] replace — заменить запись в истории, а не добавлять новую
	 */
	navigate(to, { replace = false } = {}) {
		const target = new URL(to, window.location.href);

		// переход на тот же адрес не должен засорять историю
		if (replace || target.href === this.url.href) {
			history.replaceState(null, '', target);
		} else {
			history.pushState(null, '', target);
		}

		this.url.href = target.href;
		window.scrollTo(0, 0);
	}

	/**
	 * Подключает роутер к браузеру. Вызывается один раз при запуске приложения.
	 * После этого во всём приложении достаточно писать обычные `<a href="/publications/5">`.
	 */
	start() {
		// кнопки «Назад» и «Вперёд»
		window.addEventListener('popstate', () => {
			this.url.href = window.location.href;
		});

		// один обработчик на весь документ: внутренние ссылки становятся переходами роутера
		document.addEventListener('click', (event) => {
			if (event.defaultPrevented || event.button !== 0) return;
			if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;

			const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
			if (!link || !(link instanceof HTMLAnchorElement)) return;

			const isOwnPage = link.origin === window.location.origin;
			const isApi = link.pathname.startsWith('/api');
			const opensElsewhere = link.target !== '' && link.target !== '_self';
			if (!isOwnPage || isApi || opensElsewhere || link.hasAttribute('download')) return;

			event.preventDefault();
			this.navigate(link.pathname + link.search + link.hash);
		});
	}
}

export const router = new Router();

/**
 * Ищет маршрут по пути. `:id` совпадает только с цифрами, `*` — с любым путём.
 *
 * @template {{ path: string }} R
 * @param {R[]} routes Таблица маршрутов (порядок важен)
 * @param {string} pathname Путь, например `/publications/5`
 * @returns {{ route: R | undefined, params: Record<string, number> }} Найденный маршрут и параметры (числа)
 */
export function matchRoute(routes, pathname) {
	// слэш в конце не важен: /search/ = /search
	const path = pathname.length > 1 ? pathname.replace(/\/+$/, '') : pathname;

	for (const route of routes) {
		if (route.path === '*') return { route, params: {} };

		const pattern = route.path.replace(/:(\w+)/g, '(?<$1>\\d+)');
		const match = new RegExp(`^${pattern}$`).exec(path);
		if (match) {
			const params = Object.fromEntries(
				Object.entries(match.groups ?? {}).map(([name, value]) => [name, Number(value)])
			);
			return { route, params };
		}
	}

	return { route: undefined, params: {} };
}

/**
 * Собирает адрес с query-строкой. Пустые значения пропускаются.
 *
 * @param {string} path Путь, например `/search`
 * @param {Record<string, string | number | null | undefined>} [params] Параметры
 * @returns {string} Например `/search?q=сессия&page=2`
 */
export function buildUrl(path, params = {}) {
	const search = new URLSearchParams();
	for (const [name, value] of Object.entries(params)) {
		if (value !== null && value !== undefined && value !== '') search.set(name, String(value));
	}
	const text = search.toString();
	return text ? `${path}?${text}` : path;
}

/**
 * Возвращает безопасный адрес для перехода после входа. Принимается только внутренний путь,
 * начинающийся с одного `/` (защита от открытого редиректа на чужой сайт).
 *
 * @param {string | null} value Значение параметра `redirect`
 * @returns {string} Внутренний адрес или `/`
 */
export function safeRedirect(value) {
	const isInternal = value && value.startsWith('/') && !value.startsWith('//') && !value.startsWith('/\\');
	return isInternal ? value : '/';
}
