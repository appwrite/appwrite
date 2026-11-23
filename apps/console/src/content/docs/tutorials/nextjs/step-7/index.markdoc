---
layout: tutorial
title: Ideas page
description: Add ideas from Appwrite database in your app.
step: 7
---

With the methods in the `useIdeas()` hook we can get some ideas to the home page for the users to interact with.
We will use it in a form component so the logged in users can add their ideas, and in a list component to render the ten most recent ideas.
We start with building the form.

## Idea form {% #idea-form %}

On the home page, the logged in users should be able to add their ideas to the Appwrite database.
The form needs a text field for filling in the title, a textarea for the description and a submit button.

Create a new file `src/components/IdeasForm.tsx` and add the following code.

```tsx
// src/components/IdeasForm.tsx
'use client';

import { useIdeas } from '../hooks/useIdeas';
import { useAuth } from '../hooks/useAuth';

export default function IdeasForm() {
    const { add } = useIdeas();
    const { current: user } = useAuth();

    const handleAddIdea = async (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = event.target as HTMLFormElement;
        const formData = new FormData(form);

        if (!user) return;

        const postIdeaData = {
            userId: user.userId,
            title: formData.get('title') as string,
            description: formData.get('description') as string,
        };

        await add(postIdeaData);
        form.reset();
    };

    if (!user) {
        return null; // Don't render form if user is not logged in
    }

    return (
        <div>
            <article className="container padding-0">
                <h4 className="heading-level-4">Submit Idea</h4>
                <form onSubmit={handleAddIdea} className="u-margin-block-start-16">
                    <ul className="form-list">
                        <li className="form-item">
                            <label className="label">Title</label>
                            <input
                                type="text"
                                placeholder="Title"
                                name="title"
                                required
                            />
                        </li>
                        <li className="form-item">
                            <label className="label">Description</label>
                            <textarea
                                placeholder="Description"
                                name="description"
                                rows={4}
                            />
                        </li>
                    </ul>
                    <ul className="buttons-list u-margin-block-start-16">
                        <li className="buttons-list-item">
                            <button
                                type="submit"
                                className="button"
                                aria-label="Submit idea"
                            >
                                Submit
                            </button>
                        </li>
                    </ul>
                </form>
            </article>
        </div>
    );
}
```

## Ideas list {% #ideas-list %}

Now we need to display the ideas from the database. Create a new file `src/components/IdeasList.tsx`.

```tsx
// src/components/IdeasList.tsx
'use client';

import { useIdeas } from '../hooks/useIdeas';
import { useAuth } from '../hooks/useAuth';

export default function IdeasList() {
    const { current: ideas, loading, remove } = useIdeas();
    const { current: user } = useAuth();

    if (loading) {
        return <div>Loading ideas...</div>;
    }

    if (ideas.length === 0) {
        return (
            <div className="container u-margin-block-start-16">
                <p>No ideas yet. Be the first to share one!</p>
            </div>
        );
    }

    return (
        <div className="container u-margin-block-start-16">
            <h4 className="heading-level-4 u-margin-block-end-16">Latest Ideas</h4>
            <ul className="u-margin-0 u-padding-0">
                {ideas.map((idea) => (
                    <li key={idea.$id} className="card u-margin-block-end-16">
                        <div className="u-flex u-main-space-between">
                            <div className="u-flex-grow-1">
                                <h5 className="heading-level-5">{idea.title}</h5>
                                {idea.description && (
                                    <p className="u-margin-block-start-8">
                                        {idea.description}
                                    </p>
                                )}
                            </div>
                            {user && user.userId === idea.userId && (
                                <button
                                    onClick={() => remove(idea.$id)}
                                    className="button is-text u-padding-inline-8"
                                    aria-label="Delete idea"
                                >
                                    🗑️
                                </button>
                            )}
                        </div>
                    </li>
                ))}
            </ul>
        </div>
    );
}
```

## Update home page {% #update-home-page %}

Now let's update the home page to use our new components. Update `src/app/page.tsx`:

```tsx
// src/app/page.tsx
import IdeasForm from '../components/IdeasForm';
import IdeasList from '../components/IdeasList';

export default function Home() {
    return (
        <main className="u-padding-16">
            <div className="container">
                <h1 className="heading-level-1">Ideas Tracker</h1>
                <p className="u-margin-block-start-16">
                    Track all your side project ideas in one place.
                </p>
            </div>
            <IdeasForm />
            <IdeasList />
        </main>
    );
}
```

You can now test the full functionality of your ideas tracker app!