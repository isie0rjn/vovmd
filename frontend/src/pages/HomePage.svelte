<script>
	import Box from '../lib/components/Box.svelte';
	import Notice from '../lib/components/Notice.svelte';
	import PublicationTable from '../lib/components/PublicationTable.svelte';
	import { api } from '../lib/api.js';
	import { createResource } from '../lib/resource.svelte.js';
	import { session } from '../lib/session.svelte.js';
	import { buildUrl } from '../lib/router.svelte.js';
	import { padCounter } from '../lib/format.js';

	// все четыре запроса идут параллельно
	const home = createResource(async () => {
		const [stats, categories, latest, popular] = await Promise.all([
			api.get('/api/stats'),
			api.get('/api/categories'),
			api.get('/api/publications', { per_page: 10 }),
			api.get('/api/publications', { sort: 'popular', per_page: 5 })
		]);
		return { stats, categories: categories.items, latest: latest.items, popular: popular.items };
	});

	const counters = [
		['users', 'Пользователей'],
		['publications', 'Тем'],
		['comments', 'Комментариев'],
		['likes', 'Оценок']
	];
</script>

<svelte:head><title>Главная — Студенческий форум</title></svelte:head>

{#if home.error}
	<Notice kind="error">{home.error.message}</Notice>
{:else if !home.data}
	<p>Загрузка…</p>
{:else}
	<div class="top">
		<Box title="Добро пожаловать">
			<p>
				Здесь студенты задают вопросы, обсуждают учёбу и сессию и делятся новостями общежития и мероприятий.
			</p>
			{#if session.isLoggedIn}
				<p>Рады видеть вас снова, {session.user?.username}! <a href="/publications/new">» Создать тему</a></p>
			{:else}
				<p>
					Читать можно без регистрации, а чтобы писать, <a href="/login">войдите</a> или
					<a href="/register">зарегистрируйтесь</a>.
				</p>
			{/if}
		</Box>

		<Box title="Статистика">
			<dl class="counter">
				{#each counters as [key, label] (key)}
					<div>
						<dt>{label}</dt>
						<dd>{padCounter(home.data.stats[key])}</dd>
					</div>
				{/each}
			</dl>
		</Box>
	</div>

	<Box title="Разделы">
		<ul class="categories">
			{#each home.data.categories as category (category.id)}
				<li>» <a href={buildUrl('/publications', { category_id: category.id })}>{category.name}</a></li>
			{/each}
		</ul>
	</Box>

	<Box title="Последние темы">
		<PublicationTable items={home.data.latest}>
			{#snippet empty()}<p>Тем пока нет. Станьте первым!</p>{/snippet}
		</PublicationTable>
		<p><a href="/publications">» Все темы</a></p>
	</Box>

	<Box title="Популярное">
		<PublicationTable items={home.data.popular}>
			{#snippet empty()}<p>Пока нет популярных тем.</p>{/snippet}
		</PublicationTable>
	</Box>
{/if}

<style lang="scss">
	@use 'tokens' as *;
	@use 'mixins' as *;

	.top {
		display: grid;
		grid-template-columns: 1fr 240px;
		gap: 12px;

		@include mobile {
			grid-template-columns: 1fr;
			gap: 0;
		}
	}

	// счётчик посещений: зелёные цифры на чёрном
	.counter {
		margin: 0;

		div {
			display: flex;
			justify-content: space-between;
			align-items: center;
			padding: 2px 0;
		}

		dt {
			font-size: 12px;
		}

		dd {
			margin: 0;
			padding: 0 6px;
			background: $color-counter-bg;
			color: $color-counter-text;
			font: bold 15px $font-mono;
			letter-spacing: 1px;
		}
	}

	.categories {
		display: flex;
		flex-wrap: wrap;
		gap: 4px 20px;
		margin: 0;
		padding: 0;
		list-style: none;

		a {
			display: inline-block;
			@include mobile {
				padding: 8px 0;
			}
		}
	}
</style>
