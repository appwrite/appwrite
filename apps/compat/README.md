# Compat

The conversion report of the Utopia libraries from PHP to Rust, as a TanStack Start app. It renders what [`bin/compat report`](../../tests/compat/README.md) measures, per library:

- **Summary**: API coverage, cases, fuzzing, ported PHP tests, waivers, quirks, deviations and any problem in the run.
- **Interface**: every public PHP method next to its closest Rust counterpart: how it is called, each parameter's type and default, the return type, a verdict, and the case steps and fuzzed inputs that exercise it.
- **Cases**: every case step with its arguments and the PHP and Rust results side by side, differing lines highlighted.
- **Fuzz**: every fuzz profile, the inputs run and the counts CI runs.
- **Docs**: each PHP symbol's signature and docblock next to the Rust items that document it.
- **Rust-only API**: public Rust items no PHP symbol links to.

## Run

From the repository root, generate the data (PHP runs in the `appwrite-dev` image, so Docker must be up), then start the app:

```bash
bin/compat report --iterations 300
cd apps/compat
bun install
bun run dev
```

The app serves on <http://localhost:3100>. `bun run generate` runs the report from here. `bun run build` then `bun run start` serves a production build.

The data lands in `apps/compat/data` (not committed); every page reads it through Vite, so regenerate and reload.
