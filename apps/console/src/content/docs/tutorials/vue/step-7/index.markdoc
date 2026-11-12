---
layout: tutorial
title: Create ideas page
description: Add data queries and pagination to your Vue.js project powered by Appwrite Cloud databases.
step: 7
---

Using the `ideas` store we can now display the ideas on the page. We will also add a form to submit new ideas.

Overwrite the contents of `src/pages/Home.vue` with the following:

```vue
<script setup>
import { user } from "../stores/user";
import { ideas } from "../stores/ideas";
import { onMounted, ref } from "vue";

const title = ref("");
const description = ref("");

function addIdea() {
  ideas.add({
    userId: user.current.$id,
    title: title.value,
    description: description.value,
  });
  title.value = "";
  description.value = "";
}

onMounted(() => {
  ideas.init();
});
</script>

<template>
  <section v-if="user.current">
    <h2>Submit Idea</h2>

    <form>
      <input
        type="text"
        placeholder="Title"
        v-model="title"
      />
      <textarea
        placeholder="Description"
        v-model="description"
      ></textarea>
      <button type="button" @click="addIdea()">Submit</button>
    </form>
  </section>
  <section v-else><p>Please login to submit an idea.</p></section>

  <section v-if="ideas.current">
    <h2>Latest Ideas</h2>
    <ul>
      <li v-for="idea in ideas.current"">
        <strong>{{ idea.title }}</strong>
        <p>{{ idea.description }}</p>
        <button
          v-if="user.current && idea.userId === user.current.$id"
          type="button"
          @click="ideas.remove(idea.$id)"
        >
          Remove
        </button>
      </li>
    </ul>
  </section>
</template>
```