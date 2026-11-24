<script>
	import Box from '../lib/components/Box.svelte';
</script>

<svelte:head><title>Админка — Студенческий форум</title></svelte:head>

<h1>Панель администратора</h1>

<Box title="Управление">
	<ul class="menu-list">
		<li>» <a href="/admin/users">Пользователи</a></li>
		<li>» <a href="/admin/categories">Разделы</a></li>
	</ul>
</Box>

<Box title="Что можно делать">
	<ul>
		<li>Создавать учётные записи пользователей и администраторов.</li>
		<li>Блокировать и разблокировать пользователей — доступ закрывается сразу.</li>
		<li>Выдавать и снимать роль администратора.</li>
		<li>Добавлять, переименовывать и удалять разделы форума.</li>
		<li>Изменять и удалять любые темы — кнопки на странице темы.</li>
		<li>Удалять любые комментарии — кнопки под темой.</li>
	</ul>
</Box>

<style lang="scss">
	@use 'mixins' as *;

	.menu-list {
		margin: 0;
		padding: 0;
		list-style: none;

		a {
			display: inline-block;
			@include mobile {
				padding: 8px 0;
			}
		}
	}
</style>
