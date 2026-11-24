<script>
	import Box from '../lib/components/Box.svelte';
	import Field, { autofocus } from '../lib/components/Field.svelte';
	import Notice from '../lib/components/Notice.svelte';
	import { api, ApiError } from '../lib/api.js';
	import { validateForgotPassword } from '../lib/validation.js';

	let values = $state({ email: '' });
	let touched = $state(false);
	let submitted = $state(false);
	let submitting = $state(false);
	let serverError = $state('');
	let formError = $state('');
	/** Сообщение сервера после успешной отправки (оно одинаково для любого email) */
	let doneMessage = $state('');

	let errors = $derived(validateForgotPassword(values));
	let emailError = $derived(serverError || (touched || submitted ? (errors.email ?? '') : ''));

	/** @param {SubmitEvent} event */
	async function handleSubmit(event) {
		event.preventDefault();
		submitted = true;
		formError = '';
		serverError = '';
		if (errors.email) return;

		submitting = true;
		try {
			const result = await api.post('/api/auth/forgot-password', { email: values.email.trim() });
			doneMessage = result.message;
		} catch (error) {
			if (error instanceof ApiError && error.errors.email) serverError = error.errors.email;
			else formError = error.message;
		} finally {
			submitting = false;
		}
	}
</script>

<svelte:head><title>Восстановление пароля — Студенческий форум</title></svelte:head>

<h1>Забыли пароль?</h1>

<Box title="Восстановление пароля">
	{#if doneMessage}
		<Notice kind="success">{doneMessage}</Notice>
		<p>Письмо приходит в течение минуты. Ссылка действует 60 минут.</p>
		<p><a href="/login">» Вернуться ко входу</a></p>
	{:else}
		<p>Введите email, указанный при регистрации. Мы отправим на него ссылку для создания нового пароля.</p>

		<form
			class="form-narrow"
			novalidate
			onsubmit={handleSubmit}
			onfocusout={() => (touched = true)}
			oninput={() => (serverError = '')}
		>
			{#if formError}<Notice kind="error">{formError}</Notice>{/if}

			<Field
				label="Email"
				type="email"
				name="email"
				autocomplete="email"
				bind:value={values.email}
				error={emailError}
				required
				maxlength="255"
				{@attach autofocus}
			/>

			<div class="form-actions">
				<button type="submit" disabled={submitting}>{submitting ? 'Отправка…' : 'Отправить ссылку'}</button>
			</div>
		</form>

		<p><a href="/login">« Назад ко входу</a></p>
	{/if}
</Box>
