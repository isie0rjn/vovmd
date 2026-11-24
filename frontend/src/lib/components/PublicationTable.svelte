<script>
	import { buildUrl } from '../router.svelte.js';
	import { formatDate, isNew, typeLabel } from '../format.js';

	/**
	 * Список публикаций: таблица на компьютере, карточки на телефоне.
	 *
	 * @type {{
	 *   items: Array<{
	 *     id: number, title: string, type: string, created_at: string,
	 *     author: { id: number, username: string }, category: { id: number, name: string },
	 *     likes_count: number, comments_count: number
	 *   }>,
	 *   empty?: import('svelte').Snippet
	 * }}
	 */
	let { items, empty } = $props();
</script>

{#if items.length === 0}
	{#if empty}
		{@render empty()}
	{:else}
		<p>Здесь пока ничего нет.</p>
	{/if}
{:else}
	<table class="data">
		<thead>
			<tr>
				<th scope="col">Тема</th>
				<th scope="col">Раздел</th>
				<th scope="col">Автор</th>
				<th scope="col"><abbr title="Ответов">Отв.</abbr></th>
				<th scope="col"><span aria-label="Нравится">♥</span></th>
				<th scope="col">Дата</th>
			</tr>
		</thead>
		<tbody>
			{#each items as item (item.id)}
				<tr>
					<td>
						<a href="/publications/{item.id}">{item.title}</a>
						{#if isNew(item.created_at)}<span class="new-badge">NEW!</span>{/if}
						<br /><span class="muted">{typeLabel(item.type)}</span>
					</td>
					<td data-label="Раздел">
						<a href={buildUrl('/publications', { category_id: item.category.id })}>{item.category.name}</a>
					</td>
					<td data-label="Автор">
						<a href={buildUrl('/search', { author: item.author.username })}>{item.author.username}</a>
					</td>
					<td class="num" data-label="Ответов">{item.comments_count}</td>
					<td class="num" data-label="Нравится">{item.likes_count}</td>
					<td data-label="Дата">{formatDate(item.created_at)}</td>
				</tr>
			{/each}
		</tbody>
	</table>
{/if}
