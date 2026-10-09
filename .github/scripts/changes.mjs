// Decides what a CI run tests from the files it changed, using .github/ci/e2e.json.
// A file no rule claims runs every lane, so a gap in the map costs time, not coverage.
// Release branches and manual runs test everything, and on a push any server
// change runs every lane, since the nightly channel ships the newest green commit
// on main.

import { execFileSync } from "node:child_process";
import { appendFileSync, readFileSync } from "node:fs";

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

const databaseVersion = (commit) => {
  const lock = JSON.parse(git("show", `${commit}:composer.lock`));
  return lock.packages?.find((item) => item.name === "utopia-php/database")
    ?.version;
};

const everything =
  event === "workflow_dispatch" ||
  (ref.startsWith("refs/heads/") && ref.endsWith(".x"));
const from = everything ? null : base();

let server = everything;
let frontend = everything;
let all = everything || event === "push";
const selected = new Set();

if (!everything) {
  // Without renames, a move out of the server still counts its source.
  const files = git("diff", "--name-only", "--no-renames", from, "HEAD")
    .split("\n")
    .filter(Boolean);
  for (const file of files) {
    if (claims(file, config.paths.all)) {
      server = frontend = all = true;
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
    databaseVersion(from) !== databaseVersion("HEAD"));

const outputs = {
  server,
  console: frontend,
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
