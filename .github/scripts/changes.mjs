// Decides what a CI run tests from the files it changed, using .github/ci/e2e.json.
// A file no rule claims runs every lane, so a gap in the map costs time, not coverage.
// Release branches (the nightly builds from them) and manual runs test
// everything. Every push runs every server lane, so PRs that each ran a subset
// are checked together after merge and main's tip is never green on a skip.

import { execFileSync } from "node:child_process";
import { appendFileSync, readdirSync, readFileSync } from "node:fs";

const config = JSON.parse(
  readFileSync(new URL("../ci/e2e.json", import.meta.url)),
);
const event = process.env.EVENT;
const ref = process.env.REF ?? "";

const git = (...args) =>
  execFileSync("git", args, { encoding: "utf8", maxBuffer: 64 * 1024 * 1024 });

// `*.ext` matches by extension; any other pattern is a path prefix in which a
// `*` segment matches one directory.
const matches = (file, pattern) => {
  if (pattern.startsWith("*.")) {
    return file.endsWith(pattern.slice(1));
  }
  const want = pattern.split("/");
  const have = file.split("/");
  if (have.length < want.length) {
    return false;
  }
  return want.every((segment, index) => {
    if (segment === "*") {
      return true;
    }
    return index === want.length - 1
      ? have[index].startsWith(segment)
      : have[index] === segment;
  });
};

const claims = (file, patterns) =>
  patterns.some((pattern) => matches(file, pattern));

const base = () => {
  if (event === "pull_request") {
    // The checkout is GitHub's merge commit; its first parent is the base tip it
    // merges onto, so the diff holds only this PR's changes.
    const [, parent, other] = git("rev-list", "--parents", "-n", "1", "HEAD")
      .trim()
      .split(" ");
    return other
      ? parent
      : git("merge-base", `origin/${process.env.BASE_REF}`, "HEAD").trim();
  }
  if (event === "merge_group") {
    return process.env.MERGE_BASE;
  }
  try {
    git("rev-parse", "-q", "--verify", `${process.env.BEFORE}^{commit}`);
    return process.env.BEFORE;
  } catch {
    return git("rev-parse", "HEAD~1").trim();
  }
};

// Every library whose queries run on the matrix databases; usage writes to ClickHouse instead.
const databaseLibraries = [
  "abuse",
  "audit",
  "database",
  "migration",
  "mongo",
  "query",
];

// A branch constraint locks the branch name as the version on every update,
// so only the source commit tells two builds of the same branch apart.
const databaseReferences = (commit) => {
  const lock = JSON.parse(git("show", `${commit}:composer.lock`));
  return databaseLibraries
    .map((name) => {
      const entry = lock.packages?.find(
        (item) => item.name === `utopia-php/${name}`,
      );
      return `${name}@${entry?.source?.reference ?? entry?.version ?? ""}`;
    })
    .join(",");
};

// The libraries a change under crates/ reaches, by the name of their package: the extension
// registers crates/utopia/<name> as the classes of packages/<name>. Cargo's own graph says which
// crates depend on the one that changed, so a change to php-std reaches every library and a
// change to one library reaches that library. The extension crate binds all of them, as do the
// workspace's manifests. A crate's tests, benchmarks and docs reach none: the Extension job
// runs those.
const libraries = (files) => {
  const changed = files.filter(
    (file) =>
      !file.endsWith(".md") &&
      !/\/(tests|bench|benches)\//.test(file) &&
      (file.startsWith("crates/") ||
        file.startsWith(".cargo/") ||
        ["Cargo.toml", "Cargo.lock", "rust-toolchain.toml"].includes(file)),
  );
  if (changed.length === 0) {
    return [];
  }
  // --no-deps reads the manifests only: no build, no network, no lock resolution. Any installed
  // toolchain can do that, so the one rust-toolchain.toml pins is not downloaded for it.
  let metadata;
  try {
    metadata = execFileSync(
      "cargo",
      ["metadata", "--no-deps", "--format-version", "1"],
      {
        encoding: "utf8",
        maxBuffer: 64 * 1024 * 1024,
        env: { ...process.env, RUSTUP_TOOLCHAIN: "stable" },
      },
    );
  } catch (error) {
    // Without the graph every library is reached: a missing cargo costs time, not coverage.
    console.log(`cargo metadata failed, taking every library: ${error.message}`);
    return readdirSync("crates/utopia").sort();
  }
  const { packages, workspace_root: root } = JSON.parse(metadata);
  const crates = packages.map((crate) => ({
    name: crate.name,
    directory: crate.manifest_path.slice(root.length + 1, -"/Cargo.toml".length),
    dependencies: crate.dependencies
      .filter((dependency) => dependency.path)
      .map((dependency) => dependency.name),
  }));
  const library = (crate) =>
    crate.directory.startsWith("crates/utopia/")
      ? crate.directory.slice("crates/utopia/".length)
      : null;
  const every = crates.map(library).filter(Boolean);
  const reached = new Set();
  for (const file of changed) {
    const owner = crates
      .filter((crate) => file.startsWith(`${crate.directory}/`))
      .sort((a, b) => b.directory.length - a.directory.length)[0];
    if (!owner || owner.directory === "crates/extension") {
      return every;
    }
    const queue = [owner];
    const seen = new Set();
    while (queue.length > 0) {
      const crate = queue.pop();
      if (seen.has(crate.name)) {
        continue;
      }
      seen.add(crate.name);
      if (library(crate)) {
        reached.add(library(crate));
      }
      queue.push(
        ...crates.filter((other) => other.dependencies.includes(crate.name)),
      );
    }
  }
  return [...reached].sort();
};

