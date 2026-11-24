import { mount } from 'svelte';
import './styles/app.scss';
import App from './App.svelte';
import { router } from './lib/router.svelte.js';

router.start();
mount(App, { target: document.getElementById('app') });
