<script>
	import Notice from '../lib/components/Notice.svelte';
	import PublicationForm from '../lib/components/PublicationForm.svelte';
	import ErrorPage from './ErrorPage.svelte';
	import { api } from '../lib/api.js';
	import { createResource } from '../lib/resource.svelte.js';
	import { router } from '../lib/router.svelte.js';

	/** @type {{ params: { id: number } }} */
	let { params } = $props();

	// публикация и список разделов загружаются параллельно
	const page = createResource(async () => {
		const [publication, categories] = await Promise.all([
			api.get(`/api/publications/${params.id}`),
			api.get('/api/categories')
		]);
		return { publication, categories: categories.items };
	});

	/**
	 * Отправляет форму: сохраняет изменения и возвращается к публикации.
	 *
	 * @param {object} data Поля формы
	 */
	async function save(data) {
		await api.put(`/api/publications/${params.id}`, data);
		router.navigate(`/publications/${params.id}`);
	}
</script>

<svelte:head><title>Изменить тему — Студенческий форум</title></svelte:head>

{#if page.error}
	{#if page.error.status === 404}
		<ErrorPage status={404} message="Такой темы не существует или она была удалена." />
	{:else}
		<Notice kind="error">{page.error.message}</Notice>
	{/if}
{:else if !page.data}
	<p>Загрузка…</p>
{:else if !page.data.publication.can_edit}
	<ErrorPage status={403} message="Изменять эту тему может только её автор или администратор." />
{:else}
	<h1>Изменить тему</h1>
	<PublicationForm
		categories={page.data.categories}
		initial={{
			title: page.data.publication.title,
			text: page.data.publication.text,
			category_id: page.data.publication.category.id,
			type: page.data.publication.type,
			allow_comments: page.data.publication.allow_comments
		}}
		submitLabel="Сохранить"
		cancelHref="/publications/{params.id}"
		onsubmit={save}
	/>
{/if}
