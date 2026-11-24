<script>
	/**
	 * Страница ошибки в духе 2000-х: крупный код и ссылка на главную.
	 *
	 * @type {{ status?: number, message?: string }}
	 */
	let { status = 404, message = '' } = $props();

	const defaultMessages = {
		403: 'Недостаточно прав для просмотра этой страницы.',
		404: 'Такой страницы не существует. Возможно, она была удалена или вы ошиблись адресом.',
		500: 'Что-то сломалось на сервере. Попробуйте зайти позже.'
	};

	let text = $derived(message || defaultMessages[status] || 'Произошла ошибка.');
</script>

<svelte:head><title>Ошибка {status} — Студенческий форум</title></svelte:head>

<div class="error-page">
	<h1>Ошибка {status}</h1>
	<p>{text}</p>
	<p><a href="/">» На главную</a></p>
</div>

<style lang="scss">
	@use 'tokens' as *;

	.error-page {
		padding: 20px 0;
		text-align: center;
	}

	h1 {
		margin-bottom: 16px;
		color: $color-error-text;
		font-size: 40px;
	}
</style>
