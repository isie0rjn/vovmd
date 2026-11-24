import { vitePreprocess } from '@sveltejs/vite-plugin-svelte';

export default {
	// компилирует <style lang="scss"> в обычный CSS через Vite
	preprocess: vitePreprocess(),
	compilerOptions: { runes: true }
};
