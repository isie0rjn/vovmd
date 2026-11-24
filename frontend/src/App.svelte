<script>
	import { onMount } from 'svelte';
	import { router, matchRoute, buildUrl, safeRedirect } from './lib/router.svelte.js';
	import { session } from './lib/session.svelte.js';
	import { routes } from './routes.js';
	import ErrorPage from './pages/ErrorPage.svelte';

	// при старте узнаём у сервера, кто вошёл, и получаем CSRF-токен
	onMount(() => {
		session.refresh();
	});

	let match = $derived(matchRoute(routes, router.path));
	let route = $derived(match.route ?? routes[routes.length - 1]);
	// в Svelte 5 компонент из переменной выводится напрямую: <Page />
	let Page = $derived(route.page);

	// Защита страниц на клиенте — только удобство для пользователя, настоящую защиту делает API.
	let needsLogin = $derived((route.auth || route.admin) && !session.isLoggedIn);
	let isForbidden = $derived(route.admin === true && session.isLoggedIn && !session.isAdmin);

	// Единственное место, где нужен $effect (кроме createResource): переходы, зависящие от состояния сессии
	$effect(() => {
		if (!session.loaded) return;

		if (needsLogin) {
			router.navigate(buildUrl('/login', { redirect: router.path + router.url.search }), { replace: true });
		} else if (route.guestOnly && session.isLoggedIn) {
			router.navigate(safeRedirect(router.query.get('redirect')), { replace: true });
		}
	});

	/**
	 * Подсвечивается ли пункт меню.
	 *
	 * @param {string} href Адрес пункта
	 * @returns {boolean}
	 */
	function isActive(href) {
		return href === '/admin' ? router.path.startsWith('/admin') : router.path === href;
	}

	async function logout() {
		try {
			await session.logout();
		} finally {
			router.navigate('/');
		}
	}
</script>

<div class="container">
	<header class="site-header">
		<p class="site-title"><a href="/">Студенческий форум</a></p>
		<p class="site-tagline">место, где студенты спрашивают и отвечают</p>
	</header>

	<nav class="menu" aria-label="Главное меню">
		<ul>
			<li><a href="/" class={{ active: isActive('/') }}>[ Главная ]</a></li>
			<li><a href="/publications" class={{ active: isActive('/publications') }}>[ Лента ]</a></li>
			<li><a href="/search" class={{ active: isActive('/search') }}>[ Поиск ]</a></li>
			{#if session.isLoggedIn}
				<li><a href="/publications/new" class={{ active: isActive('/publications/new') }}>[ Новая тема ]</a></li>
			{/if}
			{#if session.isAdmin}
				<li><a href="/admin" class={{ active: isActive('/admin') }}>[ Админка ]</a></li>
			{/if}
			{#if session.loaded && !session.isLoggedIn}
				<li><a href="/login" class={{ active: isActive('/login') }}>[ Вход ]</a></li>
				<li><a href="/register" class={{ active: isActive('/register') }}>[ Регистрация ]</a></li>
			{/if}
		</ul>

		{#if session.user}
			<p class="user-bar">
				Привет, <strong>{session.user.username}</strong>!
				<a href={buildUrl('/search', { author: session.user.username })}>Мои темы</a>
				<button type="button" onclick={logout}>Выход</button>
			</p>
		{/if}
	</nav>

	<main>
		{#if !session.loaded}
			<p>Загрузка…</p>
		{:else if needsLogin || (route.guestOnly && session.isLoggedIn)}
			<!-- идёт перенаправление -->
			<p>Загрузка…</p>
		{:else if isForbidden}
			<ErrorPage status={403} />
		{:else}
			<Page params={match.params} {...route.props} />
		{/if}
	</main>

	<footer class="site-footer">
		<hr />
		<p class="badges">
			<span class="badge badge-blue">PHP + Svelte</span>
			<span class="badge badge-gray">Вручную</span>
		</p>
		<p>© 2026 Студенческий форум · Оптимизировано для 800×600 и выше</p>
	</footer>
</div>

<style lang="scss">
	@use 'tokens' as *;
	@use 'mixins' as *;

	.container {
		max-width: $container-width;
		margin: 0 auto;
		background: $color-paper;
		border: 1px solid $color-border;
		border-top: 0;
		border-bottom: 0;
		min-height: 100vh;

		@include mobile {
			max-width: none;
			padding: 0 8px;
			border: 0;
		}
	}

	.site-header {
		padding: 12px 8px 8px;
		border-bottom: 2px groove $color-button;

		@include mobile {
			padding-left: 0;
			padding-right: 0;
		}
	}

	.site-title {
		margin: 0;
		font: bold 30px $font-heading;

		a,
		a:visited {
			color: $color-header;
			text-decoration: none;
		}

		@include mobile {
			font-size: 24px;
		}
	}

	.site-tagline {
		margin: 0;
		color: $color-muted;
		font-size: 11px;
		font-style: italic;

		@include mobile {
			font-size: 13px;
		}
	}

	.menu {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		justify-content: space-between;
		gap: 4px 12px;
		padding: 4px 8px;
		background: $color-row-alt;
		border-bottom: 1px solid $color-border;

		@include mobile {
			padding-left: 0;
			padding-right: 0;
		}

		ul {
			display: flex;
			flex-wrap: wrap;
			gap: 0 10px;
			margin: 0;
			padding: 0;
			list-style: none;
		}

		a {
			display: inline-block;
			@include mobile {
				min-height: $tap-size;
				padding: 8px 2px;
			}
		}

		a.active {
			font-weight: bold;
			color: $color-text;
		}
	}

	.user-bar {
		margin: 0;

		button {
			margin-left: 6px;
			padding: 0 8px;
		}

		@include mobile {
			width: 100%;
		}
	}

	main {
		padding: 12px 8px;

		@include mobile {
			padding-left: 0;
			padding-right: 0;
		}
	}

	.site-footer {
		padding: 0 8px 12px;
		color: $color-muted;
		font-size: 11px;
		text-align: center;

		p {
			margin: 4px 0;
		}
	}

	// «кнопки 88×31», нарисованные на CSS
	.badge {
		display: inline-block;
		width: 88px;
		height: 31px;
		margin: 0 3px;
		border: 2px outset $color-button;
		font: bold 11px / 27px $font-main;
		text-align: center;
		color: $color-header-text;
	}

	.badge-blue {
		background: $color-header;
	}

	.badge-gray {
		background: $color-muted;
	}
</style>