const everything =
  event === "workflow_dispatch" ||
  (ref.startsWith("refs/heads/") && ref.endsWith(".x"));
const from = everything ? null : base();

let server = everything || event === "push";
let frontend = everything;
let all = everything || event === "push";
// The utopia PHP extension (crates/) and what it is tested against.
let extension = everything || event === "push";
let databasePackagesChanged = false;
const selected = new Set();

if (!everything) {
  // Without renames, a move out of the server still counts its source.
  const files = git("diff", "--name-only", "--no-renames", from, "HEAD")
    .split("\n")
    .filter(Boolean);
  // An absorbed library leaves the lock and moves with its packages/ directory.
  databasePackagesChanged = files.some((file) =>
    databaseLibraries.some((name) => file.startsWith(`packages/${name}/`)),
  );
  for (const file of files) {
    if (claims(file, config.paths.extension)) {
      extension = true;
    }
    if (claims(file, config.paths.all)) {
      server = frontend = all = extension = true;
    } else if (claims(file, config.paths.console)) {
      frontend = true;
    } else if (claims(file, config.paths.checks)) {
      server = true;
    } else if (claims(file, config.paths.ignore)) {
      continue;
    } else {
      server = true;
      const owners = config.services.filter(
        (service) =>
          file.startsWith(`tests/e2e/Services/${service.name}/`) ||
          claims(file, service.paths),
      );
      if (owners.length === 0) {
        all = true;
      }
      owners.forEach((service) => selected.add(service.name));
    }
  }
  // A library's classes have no PHP source, so the suites that use them are the ones that own
  // its package. A library no suite owns is one the whole server uses.
  for (const name of libraries(files)) {
    server = true;
    const owners = config.services.filter((service) =>
      claims(`packages/${name}/`, service.paths),
    );
    if (owners.length === 0) {
      all = true;
    }
    owners.forEach((service) => selected.add(service.name));
    console.log(
      `crate library ${name}: ${owners.map((service) => service.name).join(", ") || "every suite"}`,
    );
  }
}

const services = config.services
  .filter((service) => server && (all || selected.has(service.name)))
  .map(({ paths, ...service }) => service);
const names = new Set(services.map((service) => service.name));
const groups = config.groups
  .filter(
    (group) =>
      server && (all || group.services.some((name) => names.has(name))),
  )
  .map(({ services, ...group }) => group);

const full =
  event === "workflow_dispatch" ||
  (server &&
    (event === "pull_request" || event === "merge_group") &&
    (databasePackagesChanged ||
      databaseReferences(from) !== databaseReferences("HEAD")));

const outputs = {
  server,
  console: frontend,
  extension,
  services: JSON.stringify(services),
  groups: JSON.stringify(groups),
  databases: JSON.stringify(
    full ? ["MariaDB", "PostgreSQL", "MongoDB"] : ["PostgreSQL"],
  ),
  modes: JSON.stringify(full ? ["dedicated", "shared"] : ["dedicated"]),
};

if (process.env.GITHUB_OUTPUT) {
  appendFileSync(
    process.env.GITHUB_OUTPUT,
    Object.entries(outputs)
      .map(([key, value]) => `${key}=${value}\n`)
      .join(""),
  );
}
console.log(
  `base ${from ?? "(everything)"}: server=${server} console=${frontend}`,
);
console.log(
  `e2e: ${all ? "all" : [...names].join(", ") || "none"}; groups: ${groups.map((group) => group.label).join(", ") || "none"}`,
);
