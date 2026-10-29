---
layout: tutorial
title: Create signup page
description: Add Authentication to a SvelteKit project using Appwrite.
step: 6
---

For signup, you can copy the login `+page.js` and `+page.svelte` files into `src/routes/signup`, with some small changes to the `+page.svelte` file:

```svelte
<!-- src/routes/signup/+page.svelte -->
<script>
	import { invalidateAll } from '$app/navigation';
	import { appwrite } from '$lib/appwrite';
  import { ID } from "appwrite";

	/** @type {string|null} */
	let formError = null;

	/**
	 * @param {Event} event
	 */
	async function handleSubmit(event) {
		event.preventDefault();
		formError = null;

		const form = /** @type {HTMLFormElement} */ (event.target);
		const formData = /** @type Record<string, string | undefined> */ (
			Object.fromEntries(new FormData(form).entries())
		);

		const { name, email, password } = formData;
		if (!name || !email || !password) {
			formError = 'Please fill out all fields';
			return;
		}

		try {
			await appwrite.account.create({
				userId: ID.unique(),
				email,
				password,
				name
			});
			await appwrite.account.createEmailPasswordSession({
				email,
				password
			});
			await invalidateAll();
		} catch (e) {
			formError = /** @type {import('appwrite').AppwriteException} */ (e).message;
		}
	}
</script>

<form on:submit={handleSubmit}>
	<label for="name">Name</label>
	<input type="text" id="name" name="name" required />
	<label for="email">Email</label>
	<input type="email" id="email" name="email" required />
	<label for="password">Password</label>
	<input
		type="password"
		id="password"
		name="password"
		placeholder="SuperSecretPassword"
		required
		minlength="8"
	/>
	<button type="submit">Signup</button>
	{#if formError}
		<p>{formError}</p>
	{/if}
</form>
```
With this, you have a simple authentication system.

