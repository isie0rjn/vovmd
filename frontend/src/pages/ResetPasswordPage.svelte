<script>
	import Box from '../lib/components/Box.svelte';
	import Field, { autofocus } from '../lib/components/Field.svelte';
	import Notice from '../lib/components/Notice.svelte';
	import { api, ApiError } from '../lib/api.js';
	import { router } from '../lib/router.svelte.js';
	import { validateResetPassword } from '../lib/validation.js';

	// токен приходит в ссылке из письма: /reset-password?token=...
	let token = $derived(router.query.get('token') ?? '');

	let values = $state({ password: '', password_confirmation: '' });
	let touched = $state({});
	let submitted = $state(false);
	let submitting = $state(false);
	let serverErrors = $state({});
	let formError = $state('');
	let doneMessage = $state('');

	let errors = $derived(validateResetPassword(values));

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
	async function handleSubmit(event) {
		event.preventDefault();
		submitted = true;
		formError = '';
		if (Object.keys(errors).length > 0) return;

		submitting = true;
		try {
			const result = await api.post('/api/auth/reset-password', { token, ...values });
			doneMessage = result.message;
		} catch (error) {
			if (error instanceof ApiError && error.status === 422) {
				serverErrors = error.errors;
				// ошибка токена относится не к полю формы, а ко всей ссылке
				if (error.errors.token) formError = error.errors.token;
			} else {
				formError = error.message;
			}
		} finally {
			submitting = false;
		}
	}
</script>

<svelte:head><title>Новый пароль — Студенческий форум</title></svelte:head>

<h1>Новый пароль</h1>

<Box title="Создание нового пароля">
	{#if doneMessage}
		<Notice kind="success">{doneMessage}</Notice>
		<p><a href="/login">» Войти с новым паролем</a></p>
	{:else if !token}
		<Notice kind="error">В ссылке нет токена. Откройте ссылку из письма целиком или запросите новую.</Notice>
		<p><a href="/forgot-password">» Забыли пароль?</a></p>
	{:else}
		<form class="form-narrow" novalidate onsubmit={handleSubmit} onfocusout={markTouched} oninput={clearServerError}>
			{#if formError}
				<Notice kind="error">{formError}</Notice>
				{#if serverErrors.token}<p><a href="/forgot-password">» Запросить новую ссылку</a></p>{/if}
			{/if}

			<Field
				label="Новый пароль"
				type="password"
				name="password"
				autocomplete="new-password"
				bind:value={values.password}
				error={errorFor('password')}
				hint="8–72 символа, минимум одна буква и одна цифра"
				required
				minlength="8"
				maxlength="72"
				{@attach autofocus}
			/>
			<Field
				label="Повторите пароль"
				type="password"
				name="password_confirmation"
				autocomplete="new-password"
				bind:value={values.password_confirmation}
				error={errorFor('password_confirmation')}
				required
			/>

			<div class="form-actions">
				<button type="submit" disabled={submitting}>{submitting ? 'Сохранение…' : 'Сохранить пароль'}</button>
			</div>
		</form>
	{/if}
</Box>
