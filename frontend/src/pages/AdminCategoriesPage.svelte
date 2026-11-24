<script>
	import Box from '../lib/components/Box.svelte';
	import Field from '../lib/components/Field.svelte';
	import Notice from '../lib/components/Notice.svelte';
	import { api, ApiError } from '../lib/api.js';
	import { createResource } from '../lib/resource.svelte.js';
	import { validateCategory } from '../lib/validation.js';

	const categories = createResource(() => api.get('/api/categories'));

	/** Сообщение об успехе или ошибке последнего действия */
	let notice = $state({ kind: 'info', text: '' });
	/** Ошибки проверки по строкам: { [id категории]: сообщение } */
	let rowErrors = $state({});

	// ---- переименование и удаление ----

	/**
	 * Сохраняет новое название раздела прямо из строки списка.
	 *
	 * @param {{ id: number, name: string }} category Раздел (название уже изменено в поле)
	 */
	async function rename(category) {
		notice = { kind: 'info', text: '' };
		delete rowErrors[category.id];

		const problem = validateCategory({ name: category.name }).name;
		if (problem) {
			rowErrors[category.id] = problem;
			return;
		}

		try {
			await api.put(`/api/admin/categories/${category.id}`, { name: category.name.trim() });
			notice = { kind: 'success', text: 'Название сохранено.' };
		} catch (error) {
			rowErrors[category.id] = (error instanceof ApiError && error.errors.name) || error.message;
		}
	}

	/**
	 * Удаляет раздел после подтверждения. Если в нём есть публикации, сервер ответит 409.
	 *
	 * @param {{ id: number, name: string }} category Раздел
	 */
	async function remove(category) {
		if (!confirm(`Удалить раздел «${category.name}»?`)) return;
		notice = { kind: 'info', text: '' };

		try {
			await api.del(`/api/admin/categories/${category.id}`);
			notice = { kind: 'success', text: 'Раздел удалён.' };
			await categories.reload();
		} catch (error) {
			notice = {
				kind: 'error',
				text: error.status === 409 ? `Раздел «${category.name}» нельзя удалить: в нём есть публикации.` : error.message
			};
		}
	}

	// ---- создание ----
	let newName = $state('');
	let touched = $state(false);
	let submitted = $state(false);
	let submitting = $state(false);
	let serverError = $state('');

	let createErrors = $derived(validateCategory({ name: newName }));
	let createError = $derived(serverError || (touched || submitted ? (createErrors.name ?? '') : ''));

	/** @param {SubmitEvent} event */
	async function create(event) {
		event.preventDefault();
		submitted = true;
		serverError = '';
		notice = { kind: 'info', text: '' };
		if (createErrors.name) return;

		submitting = true;
		try {
			await api.post('/api/admin/categories', { name: newName.trim() });
			notice = { kind: 'success', text: `Раздел «${newName.trim()}» создан.` };
			newName = '';
			touched = false;
			submitted = false;
			await categories.reload();
		} catch (error) {
			if (error instanceof ApiError && error.errors.name) serverError = error.errors.name;
			else notice = { kind: 'error', text: error.message };
		} finally {
			submitting = false;
		}
	}
</script>

<svelte:head><title>Разделы — Студенческий форум</title></svelte:head>

<h1>Разделы</h1>
<p><a href="/admin">« Админка</a></p>

{#if notice.text}<Notice kind={notice.kind}>{notice.text}</Notice>{/if}

{#if categories.error}
	<Notice kind="error">{categories.error.message}</Notice>
{:else if !categories.data}
	<p>Загрузка…</p>
{:else}
	<Box title="Список разделов">
		<ul class="rows">
			{#each categories.data.items as category (category.id)}
				<li>
					<form novalidate onsubmit={(event) => { event.preventDefault(); rename(category); }}>
						<Field
							label="Название раздела"
							name="name"
							bind:value={category.name}
							error={rowErrors[category.id] ?? ''}
							maxlength="100"
						/>
						<div class="buttons">
							<button type="submit">Сохранить</button>
							<button type="button" onclick={() => remove(category)}>[x] Удалить</button>
						</div>
					</form>
				</li>
			{/each}
		</ul>
	</Box>
{/if}

<Box title="Новый раздел">
	<form class="form-narrow" novalidate onsubmit={create} onfocusout={() => (touched = true)} oninput={() => (serverError = '')}>
		<Field label="Название" name="name" bind:value={newName} error={createError} required maxlength="100" hint="От 2 до 100 символов" />
		<div class="form-actions">
			<button type="submit" disabled={submitting}>{submitting ? 'Создание…' : 'Создать'}</button>
		</div>
	</form>
</Box>

<style lang="scss">
	@use 'tokens' as *;
	@use 'mixins' as *;

	.rows {
		margin: 0;
		padding: 0;
		list-style: none;

		li {
			padding: 6px 0;
			border-bottom: 1px dotted $color-border;
		}

		form {
			display: flex;
			align-items: flex-end;
			gap: 8px;

			:global(.field) {
				flex: 1;
				margin-bottom: 0;
			}

			@include mobile {
				flex-direction: column;
				align-items: stretch;
			}
		}
	}

	.buttons {
		display: flex;
		gap: 4px;

		@include mobile {
			flex-direction: column;

			button {
				width: 100%;
			}
		}
	}
</style>
