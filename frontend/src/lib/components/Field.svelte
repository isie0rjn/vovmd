<script module>
	/**
	 * Вложение для `{@attach autofocus}`: ставит фокус на элемент при появлении на странице.
	 *
	 * @param {HTMLElement} element Поле формы
	 */
	export function autofocus(element) {
		element.focus();
	}
</script>

<script>
	/**
	 * Подпись + поле ввода + сообщение об ошибке. Поддерживает text, password, email, date,
	 * textarea (type="textarea") и select (type="select", варианты передаются как children).
	 * Остальные атрибуты (name, autocomplete, maxlength, {@attach ...}) попадают на само поле.
	 *
	 * @type {{
	 *   label: string,
	 *   value?: any,
	 *   type?: string,
	 *   error?: string,
	 *   hint?: string,
	 *   required?: boolean,
	 *   children?: import('svelte').Snippet,
	 *   [key: string]: any
	 * }}
	 */
	let {
		label,
		value = $bindable(''),
		type = 'text',
		error = '',
		hint = '',
		required = false,
		children,
		...rest
	} = $props();

	// уникальный id: связывает <label for>, подсказку и сообщение об ошибке
	const id = $props.id();

	let describedBy = $derived([hint ? `${id}-hint` : '', error ? `${id}-error` : ''].filter(Boolean).join(' ') || undefined);
</script>

<div class="field">
	<label for={id}>
		{label}
		{#if required}<span class="required" aria-hidden="true">*</span>{/if}
	</label>

	{#if type === 'textarea'}
		<textarea {id} rows="8" {required} aria-invalid={error ? 'true' : undefined} aria-describedby={describedBy} bind:value {...rest}
		></textarea>
		{#if rest.maxlength}
			<p class="counter muted">{[...String(value ?? '')].length} / {rest.maxlength}</p>
		{/if}
	{:else if type === 'select'}
		<select {id} {required} aria-invalid={error ? 'true' : undefined} aria-describedby={describedBy} bind:value {...rest}>
			{@render children?.()}
		</select>
	{:else}
		<input {id} {type} {required} aria-invalid={error ? 'true' : undefined} aria-describedby={describedBy} bind:value {...rest} />
	{/if}

	{#if hint}<p id="{id}-hint" class="hint muted">{hint}</p>{/if}
	{#if error}<p id="{id}-error" class="error">{error}</p>{/if}
</div>

<style lang="scss">
	@use 'tokens' as *;

	.field {
		margin-bottom: 10px;
	}

	label {
		display: block;
		margin-bottom: 2px;
		font-weight: bold;
	}

	.required {
		color: $color-error-text;
	}

	[aria-invalid='true'] {
		border-color: $color-error-text;
	}

	.hint,
	.counter {
		margin: 2px 0 0;
	}

	.error {
		margin: 2px 0 0;
		color: $color-error-text;
		font-weight: bold;
	}
</style>
