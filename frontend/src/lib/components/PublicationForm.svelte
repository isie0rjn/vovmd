<script>
	import { untrack } from 'svelte';
	import Field from './Field.svelte';
	import Notice from './Notice.svelte';
	import { ApiError } from '../api.js';
	import { publicationTypes } from '../format.js';
	import { validatePublication } from '../validation.js';

	/**
	 * Общая форма создания и редактирования публикации. Пять полей разных типов:
	 * text, textarea, select, radio и checkbox.
	 *
	 * @type {{
	 *   categories: Array<{ id: number, name: string }>,
	 *   initial?: { title: string, text: string, category_id: number, type: string, allow_comments: boolean },
	 *   submitLabel: string,
	 *   cancelHref: string,
	 *   onsubmit: (data: { title: string, text: string, category_id: number, type: string, allow_comments: boolean }) => Promise<void>
	 * }}
	 */
	let { categories, initial, submitLabel, cancelHref, onsubmit } = $props();

	// форма работает с копией значений (читаем props один раз, поэтому untrack):
	// исходные данные не меняются, пока пользователь не нажал «Сохранить»
	let values = $state(
		untrack(() => ({
			title: initial?.title ?? '',
			text: initial?.text ?? '',
			category_id: initial?.category_id ?? '',
			type: initial?.type ?? 'discussion',
			allow_comments: initial?.allow_comments ?? true
		}))
	);

	let touched = $state({});
	let submitted = $state(false);
	let submitting = $state(false);
	/** Ошибки по полям, пришедшие от сервера (422) */
	let serverErrors = $state({});
	/** Общая ошибка (не относится к конкретному полю) */
	let formError = $state('');

	let errors = $derived(validatePublication(values));
	let canSubmit = $derived(Object.keys(errors).length === 0 && !submitting);

	/**
	 * Какую ошибку показать у поля: сначала серверную, затем клиентскую
	 * (клиентская — только после ухода из поля или попытки отправки).
	 *
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

	/** Пользователь изменил поле — серверная ошибка для него больше неактуальна */
	function clearServerError(/** @type {Event} */ event) {
		delete serverErrors[/** @type {HTMLInputElement} */ (event.target).name];
	}

	/** @param {SubmitEvent} event */
	async function handleSubmit(event) {
		event.preventDefault();
		submitted = true;
		formError = '';
		if (!canSubmit) return;

		submitting = true;
		try {
			await onsubmit({
				title: values.title.trim(),
				text: values.text.trim(),
				category_id: Number(values.category_id),
				type: values.type,
				allow_comments: values.allow_comments
			});
		} catch (error) {
			if (error instanceof ApiError && error.status === 422) {
				serverErrors = error.errors;
				// ошибки, которых нет среди полей формы, показываем общим сообщением
				if (!Object.keys(error.errors).some((field) => field in values)) formError = error.message;
			} else {
				formError = error instanceof Error ? error.message : 'Не удалось сохранить';
			}
		} finally {
			submitting = false;
		}
	}
</script>

<form novalidate onsubmit={handleSubmit} onfocusout={markTouched} oninput={clearServerError}>
	{#if formError}<Notice kind="error">{formError}</Notice>{/if}

	<Field
		label="Заголовок"
		name="title"
		bind:value={values.title}
		error={errorFor('title')}
		hint="От 5 до 255 символов"
		required
		minlength="5"
		maxlength="255"
	/>

	<Field
		label="Текст"
		type="textarea"
		name="text"
		bind:value={values.text}
		error={errorFor('text')}
		hint="От 10 до 10000 символов"
		required
		minlength="10"
		maxlength="10000"
	/>

	<Field label="Раздел" type="select" name="category_id" bind:value={values.category_id} error={errorFor('category_id')} required>
		<option value="" disabled>— выберите раздел —</option>
		{#each categories as category (category.id)}
			<option value={category.id}>{category.name}</option>
		{/each}
	</Field>

	<fieldset aria-describedby={errorFor('type') ? 'type-error' : undefined}>
		<legend>Тип</legend>
		{#each publicationTypes as option (option.value)}
			<label class="choice">
				<input type="radio" name="type" value={option.value} bind:group={values.type} />
				{option.label}
			</label>
		{/each}
		{#if errorFor('type')}<p id="type-error" class="field-error">{errorFor('type')}</p>{/if}
	</fieldset>

	<label class="choice">
		<input type="checkbox" name="allow_comments" bind:checked={values.allow_comments} />
		Разрешить комментарии
	</label>
	{#if errorFor('allow_comments')}<p class="field-error">{errorFor('allow_comments')}</p>{/if}

	<div class="form-actions">
		<button type="submit" disabled={submitting}>{submitting ? 'Сохранение…' : submitLabel}</button>
		<a href={cancelHref}>Отмена</a>
	</div>
</form>

<style lang="scss">
	@use 'tokens' as *;

	.field-error {
		margin: 2px 0 0;
		color: $color-error-text;
		font-weight: bold;
	}
</style>
