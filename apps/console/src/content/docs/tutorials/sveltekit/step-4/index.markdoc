---
layout: tutorial
title: Add authentication
description: Add Appwrite authentication to you Svelte app using your Svelte store.
step: 4
---

## Using stores {% #using-stores %}

Svelte [stores](https://svelte.dev/docs/svelte-store) provide an easy way to manage state throughout your application.
We'll use a store to keep track of our user's data.

Create a new file `src/lib/user.js` and add the following code to it.

```client-web
import { writable } from 'svelte/store';
import { ID } from 'appwrite';
import { goto } from '$app/navigation';
import { account } from '$lib/appwrite';

// Avoid auth calls in server-side, so that a user is not shared between requests
const isBrowser = typeof window !== 'undefined';

const createUser = () => {
	const store = writable(null);

	async function init() {
		if (!isBrowser) return;
		try {
			store.set(await account.get());
		} catch (e) {
			store.set(null);
		}
	}

	init();

	async function register(email, password, name) {
		if (!isBrowser) return;
		await account.create({
			userId: ID.unique(),
			email,
			password,
			name
		});
		await login(email, password);
	}

	async function login(email, password) {
		if (!isBrowser) return;
		await account.createEmailPasswordSession({
			email,
			password
		});
		await init();
		goto('/'); // Redirect to home page after login
	}

	async function logout() {
		await account.deleteSession({
			sessionId: 'current'
		});
		store.set(null);
	}

	return {
		// Exposes the store's value with $user
		subscribe: store.subscribe,
		register,
		login,
		logout,
		init
	};
};

export const user = createUser();
```

## Login page {% #login-page %}

Using this store, we can build a login page.

Create a new file `src/routes/login/+page.svelte` and add the following code to it.

```html
<script>
	import { user } from '$lib/user';

	const login = async (e) => {
		e.preventDefault();
		const formData = new FormData(e.target);
		await user.login(formData.get('email'), formData.get('password'));
	};

	const register = async (e) => {
		e.preventDefault();
		const formData = new FormData(e.target);
		await user.register(formData.get('email'), formData.get('password'), formData.get('name'));
	};
</script>

<h1>Login or register</h1>

<form on:submit={login}>
	<label>
		Email
		<input type="email" placeholder="Email" name="email" required />
	</label>
	<label>
		Password:
		<input type="password" placeholder="Password" name="password" required />
	</label>
	<button type="submit"> Login </button>
</form>

<form on:submit={register}>
	<label>
		Name:
		<input type="text" placeholder="Name" name="name" required />
	</label>
	<label>
		Email:
		<input type="email" placeholder="Email" name="email" required />
	</label>
	<label>
		Password:
		<input type="password" placeholder="Password" name="password" required minlength="8" />
	</label>
	<button type="submit"> Register </button>
</form>

<style>
	form {
		display: flex;
		flex-direction: column;
		align-items: flex-start;
		gap: 0.25rem;

		margin-block-end: 2rem;
	}
</style>
```