<script>
	import Box from '../lib/components/Box.svelte';
	import Notice from '../lib/components/Notice.svelte';
	import Pagination from '../lib/components/Pagination.svelte';
	import PublicationTable from '../lib/components/PublicationTable.svelte';
	import { api } from '../lib/api.js';
	import { createResource } from '../lib/resource.svelte.js';
	import { router, buildUrl } from '../lib/router.svelte.js';

	// параметры списка хранятся в адресе: работают кнопка «Назад» и обычные ссылки
	let categoryId = $derived(router.query.get('category_id') ?? '');
	let sort = $derived(router.query.get('sort') ?? 'latest');

	const categories = createResource(() => api.get('/api/categories'));

	// fetcher читает адрес до первого await, поэтому при смене адреса список перезагружается сам
	const feed = createResource(() =>
		api.get('/api/publications', {
			category_id: router.query.get('category_id'),
			sort: router.query.get('sort'),
			page: router.query.get('page')
		})
	);

	// значения формы: пока пользователь не нажал «Показать», адрес не меняется
	let selectedCategory = $derived(categoryId);
	let selectedSort = $derived(sort);

	/** @param {SubmitEvent} event */
	function applyFilters(event) {
		event.preventDefault();
		router.navigate(buildUrl('/publications', { category_id: selectedCategory, sort: selectedSort === 'latest' ? '' : selectedSort }));
	}
</script>

<svelte:head><title>Лента — Студенческий форум</title></svelte:head>

<h1>Лента тем</h1>

<form class="filters" onsubmit={applyFilters}>
	<label>
		Раздел:
		<select bind:value={selectedCategory}>
			<option value="">Все разделы</option>
			{#each categories.data?.items ?? [] as category (category.id)}
				<option value={String(category.id)}>{category.name}</option>
			{/each}
		</select>
	</label>
	<label>
		Сортировка:
		<select bind:value={selectedSort}>
			<option value="latest">Новые</option>
			<option value="popular">Популярные</option>
		</select>
	</label>
	<button type="submit">Показать</button>
</form>

{#if feed.error}
	<Notice kind="error">{feed.error.message}</Notice>
{:else if !feed.data}
	<p>Загрузка…</p>
{:else}
	<Box title="Темы: {feed.data.total}">
		<PublicationTable items={feed.data.items}>
			{#snippet empty()}<p>В этом разделе пока нет тем. <a href="/publications/new">» Создать</a></p>{/snippet}
		</PublicationTable>
		<Pagination page={feed.data.page} perPage={feed.data.per_page} total={feed.data.total} />
	</Box>
{/if}

<style lang="scss">
	@use 'mixins' as *;

	.filters {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 8px 16px;
		margin-bottom: 12px;

		label {
			display: flex;
			align-items: center;
			gap: 6px;
		}

		@include mobile {
			flex-direction: column;
			align-items: stretch;

			label {
				flex-direction: column;
				align-items: stretch;
				gap: 2px;
			}

			button {
				width: 100%;
			}
		}
	}
</style>
