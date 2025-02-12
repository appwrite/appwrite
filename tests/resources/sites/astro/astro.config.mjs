import { defineConfig } from 'astro/config';
import node from '@astrojs/node';
import 'dotenv/config';

export default defineConfig({
    output: 'server',
	adapter: node({
	  mode: 'standalone'
	}),
});
