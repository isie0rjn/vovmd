// Единый способ загружать данные на страницах.

/**
 * Загружает данные и автоматически перезагружает их, когда меняется что-то,
 * что fetcher прочитал синхронно (например, router.query или params.id).
 * Во время перезагрузки старые данные остаются на экране, устаревшие ответы игнорируются.
 * Вызывать только при инициализации компонента.
 *
 * @template T
 * @param {() => Promise<T> | null} fetcher Функция, возвращающая промис с данными (или null — ничего не грузить)
 */
export function createResource(fetcher) {
	let data = $state(null);
	let error = $state(null);
	let loading = $state(true);
	let lastRequest = 0;

	async function run() {
		const request = ++lastRequest;
		loading = true;
		error = null;
		try {
			const result = await fetcher();
			if (request === lastRequest) data = result;
		} catch (e) {
			if (request === lastRequest) error = e;
		} finally {
			if (request === lastRequest) loading = false;
		}
	}

	$effect(() => {
		run();
	});

	return {
		get data() {
			return data;
		},
		get error() {
			return error;
		},
		get loading() {
			return loading;
		},
		reload: run
	};
}
