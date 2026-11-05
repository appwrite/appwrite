---
layout: tutorial
title: Ideas page
description: Add ideas from Appwrite database in your app.
step: 7
---

With the methods in the `useIdeas` composable we can get some ideas to the home page for the users to interact with.
We will use it in a form component so the logged in users can add their ideas, and in a list component to render the ten most recent ideas.
We start with building the form.

## Idea form {% #idea-form %}

On the home page, the logged in users should be able to add their ideas to the Appwrite database.
The form need a text field for filling in the title, a textarea for the description and a submit button.

From the `components` directory, add the file `IdeasForm.vue` and add the following code.

```vue
<!-- components/IdeasForm.vue -->
<script setup>
const ideas = useIdeas();
const user = useUserSession();

const handleAddIdea = async (event) => {
  const form = event.target;
  const formData = new FormData(form);

  // Extract the values from the FormData object and add userId
  const postIdeaData = {
    userId: user.current.value.userId,
    title: formData.get('title'),
    description: formData.get('description'),
  };

  await ideas.add(postIdeaData);

  form.reset(); // Clear the form
};
</script>

<template>
  <div>
    <article class="container padding-0">
      <h4 class="heading-level-4">Submit Idea</h4>
      <form @submit.prevent="handleAddIdea" class="u-margin-block-start-16">
        <ul class="form-list">
          <li class="form-item">
            <label class="label">Title</label>
            <input
              type="text"
              placeholder="Title"
              name="title"
            />
          </li>
          <li class="form-item">
            <label class="label">Description</label>
            <textarea
              placeholder="Description"
              name="description"
            />
          </li>
          <button class="button" aria-label="Submit idea" type="submit">
            Submit
          </button>
        </ul>
      </form>
    </article>
  </div>
</template>
```

Next, add the component to the page `pages/index.vue` by auto-importing it in the `<template>` tag.
In doing that, we need to take a moment to think about how we want to display the form to the users.

Since it should only be shown to logged in user, we need to wrap it in a `<section>` that renders conditionally when the `isLoggedIn` reference in the `useUserSession` is true.
If the requirement is not met, we show a paragraph with some information to the user instead.

Add the following code to the `index.vue` page to conditionally render the form and information paragraph.

Overwrite the contents of `pages/index.vue` with the following code.

```vue
<!-- pages/index.vue -->
<script setup>
const user = useUserSession();
</script>

<template>
  <div class="u-max-width-650" style="margin-inline: auto;">
    <!-- Idea form component for logged in users -->
    <section v-if="user.current.value" class="card u-margin-32">
      <IdeasForm />
    </section>

    <section v-else class="card u-margin-32">
      <div class="container">
        <p class="body-text-1" style="width: 100%;">
          Please login to submit an idea.
        </p>
      </div>
    </section>
    <IdeasList />
  </div>
</template>
<style>
article.box {
  background-color: hsl(var(--color-neutral-0));
}
</style>
````

## Ideas list {% #ideas-list %}
Now that we can get some ideas to show, we go on to build the component for the list of ideas.

Once again, we need to take a moment to think about how this component should work.
First of all, the ideas should be visible for the users before any interaction has taken place on the page.
To catch that moment in time when the page loads, we call our `fetch` function, that fetches the ideas in Appwrite, from the built-in `onMounted` function.

Second, it's likely that a user will want to delete one of their ideas from the database.
We help them do that by adding a delete button in the top right corner of the idea list item, but only on the ideas added by the user itself.

Add the file `IdeasList` from the `components` directory and insert the following code:

```vue
<!-- components/IdeasList.vue -->
<script setup>
import { onMounted } from 'vue';

const ideas = useIdeas();
const user = useUserSession();
</script>

<template>
    <section class="u-margin-32">
      <article class="card">
        <h4 class="heading-level-4">Latest Ideas</h4>
        <ul class="u-margin-block-start-8">
          <template v-if="ideas.current.value && ideas.current.value.length">
            <li v-for="idea in ideas.current.value">
              <div class="box">
                <h5 class="heading-level-6">{{ idea.title }}</h5>
                <p class="body-text-2">{{ idea.description }}</p>
                <div class="u-position-absolute u-inset-inline-end-8 u-inset-block-start-8">
                  <button class="button is-small is-text is-only-icon" aria-label="Remove item" v-if="user.current.value &&
                    idea.userId === user.current.value.userId
                    " type="button" @click="ideas.remove(idea.$id)">
                    <span class="icon-row-remove" aria-hidden="true" />
                  </button>
                </div>
              </div>
            </li>
          </template>
          <template v-else>
            <p>No ideas yet.</p>
          </template>
        </ul>
      </article>
    </section>
  </template>
```

Return to the file `pages/index.vue` once more to import the list of ideas.
This component should be visible to all users, so no conditional rendering neeeds to be handled.

```vue
<!-- pages/index.vue -->

<template>
  <div class="u-max-width-650" style="margin: 0 auto;">
    <!-- ... Some skipped code -->
    <IdeasList />
  </div>
</template>

<!-- ... Some skipped code -->
````

Congratulations!
You now have an ideas tracker built with Nuxt and Appwrite to use locally.
