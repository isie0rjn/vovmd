import { defineConfig } from 'vite';
import { svelte } from '@sveltejs/vite-plugin-svelte';
import { fileURLToPath } from 'node:url';

export default defineConfig({
	plugins: [svelte()],
	css: {
		preprocessorOptions: {
			scss: {
				// позволяет в любом SCSS писать: @use 'tokens' as *;
				loadPaths: [fileURLToPath(new URL('./src/styles', import.meta.url))]
			}
		}
	},
	server: {
		// в разработке запросы /api уходят в Docker: фронт и API на одном адресе, cookie сессии работают
		proxy: { '/api': 'http://localhost:8080' }
	}
});
