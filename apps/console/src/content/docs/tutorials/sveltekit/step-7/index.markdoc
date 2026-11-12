---
layout: tutorial
title: Create ideas page
description: Add pagining and ordering to you SvelteKit application powered by Appwrite Databases.
step: 7
---

Using our created methods, we can build a page to submit and view ideas.

First, let's create a load function for our ideas page. This will load the latest ideas from the database.

To do so, create a file called `src/routes/+page.js` with the following content:

```client-web
import { getIdeas } from '$lib/ideas';

export async function load() {
	return {
		ideas: await getIdeas()
	};
}
```

Simple as that! Now, let's create the page itself. Replace the contents in `src/routes/+page.svelte` with the following:

```html
<script>
	import { invalidateAll } from '$app/navigation';
	import { addIdea, deleteIdea } from '$lib/ideas.js';
	import { user } from '$lib/user.js';

	export let data;

	const add = async (e) => {
		e.preventDefault();
		const formEl = e.target;
		const formData = new FormData(formEl);
		await addIdea($user.$id, formData.get('title'), formData.get('description'));
		invalidateAll();

		// Reset form
		formEl.reset();
	};

	const remove = async (id) => {
		await deleteIdea(id);
		invalidateAll();
	};
</script>

{#if $user}
	<section>
		<h2>Submit Idea</h2>
		<form on:submit={add}>
			<label>
				Title
				<input type="text" placeholder="Title" name="title" required />
			</label>
			<label>
				Description
				<textarea placeholder="Description" name="description" />
			</label>
			<button type="submit">Submit</button>
		</form>
	</section>
{:else}
	<section><p>Please login to submit an idea.</p></section>
{/if}

<section>
	<h2>Latest Ideas</h2>
	{#if data.ideas.total === 0}
		<p>No ideas yet.</p>
	{:else}
		<p>{data.ideas.total} ideas found</p>
	{/if}
	<ul>
		{#each data.ideas.rows as idea}
			<li>
				<strong>{idea.title}</strong>
				{#if idea.description}
					<p>{idea.description}</p>
				{/if}
				{#if $user && idea.userId === $user.$id}
					<button type="button" on:click={() => remove(idea.$id)}>Remove</button>
				{/if}
			</li>
		{/each}
	</ul>
</section>

<style>
	section {
		margin-bottom: 3rem;
	}
	form {
		display: grid;
		gap: 0.75rem;
	}

	label {
		display: grid;
		gap: 0.25rem;
	}

	ul {
		list-style: none;
		padding: 0;
	}

	li {
		border-radius: 0.5rem;
		border: 2px dashed pink;
		min-width: 20%;
		padding: 1rem;
		margin-bottom: 1rem;
	}
</style>
```

With this you have successfully created an Ideas Tracker! You can now submit ideas and view them.
