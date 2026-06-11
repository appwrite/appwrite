---
layout: tutorial
title: Check if logged in
description: Add Authentication to a SvelteKit project using Appwrite.
step: 4
---

Before taking a user to the login screen, we should check if they're already logged in. 
With SvelteKit, you can use the `load` function to check if you're logged in before your app renders.

```js
// src/routes/+layout.js
import { appwrite } from "$lib/appwrite";

// Turn off SSR globally, turning the project into a static site
export const ssr = false;

export const load = async () => {
  try {
    return {
      account: await appwrite.account.get(),
    };
  } catch {
    return {
      account: null,
    };
  }
};
```

By returning the account data in the root layout, it is globally available to all pages, **before any page is displayed** to the user. 
If we find the user is not logged in, we can redirect them to log in first.

We can get the returned data in the root page by using the `data` prop:

```svelte
<!-- src/routes/+page.svelte -->
<script>
	import { invalidateAll } from '$app/navigation';
	import { appwrite } from '$lib/appwrite.js';

	export let data;

	$: loggedIn = !!data.account;

	async function logout() {
		await appwrite.account.deleteSession({ sessionId: 'current' });
		// invalidateAll will execute all `load` functions again.
		// In our case, this means we'll fetch the account data again.
		await invalidateAll();
	}
</script>

{#if loggedIn}
	<p>Hello {data.account?.name}!</p>
	<button on:click={logout}>Logout</button>
{:else}
	<a href="/login">Login</a>
	<a href="/signup">Signup</a>
{/if}
```

Normally, you'll want to use the `invalidate` method instead of `invalidateAll`, to avoid fetching data that you don't need to.
You can refer to the [SvelteKit docs](https://kit.svelte.dev/docs/modules#$app-navigation-invalidate) for more information on invalidation.