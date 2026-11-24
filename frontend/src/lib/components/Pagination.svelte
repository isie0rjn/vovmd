<script>
	import { router, buildUrl } from '../router.svelte.js';

	/**
	 * Постраничная навигация «« Пред. 1 2 3 След. »». Номер страницы хранится в адресе (?page=2),
	 * остальные параметры адреса сохраняются.
	 *
	 * @type {{ page: number, perPage: number, total: number }}
	 */
	let { page, perPage, total } = $props();

	let pageCount = $derived(Math.max(1, Math.ceil(total / perPage)));

	// показываем первую, последнюю и соседние с текущей страницы; пропуски обозначаем многоточием
	let pages = $derived.by(() => {
		const visible = [];
		for (let number = 1; number <= pageCount; number++) {
			if (number === 1 || number === pageCount || Math.abs(number - page) <= 2) visible.push(number);
		}
		return visible;
	});

	/**
	 * Адрес текущей страницы с другим номером страницы.
	 *
	 * @param {number} number Номер страницы
	 * @returns {string}
	 */
	function hrefFor(number) {
		const params = Object.fromEntries(router.query);
		params.page = number === 1 ? '' : String(number);
		return buildUrl(router.path, params);
	}
</script>

{#if pageCount > 1}
	<nav class="pagination" aria-label="Страницы">
		{#if page > 1}
			<a href={hrefFor(page - 1)}>«« Пред.</a>
		{/if}

		{#each pages as number, index (number)}
			{#if index > 0 && number - pages[index - 1] > 1}<span aria-hidden="true">…</span>{/if}
			{#if number === page}
				<strong aria-current="page">[{number}]</strong>
			{:else}
				<a href={hrefFor(number)}>{number}</a>
			{/if}
		{/each}

		{#if page < pageCount}
			<a href={hrefFor(page + 1)}>След. »»</a>
		{/if}
	</nav>
{/if}

<style lang="scss">
	@use 'mixins' as *;
	@use 'tokens' as *;

	.pagination {
		display: flex;
		flex-wrap: wrap;
		gap: 4px 10px;
		margin: 10px 0;

		a,
		strong {
			// на телефоне зона нажатия не меньше 40px
			@include mobile {
				display: inline-block;
				min-width: $tap-size;
				padding: 8px 4px;
				text-align: center;
			}
		}
	}
</style>
