<script>
	import Box from '../lib/components/Box.svelte';
	import Field from '../lib/components/Field.svelte';
	import Notice from '../lib/components/Notice.svelte';
	import Pagination from '../lib/components/Pagination.svelte';
	import PublicationTable from '../lib/components/PublicationTable.svelte';
	import { api } from '../lib/api.js';
	import { createResource } from '../lib/resource.svelte.js';
	import { router, buildUrl } from '../lib/router.svelte.js';
	import { publicationTypes } from '../lib/format.js';
	import { validateSearch } from '../lib/validation.js';

	const searchFields = ['q', 'category_id', 'type', 'author', 'date_from', 'date_to'];

	const categories = createResource(() => api.get('/api/categories'));

	// Поиск выполняется по параметрам адреса. Отправка формы только меняет адрес.
	const results = createResource(() =>
		api.get('/api/search', Object.fromEntries([...searchFields, 'page'].map((name) => [name, router.query.get(name)])))
	);

	// Значения формы берутся из адреса, но их можно редактировать (производное состояние с возможностью записи)
	let q = $derived(router.query.get('q') ?? '');
	let categoryId = $derived(router.query.get('category_id') ?? '');
	let type = $derived(router.query.get('type') ?? '');
	let author = $derived(router.query.get('author') ?? '');
	let dateFrom = $derived(router.query.get('date_from') ?? '');
	let dateTo = $derived(router.query.get('date_to') ?? '');

	let submitted = $state(false);
	let touched = $state({});

	let errors = $derived(validateSearch({ q, author, date_from: dateFrom, date_to: dateTo }));

	/**
	 * @param {string} field Имя поля
	 * @returns {string}
	 */
	function errorFor(field) {
		return touched[field] || submitted ? (errors[field] ?? '') : '';
	}

	/** @param {SubmitEvent} event */
	function search(event) {
		event.preventDefault();
		submitted = true;
		if (Object.keys(errors).length > 0) return;

		router.navigate(
			buildUrl('/search', { q, category_id: categoryId, type, author, date_from: dateFrom, date_to: dateTo })
		);
	}

	/** @param {FocusEvent} event */
	function markTouched(event) {
		const name = /** @type {HTMLInputElement} */ (event.target).name;
		if (name) touched[name] = true;
	}

	// ошибки проверки от сервера (например, если адрес набрали вручную)
	let serverErrorText = $derived(
		results.error?.status === 422 ? Object.values(results.error.errors).join('. ') || results.error.message : ''
	);
</script>

<svelte:head><title>Поиск — Студенческий форум</title></svelte:head>

<h1>Поиск</h1>

<Box title="Что ищем?">
	<form novalidate onsubmit={search} onfocusout={markTouched}>
		<div class="grid">
			<Field
				label="Слова"
				name="q"
				bind:value={q}
				error={errorFor('q')}
				hint="От 3 символов, по заголовку и тексту"
				maxlength="100"
			/>
			<Field label="Автор" name="author" bind:value={author} error={errorFor('author')} hint="Точное имя пользователя" maxlength="255" />
			<Field label="Раздел" type="select" name="category_id" bind:value={categoryId}>
				<option value="">Любой</option>
				{#each categories.data?.items ?? [] as category (category.id)}
					<option value={String(category.id)}>{category.name}</option>
				{/each}
			</Field>
			<Field label="Тип" type="select" name="type" bind:value={type}>
				<option value="">Любой</option>
				{#each publicationTypes as option (option.value)}
					<option value={option.value}>{option.label}</option>
				{/each}
			</Field>
			<Field label="Дата с" type="date" name="date_from" bind:value={dateFrom} />
			<Field label="Дата по" type="date" name="date_to" bind:value={dateTo} error={errorFor('date_to')} />
		</div>
		<div class="form-actions">
			<button type="submit">Найти</button>
			<a href="/search">Сбросить</a>
		</div>
	</form>
</Box>

{#if serverErrorText}
	<Notice kind="error">{serverErrorText}</Notice>
{:else if results.error}
	<Notice kind="error">{results.error.message}</Notice>
{:else if results.data}
	<p>Найдено: <strong>{results.data.total}</strong></p>
	<PublicationTable items={results.data.items}>
		{#snippet empty()}<p>Ничего не найдено. Попробуйте изменить условия поиска.</p>{/snippet}
	</PublicationTable>
	<Pagination page={results.data.page} perPage={results.data.per_page} total={results.data.total} />
{:else}
	<p>Загрузка…</p>
{/if}

<style lang="scss">
	@use 'mixins' as *;

	.grid {
		display: grid;
		grid-template-columns: 1fr 1fr;
		gap: 0 16px;

		@include mobile {
			grid-template-columns: 1fr;
		}
	}
</style>
