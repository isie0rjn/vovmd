<script>
	import Box from '../lib/components/Box.svelte';
	import Field, { autofocus } from '../lib/components/Field.svelte';
	import Notice from '../lib/components/Notice.svelte';
	import { ApiError } from '../lib/api.js';
	import { router, safeRedirect } from '../lib/router.svelte.js';
	import { session } from '../lib/session.svelte.js';
	import { validateRegister } from '../lib/validation.js';

	let values = $state({ username: '', email: '', password: '', password_confirmation: '' });
	let touched = $state({});
	let submitted = $state(false);
	let submitting = $state(false);
	let serverErrors = $state({});
	let formError = $state('');

	let errors = $derived(validateRegister(values));

	/**
	 * Какую ошибку показать у поля: серверную или клиентскую (после ухода из поля / попытки отправки).
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

		// адрес возврата запоминаем до запроса: после входа App сам меняет адрес, и параметра redirect уже не будет
		const target = safeRedirect(router.query.get('redirect'));

		submitting = true;
		try {
			await session.register({
				username: values.username.trim(),
				email: values.email.trim(),
				password: values.password,
				password_confirmation: values.password_confirmation
			});
			router.navigate(target, { replace: true });
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

<svelte:head><title>Регистрация — Студенческий форум</title></svelte:head>

<h1>Регистрация</h1>

<Box title="Новый аккаунт">
	<form class="form-narrow" novalidate onsubmit={handleSubmit} onfocusout={markTouched} oninput={clearServerError}>
		{#if formError}<Notice kind="error">{formError}</Notice>{/if}

		<Field
			label="Имя пользователя"
			name="username"
			autocomplete="username"
			bind:value={values.username}
			error={errorFor('username')}
			hint="3–50 символов: латинские буквы, цифры и _"
			required
			minlength="3"
			maxlength="50"
			{@attach autofocus}
		/>
		<Field
			label="Email"
			type="email"
			name="email"
			autocomplete="email"
			bind:value={values.email}
			error={errorFor('email')}
			hint="На него придёт ссылка, если вы забудете пароль"
			required
			maxlength="255"
		/>
		<Field
			label="Пароль"
			type="password"
			name="password"
			autocomplete="new-password"
			bind:value={values.password}
			error={errorFor('password')}
			hint="8–72 символа, минимум одна буква и одна цифра"
			required
			minlength="8"
			maxlength="72"
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
			<button type="submit" disabled={submitting}>{submitting ? 'Регистрация…' : 'Зарегистрироваться'}</button>
		</div>
	</form>

	<p>Уже есть аккаунт? <a href="/login">Войти</a></p>
</Box>
