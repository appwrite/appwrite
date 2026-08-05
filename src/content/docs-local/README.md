# Vibes-native documentation

Pages in this directory are **not** replaced when you run `bun run import:docs`.

Use `src/content/docs-local/` for product documentation that lives only in vibes (for example, Domains, Firewall, Agent, and Network overview overrides). Imported website docs remain in `src/content/docs/`.

After adding or editing pages here, run:

```bash
bun run generate:docs
```
