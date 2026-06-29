# Vibes-native blog posts

Posts in this directory are **not** replaced when you run `bun run import:blog`.

Use `src/content/blog-local/posts/` for announcements and articles that live only in vibes. Imported website blog content remains in `src/content/blog/`.

Store images under `public/images/blog-local/` so they are not overwritten when blog images are re-imported from the website repo.

To regenerate cover and inline images for a post:

```bash
bun run generate:blog-local-images announcing-console-terminal
```
