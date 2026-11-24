<script>
	import Notice from '../lib/components/Notice.svelte';
	import PublicationForm from '../lib/components/PublicationForm.svelte';
	import { api } from '../lib/api.js';
	import { createResource } from '../lib/resource.svelte.js';
	import { router } from '../lib/router.svelte.js';

	const categories = createResource(() => api.get('/api/categories'));

	/**
	 * Отправляет форму: создаёт публикацию и переходит на неё.
	 *
	 * @param {object} data Поля формы
	 */
	async function create(data) {
		const created = await api.post('/api/publications', data);
		router.navigate(`/publications/${created.id}`);
	}
</script>

<svelte:head><title>Новая тема — Студенческий форум</title></svelte:head>

<h1>Новая тема</h1>

{#if categories.error}
	<Notice kind="error">{categories.error.message}</Notice>
{:else if !categories.data}
	<p>Загрузка…</p>
{:else}
	<PublicationForm categories={categories.data.items} submitLabel="Опубликовать" cancelHref="/publications" onsubmit={create} />
{/if}
