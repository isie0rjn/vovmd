<script>
	/**
	 * Сообщение для пользователя.
	 *
	 * @type {{ kind?: 'error' | 'success' | 'info', children: import('svelte').Snippet }}
	 */
	let { kind = 'info', children } = $props();
</script>

<p class={['notice', kind]} role={kind === 'error' ? 'alert' : 'status'}>
	{@render children()}
</p>

<style lang="scss">
	@use 'tokens' as *;

	.notice {
		margin: 0 0 10px;
		padding: 6px 8px;
		border: 1px solid;
	}

	.error {
		color: $color-error-text;
		background: $color-error-bg;
		border-color: $color-error-text;
	}

	.success {
		color: $color-success-text;
		background: $color-success-bg;
		border-color: $color-success-text;
	}

	.info {
		color: $color-info-text;
		background: $color-info-bg;
		border-color: $color-header;
	}
</style>
