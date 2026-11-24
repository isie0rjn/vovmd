<script>
	import Box from '../lib/components/Box.svelte';
	import Field from '../lib/components/Field.svelte';
	import Notice from '../lib/components/Notice.svelte';
	import ErrorPage from './ErrorPage.svelte';
	import { api, ApiError } from '../lib/api.js';
	import { createResource } from '../lib/resource.svelte.js';
	import { router, buildUrl } from '../lib/router.svelte.js';
	import { session } from '../lib/session.svelte.js';
	import { formatDate, isNew, typeLabel } from '../lib/format.js';
	import { validateComment } from '../lib/validation.js';

	/** @type {{ params: { id: number } }} */
	let { params } = $props();

	// params.id читается до await, поэтому при переходе на другую публикацию данные загрузятся заново
	const publication = createResource(() => api.get(`/api/publications/${params.id}`));

	let actionError = $state('');
	let commentText = $state('');
	let commentTouched = $state(false);
	let commentSubmitted = $state(false);
	let commentSending = $state(false);
	let commentServerError = $state('');

	let commentErrors = $derived(validateComment({ text: commentText }));
	let commentError = $derived(commentServerError || (commentTouched || commentSubmitted ? (commentErrors.text ?? '') : ''));

	/** Поставить или убрать лайк */
	async function toggleLike() {
		actionError = '';
		try {
			const result = await api.post(`/api/publications/${params.id}/like`);
			publication.data.likes_count = result.likes_count;
			publication.data.liked_by_me = result.liked;
		} catch (error) {
			actionError = error.message;
		}
	}

	/** Удалить публикацию после подтверждения и вернуться в ленту */
	async function removePublication() {
		if (!confirm('Удалить эту тему вместе с комментариями?')) return;
		actionError = '';
		try {
			await api.del(`/api/publications/${params.id}`);
			router.navigate('/publications');
		} catch (error) {
			actionError = error.message;
		}
	}

	/**
	 * Удалить комментарий после подтверждения.
	 *
	 * @param {number} commentId Идентификатор комментария
	 */
	async function removeComment(commentId) {
		if (!confirm('Удалить комментарий?')) return;
		actionError = '';
		try {
			await api.del(`/api/comments/${commentId}`);
			await publication.reload();
		} catch (error) {
			actionError = error.message;
		}
	}

	/** @param {SubmitEvent} event */
	async function addComment(event) {
		event.preventDefault();
		commentSubmitted = true;
		commentServerError = '';
		actionError = '';
		if (commentErrors.text) return;

		commentSending = true;
		try {
			await api.post(`/api/publications/${params.id}/comments`, { text: commentText.trim() });
			commentText = '';
			commentTouched = false;
			commentSubmitted = false;
			await publication.reload();
		} catch (error) {
			if (error instanceof ApiError && error.errors.text) commentServerError = error.errors.text;
			else actionError = error.message;
		} finally {
			commentSending = false;
		}
	}
</script>

<svelte:head>
	<title>{publication.data?.title ?? 'Тема'} — Студенческий форум</title>
</svelte:head>

{#if publication.error}
	{#if publication.error.status === 404}
		<ErrorPage status={404} message="Такой темы не существует или она была удалена." />
	{:else}
		<Notice kind="error">{publication.error.message}</Notice>
	{/if}
{:else if !publication.data}
	<p>Загрузка…</p>
{:else}
	{@const item = publication.data}

	<article>
		<h1>
			{item.title}
			{#if isNew(item.created_at)}<span class="new-badge">NEW!</span>{/if}
		</h1>

		<p class="meta muted">
			{typeLabel(item.type)} ·
			<a href={buildUrl('/publications', { category_id: item.category.id })}>{item.category.name}</a> ·
			автор <a href={buildUrl('/search', { author: item.author.username })}>{item.author.username}</a> ·
			{formatDate(item.created_at)}
		</p>

		<div class="text">{item.text}</div>

		{#if actionError}<Notice kind="error">{actionError}</Notice>{/if}

		<div class="actions">
			{#if session.isLoggedIn}
				<button type="button" onclick={toggleLike} aria-pressed={item.liked_by_me}>
					♥ {item.liked_by_me ? 'Вам нравится' : 'Нравится'} ({item.likes_count})
				</button>
			{:else}
				<span>♥ {item.likes_count} · <a href={buildUrl('/login', { redirect: router.path })}>Войдите, чтобы оценить</a></span>
			{/if}

			{#if item.can_edit}<a class="button" href="/publications/{item.id}/edit">✎ Изменить</a>{/if}
			{#if item.can_delete}<button type="button" onclick={removePublication}>[x] Удалить</button>{/if}
		</div>
	</article>

	<Box title="Комментарии: {item.comments_count}">
		{#if item.comments.length === 0}
			<p>Комментариев пока нет.</p>
		{/if}

		<ul class="comments">
			{#each item.comments as comment (comment.id)}
				<li>
					<p class="muted">
						<strong>{comment.author.username}</strong> · {formatDate(comment.created_at)}
						{#if comment.can_delete}
							· <button type="button" class="link-button" onclick={() => removeComment(comment.id)}>[x] Удалить</button>
						{/if}
					</p>
					<div class="text">{comment.text}</div>
				</li>
			{/each}
		</ul>

		<hr />

		{#if !item.allow_comments}
			<Notice>Автор отключил комментарии к этой теме.</Notice>
		{:else if !session.isLoggedIn}
			<p><a href={buildUrl('/login', { redirect: router.path })}>Войдите</a>, чтобы оставить комментарий.</p>
		{:else}
			<form novalidate onsubmit={addComment} onfocusout={() => (commentTouched = true)} oninput={() => (commentServerError = '')}>
				<Field
					label="Ваш комментарий"
					type="textarea"
					name="text"
					bind:value={commentText}
					error={commentError}
					required
					maxlength="2000"
					rows="4"
				/>
				<div class="form-actions">
					<button type="submit" disabled={commentSending}>{commentSending ? 'Отправка…' : 'Отправить'}</button>
				</div>
			</form>
		{/if}
	</Box>
{/if}

<style lang="scss">
	@use 'tokens' as *;
	@use 'mixins' as *;

	h1 {
		margin-bottom: 4px;
	}

	.meta {
		margin-bottom: 12px;
	}

	// переносы строк из текста сохраняются; HTML не интерпретируется
	.text {
		margin-bottom: 12px;
		white-space: pre-wrap;
		overflow-wrap: anywhere;
	}

	.actions {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 8px 12px;
		margin-bottom: 16px;

		@include mobile {
			flex-direction: column;
			align-items: stretch;

			:global(.button),
			button {
				display: block;
				text-align: center;
				text-decoration: none;
			}
		}
	}

	.button {
		display: inline-block;
		color: $color-text;
		text-decoration: none;

		&:visited,
		&:hover {
			color: $color-text;
		}
	}

	.comments {
		margin: 0;
		padding: 0;
		list-style: none;

		li {
			padding: 6px 0;
			border-bottom: 1px dotted $color-border;
		}

		p {
			margin-bottom: 2px;
		}
	}

	// кнопка, оформленная как ссылка
	.link-button {
		min-height: 0;
		padding: 0;
		border: 0;
		background: none;
		color: $color-link;
		text-decoration: underline;

		&:hover {
			color: $color-link-hover;
		}
	}
</style>
