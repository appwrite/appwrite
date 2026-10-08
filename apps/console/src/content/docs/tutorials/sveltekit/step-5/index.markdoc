---
layout: tutorial
title: Add navigation 
description: Add navigation to your SvelteKit application with Appwrite authentication.
step: 5
---

We'll create a layout component, that's used by all pages, to display a navbar.
The navbar will show a login button if the user is not logged in, and a logout button if the user is logged in.

Create a new file `src/routes/+layout.svelte` and add the following code to it.

```html
<script>
	import { user } from '$lib/user';
</script>

<nav>
	<a href="/">Home</a>
	{#if $user}
		<div>
			<span>{$user.name}</span>
			<button type="button" on:click={user.logout}>Logout</button>
		</div>
	{:else}
		<a href="/login">Login</a>
	{/if}
</nav>

<slot />

<style>
	:global(*) {
		font-family: sans-serif;
	}

	:global(body) {
		margin: 1rem auto;
		max-width: 30rem;
	}

	nav {
		display: flex;
		justify-content: space-between;
		align-items: center;
	}
</style>
```