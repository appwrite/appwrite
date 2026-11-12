---
layout: tutorial
title: Create login page
description: Add Authentication to a SvelteKit project using Appwrite.
step: 5
---

We can now implement our login page. Create a `+page.js` file in the `src/routes/login` directory:

```js
// src/routes/login/+page.js
import { redirect } from "@sveltejs/kit";

export const load = async ({ parent }) => {
  // Gets the data returned from the root layout
  const { account } = await parent();
  if (account) {
    redirect(303, "/");
  }
};
```

You can see that we added a redirect in the login page to check if the user's already logged in, in which case we redirect them to the homepage.

Now we just need to create a form to let the user input sign in data.

```svelte
<!-- src/routes/login/+page.svelte -->
<script>
	import { invalidateAll } from '$app/navigation';
	import { appwrite } from '$lib/appwrite';

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

		const { email, password } = formData;
		if (!email || !password) {
			formError = 'Please fill out all fields';
			return;
		}

		try {
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
	<label  for="email">Email</label>
	<input type="email" id="email" name="email" required />
	<label  for="password">Password</label>
	<input
		type="password"
		id="password"
		name="password"
		placeholder="SuperSecretPassword"
		required
		minlength="8"
	/>
	<button type="submit">Login</button>
	{#if formError}
		<p>{formError}</p>
	{/if}
</form>
```

And that's it. When the user successfully logs in, we use `invalidateAll` to re-run the relevant `load` functions. In this case, the functions inside `src/routes/login/+page.js` and `src/routes/+layout.js`.
This means that the `account` data will be updated, no longer being `null`, and so the login `load` function will redirect the user to the home page.
