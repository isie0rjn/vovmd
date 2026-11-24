<script>
	import Box from '../lib/components/Box.svelte';
	import Field, { autofocus } from '../lib/components/Field.svelte';
	import Notice from '../lib/components/Notice.svelte';
	import { router, safeRedirect, buildUrl } from '../lib/router.svelte.js';
	import { session } from '../lib/session.svelte.js';
	import { validateLogin } from '../lib/validation.js';

	let values = $state({ login: '', password: '' });
	let touched = $state({});
	let submitted = $state(false);
	let submitting = $state(false);
	let formError = $state('');

	let errors = $derived(validateLogin(values));

	/**
	 * @param {string} field Имя поля
	 * @returns {string}
	 */
	function errorFor(field) {
		return touched[field] || submitted ? (errors[field] ?? '') : '';
	}

	/** @param {FocusEvent} event */
	function markTouched(event) {
		const name = /** @type {HTMLInputElement} */ (event.target).name;
		if (name) touched[name] = true;
	}

	/** @param {SubmitEvent} event */
	async function handleSubmit(event) {
		event.preventDefault();
		submitted = true;
		formError = '';
		if (Object.keys(errors).length > 0) return;

		// адрес возврата запоминаем до запроса: после входа App сам меняет адрес, и параметра redirect уже не будет
		const target = safeRedirect(router.query.get('redirect'));

		submitting = true;
		try {
			await session.login(values.login.trim(), values.password);
			// возвращаем пользователя туда, откуда его отправили на вход (только внутренний адрес)
			router.navigate(target, { replace: true });
		} catch (error) {
			formError = error.message;
		} finally {
			submitting = false;
		}
	}
</script>

<svelte:head><title>Вход — Студенческий форум</title></svelte:head>

<h1>Вход</h1>

<Box title="Войти в аккаунт">
	<form class="form-narrow" novalidate onsubmit={handleSubmit} onfocusout={markTouched}>
		{#if formError}<Notice kind="error">{formError}</Notice>{/if}

		<Field
			label="Имя пользователя или email"
			name="login"
			autocomplete="username"
			bind:value={values.login}
			error={errorFor('login')}
			required
			{@attach autofocus}
		/>
		<Field
			label="Пароль"
			type="password"
			name="password"
			autocomplete="current-password"
			bind:value={values.password}
			error={errorFor('password')}
			required
		/>

		<div class="form-actions">
			<button type="submit" disabled={submitting}>{submitting ? 'Вход…' : 'Войти'}</button>
		</div>
	</form>

	<p>
		<a href="/forgot-password">Забыли пароль?</a> ·
		<a href={buildUrl('/register', { redirect: router.query.get('redirect') })}>Регистрация</a>
	</p>
</Box>
