# Utopia Auth Agent Notes

Use the package documentation as the source of truth before changing public
behavior or examples:

- [README.md](README.md) for the feature overview and documentation index.
- [docs/oauth2.md](docs/oauth2.md) for OAuth2 and OpenID Connect token examples,
  resource indicators, prompts, and pushed authorization request URIs.
- [docs/jwt.md](docs/jwt.md) for generic JWS/JWT verification behavior and
  claim/header enum references.
- [docs/hashing.md](docs/hashing.md), [docs/proofs.md](docs/proofs.md), and
  [docs/store.md](docs/store.md) for the older authentication primitives.

Keep examples and helper docs close to the protocol or primitive they describe.
When adding OAuth2 or OpenID Connect helpers, update `docs/oauth2.md` rather than
expanding `docs/jwt.md`.
