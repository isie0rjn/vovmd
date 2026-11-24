<script>
	import Box from '../lib/components/Box.svelte';
	import Field from '../lib/components/Field.svelte';
	import Notice from '../lib/components/Notice.svelte';
	import Pagination from '../lib/components/Pagination.svelte';
	import { api, ApiError } from '../lib/api.js';
	import { createResource } from '../lib/resource.svelte.js';
	import { router, buildUrl } from '../lib/router.svelte.js';
	import { session } from '../lib/session.svelte.js';
	import { validateNewUser, validateUserSearch } from '../lib/validation.js';

	// список зависит от адреса: ?q=...&page=...
	const users = createResource(() =>
		api.get('/api/admin/users', { q: router.query.get('q'), page: router.query.get('page') })
	);

	let search = $derived(router.query.get('q') ?? '');
	let searchErrors = $derived(validateUserSearch({ q: search }));
	let actionError = $state('');

	/** @param {SubmitEvent} event */
	function runSearch(event) {
		event.preventDefault();
		if (searchErrors.q) return;
		router.navigate(buildUrl('/admin/users', { q: search }));
	}

	/**
	 * Меняет флаги пользователя и обновляет его строку из ответа сервера.
	 *
	 * @param {{ id: number }} user Строка таблицы
	 * @param {{ active?: boolean, admin?: boolean }} changes Что изменить
	 */
	async function updateUser(user, changes) {
		actionError = '';
		try {
			const result = await api.patch(`/api/admin/users/${user.id}`, changes);
			Object.assign(user, result.user);
		} catch (error) {
			actionError = error.message;
		}
	}

	// ---- форма создания пользователя ----
	let values = $state({ username: '', email: '', password: '', admin: false });
	let touched = $state({});
	let submitted = $state(false);
	let submitting = $state(false);
	let serverErrors = $state({});
	let formError = $state('');
	let createdMessage = $state('');

	let errors = $derived(validateNewUser(values));

	/**
	 * @param {string} field Имя поля
	 * @returns {string}
	 */
	function errorFor(field) {
		return serverErrors[field] ?? (touched[field] || submitted ? (errors[field] ?? '') : '');
	}

	/** @param {FocusEvent} event */
	function markTouched(event) {
		const name = /** @type {HTMLInputElement} */ (event.target).name;
		if (name) touched[name] = true;
	}

	/** @param {Event} event */
	function clearServerError(event) {
		delete serverErrors[/** @type {HTMLInputElement} */ (event.target).name];
	}

	/** @param {SubmitEvent} event */
	async function createUser(event) {
		event.preventDefault();
		submitted = true;
		formError = '';
		createdMessage = '';
		if (Object.keys(errors).length > 0) return;

		submitting = true;
		try {
			const result = await api.post('/api/admin/users', {
				username: values.username.trim(),
				email: values.email.trim(),
				password: values.password,
				admin: values.admin
			});
			createdMessage = `Пользователь ${result.user.username} создан.`;
			values = { username: '', email: '', password: '', admin: false };
			touched = {};
			submitted = false;
			await users.reload();
		} catch (error) {
			if (error instanceof ApiError && error.status === 422) {
				serverErrors = error.errors;
				if (!Object.keys(error.errors).some((field) => field in values)) formError = error.message;
			} else {
				formError = error.message;
			}
		} finally {
			submitting = false;
		}
	}
</script>

<svelte:head><title>Пользователи — Студенческий форум</title></svelte:head>

<h1>Пользователи</h1>
<p><a href="/admin">« Админка</a></p>

<form class="search" novalidate onsubmit={runSearch}>
	<Field label="Поиск по имени или email" name="q" bind:value={search} error={searchErrors.q ?? ''} maxlength="100" />
	<button type="submit">Найти</button>
</form>

{#if actionError}<Notice kind="error">{actionError}</Notice>{/if}

{#if users.error}
	<Notice kind="error">{users.error.message}</Notice>
{:else if !users.data}
	<p>Загрузка…</p>
{:else}
	<Box title="Всего: {users.data.total}">
		{#if users.data.items.length === 0}
			<p>Никого не найдено.</p>
		{:else}
			<table class="data">
				<thead>
					<tr>
						<th scope="col">Имя</th>
						<th scope="col">Email</th>
						<th scope="col">Роль</th>
						<th scope="col">Статус</th>
						<th scope="col"><abbr title="Публикаций">Тем</abbr></th>
						<th scope="col">Действия</th>
					</tr>
				</thead>
				<tbody>
					{#each users.data.items as user (user.id)}
						{@const isMe = user.id === session.user?.id}
						<tr>
							<td>{user.username}{isMe ? ' (вы)' : ''}</td>
							<td data-label="Email">{user.email}</td>
							<td data-label="Роль">{user.admin ? 'Администратор' : 'Пользователь'}</td>
							<td data-label="Статус">{user.active ? 'Активен' : 'Заблокирован'}</td>
							<td class="num" data-label="Тем">{user.publications_count}</td>
							<td class="actions">
								<button type="button" disabled={isMe} onclick={() => updateUser(user, { active: !user.active })}>
									{user.active ? 'Заблокировать' : 'Разблокировать'}
								</button>
								<button type="button" disabled={isMe} onclick={() => updateUser(user, { admin: !user.admin })}>
									{user.admin ? 'Снять админа' : 'Сделать админом'}
								</button>
							</td>
						</tr>
					{/each}
				</tbody>
			</table>
			<Pagination page={users.data.page} perPage={users.data.per_page} total={users.data.total} />
		{/if}
	</Box>
{/if}

<Box title="Создать пользователя">
	<form class="form-narrow" novalidate onsubmit={createUser} onfocusout={markTouched} oninput={clearServerError}>
		{#if createdMessage}<Notice kind="success">{createdMessage}</Notice>{/if}
		{#if formError}<Notice kind="error">{formError}</Notice>{/if}

		<Field label="Имя пользователя" name="username" bind:value={values.username} error={errorFor('username')} required maxlength="50" />
		<Field label="Email" type="email" name="email" bind:value={values.email} error={errorFor('email')} required maxlength="255" />
		<Field
			label="Пароль"
			type="password"
			name="password"
			autocomplete="new-password"
			bind:value={values.password}
			error={errorFor('password')}
			hint="8–72 символа, минимум одна буква и одна цифра"
			required
			maxlength="72"
		/>
		<label class="choice">
			<input type="checkbox" name="admin" bind:checked={values.admin} />
			Администратор
		</label>

		<div class="form-actions">
			<button type="submit" disabled={submitting}>{submitting ? 'Создание…' : 'Создать'}</button>
		</div>
	</form>
</Box>

<style lang="scss">
	@use 'mixins' as *;

	.search {
		display: flex;
		align-items: flex-end;
		gap: 8px;
		margin-bottom: 12px;

		:global(.field) {
			flex: 1;
			margin-bottom: 0;
		}

		@include mobile {
			flex-direction: column;
			align-items: stretch;

			button {
				width: 100%;
			}
		}
	}

	.actions {
		display: flex;
		flex-wrap: wrap;
		gap: 4px;

		@include mobile {
			flex-direction: column;

			button {
				width: 100%;
			}
		}
	}
</style>
