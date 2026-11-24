<script>
	/**
	 * «Окно» с синей шапкой. Заголовок — строка или snippet (если в нём нужны ссылки).
	 *
	 * @type {{ title: string | import('svelte').Snippet, children: import('svelte').Snippet }}
	 */
	let { title, children } = $props();
</script>

<section class="box">
	<h2 class="box-title">
		{#if typeof title === 'string'}
			{title}
		{:else}
			{@render title()}
		{/if}
	</h2>
	<div class="box-body">
		{@render children()}
	</div>
</section>

<style lang="scss">
	@use 'tokens' as *;
	@use 'mixins' as *;

	.box {
		margin-bottom: 12px;
		border: 1px solid $color-border;
		background: $color-paper;
	}

	.box-title {
		@include title-bar;
		margin: 0;
		padding: 3px 8px;
		font-family: $font-main;
		font-size: $font-size-base;

		@include mobile {
			font-size: $font-size-mobile;
		}

		// ссылки в шапке должны быть читаемы на синем фоне
		:global(a),
		:global(a:visited) {
			color: $color-header-text;
		}
	}

	.box-body {
		padding: 8px;
	}
</style>
