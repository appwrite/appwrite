/**
 * Applies a self-hosted Appwrite release to the website and docs: install and
 * upgrade snippets, the upgrade guide, SDK compatibility rows, the changelog
 * entry, the compose generator and, once appwrite/specs has the new version,
 * the API reference. Every edit is a draft for the release PR; review the
 * diff, fill in the prose, and add the changelog cover image.
 *
 * Usage:
 *   bun run scripts/release-self-hosted.ts <version> [--notes=<file>] [--appwrite=<path>] [--date=YYYY-MM-DD] [--specs]
 *
 * --notes     release notes markdown; defaults to the published GitHub Release
 * --appwrite  appwrite checkout at the release commit (default: APPWRITE_REPO or ../appwrite)
 * --date      changelog entry date (default: today, UTC)
 * --specs     also move the @appwrite.io/specs pin to appwrite/specs main and
 *             regenerate the reference versions; run after the specs PR merged
 */
import { execFileSync } from 'node:child_process'
import { existsSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'

const ROOT = join(import.meta.dirname, '..')
const DOCS = join(ROOT, 'src', 'content', 'docs', 'advanced', 'self-hosting')
const INSTALLATION = join(DOCS, 'installation', 'index.markdoc')
const DATABASES = join(DOCS, 'configuration', 'databases', 'index.markdoc')
const UPDATES = join(DOCS, 'production', 'updates', 'index.markdoc')
const COMPOSE_DATA = join(
  ROOT,
  'src',
  'lib',
  'docs',
  'compose-generator',
  'composeData.ts',
)
const VERSIONS = join(
  ROOT,
  'src',
  'lib',
  'docs',
  'references',
  'generated',
  'versions.ts',
)
const CHANGELOG = join(ROOT, 'src', 'content', 'changelog', 'entries')
const MANIFEST = join(ROOT, 'package.json')
const IMAGE = 'appwrite/appwrite'
const REPOSITORY = 'appwrite/appwrite'
const SPECS_REPOSITORY = 'appwrite/specs'
const SPECS_PACKAGE = '@appwrite.io/specs'

function fail(message: string): never {
  console.error(`error: ${message}`)
  process.exit(1)
}

function run(
  command: string,
  args: string[],
  options: { cwd?: string; env?: NodeJS.ProcessEnv } = {},
): string {
  return execFileSync(command, args, {
    cwd: options.cwd ?? ROOT,
    env: options.env ?? process.env,
    encoding: 'utf-8',
    stdio: ['ignore', 'pipe', 'inherit'],
  }).trim()
}

function parse(version: string): [number, number, number] {
  const parts = version.split('.')
  if (
    parts.length !== 3 ||
    parts.some((part) => part === '' || !/^\d+$/.test(part))
  ) {
    fail(`version must be X.Y.Z, got "${version}"`)
  }
  return parts.map(Number) as [number, number, number]
}

/**
 * Edits are staged in memory and written together once every step, including
 * the compose generation, has succeeded, so a failed run leaves no partial
 * edits behind and can simply be rerun.
 */
const staged = new Map<string, string>()

function current(path: string): string {
  return staged.get(path) ?? readFileSync(path, 'utf-8')
}

function stage(path: string, content: string): void {
  staged.set(path, content)
}

function replace(
  path: string,
  search: string,
  replacement: string,
  expected?: number,
): void {
  const content = current(path)
  const count = content.split(search).length - 1
  if (count === 0 || (expected !== undefined && count !== expected)) {
    fail(
      `${path}: expected ${expected ?? 'at least one'} "${search}", found ${count}`,
    )
  }
  stage(path, content.replaceAll(search, replacement))
  console.log(
    `${path.slice(ROOT.length + 1)}: ${count} × ${search} → ${replacement}`,
  )
}

/**
 * Lines under the first `### <name>` heading found in the release notes, up to
 * the next heading.
 */
function section(notes: string, ...names: string[]): string {
  const lines = notes.split('\n')
  const start = lines.findIndex((line) =>
    names.some((name) => line.trim() === `### ${name}`),
  )
  if (start === -1) return ''
  const rest = lines.slice(start + 1)
  const end = rest.findIndex(
    (line) => line.startsWith('## ') || line.startsWith('### '),
  )
  return (end === -1 ? rest : rest.slice(0, end)).join('\n').trim()
}

/**
 * Paragraphs without fenced code blocks or the sentences that introduce them.
 */
function prose(markdown: string): string {
  return markdown
    .split(/\n{2,}/)
    .filter(
      (paragraph) =>
        !paragraph.startsWith('```') && !paragraph.trimEnd().endsWith(':'),
    )
    .join('\n\n')
    .trim()
}

function notes(version: string, file: string | undefined): string {
  if (file) return readFileSync(file, 'utf-8')
  try {
    return run('gh', [
      'release',
      'view',
      version,
      '-R',
      REPOSITORY,
      '--json',
      'body',
      '--jq',
      '.body',
    ])
  } catch {
    fail(
      `GitHub Release ${version} is not published; pass --notes=<file> with the reviewed draft`,
    )
  }
}

function snippets(version: string, previous: string): void {
  replace(INSTALLATION, `${IMAGE}:${previous}`, `${IMAGE}:${version}`, 3)
  replace(DATABASES, `${IMAGE}:${previous}`, `${IMAGE}:${version}`, 3)
}

/**
 * The versions sharing this version's migration in the checkout's
 * Migration::$versions, oldest first; any of them upgrades in one step.
 */
function upgradable(version: string, appwrite: string): string[] {
  const source = readFileSync(
    join(appwrite, 'src', 'Appwrite', 'Migration', 'Migration.php'),
    'utf-8',
  )
  const versions = [...source.matchAll(/'(\d+\.\d+\.\d+)' => '(V\d+)'/g)]
  const migration =
    versions.find(([, name]) => name === version)?.[2] ??
    fail(`Migration::$versions in ${appwrite} has no entry for ${version}`)
  return versions
    .filter(([, name, shared]) => shared === migration && name !== version)
    .map(([, name]) => name)
}

/**
 * The guide keeps one upgrade section, for the latest release, under a stable
 * anchor so links from changelog entries keep working across releases.
 */
function upgrade(
  version: string,
  previous: string,
  release: string,
  appwrite: string,
): void {
  const content = current(UPDATES)
  if (content.includes(`# Upgrading to ${version} `)) {
    console.log(
      `${UPDATES.slice(ROOT.length + 1)}: already has an Upgrading to ${version} section`,
    )
    return
  }
  const marker = '\n# Upgrading to '
  const start = content.indexOf(marker)
  if (start === -1) fail(`${UPDATES}: no "# Upgrading to" section to replace`)
  const next = content.slice(start + 1).search(/\n# (?!Upgrading to )/)
  if (next === -1) fail(`${UPDATES}: no section after "# Upgrading to"`)
  const end = start + 1 + next

  // The guide already carries the commands, so keep only the prose.
  const upgrading = prose(section(release, 'Upgrade', 'Upgrading'))
  // Upgrading from the oldest version skips the rest, and their notes still apply.
  const [oldest, ...skipped] = upgradable(version, appwrite)
  const links = skipped
    .map(
      (name) =>
        `[\`${name}\`](https://github.com/${REPOSITORY}/releases/tag/${name})`,
    )
    .join(', ')
  const block = [
    `# Upgrading to ${version} {% #upgrading-to-latest %}`,
    '',
    `Appwrite ${version} upgrades from \`${oldest}\` or any later version. Run the upgrade command with the \`${version}\` tag, then run the migration.`,
    '',
    '```sh',
    'docker run -it --rm \\',
    '    --publish 20080:20080 \\',
    '    --volume /var/run/docker.sock:/var/run/docker.sock \\',
    '    --volume "$(pwd)"/appwrite:/usr/src/code/appwrite:rw \\',
    '    --entrypoint="upgrade" \\',
    `    ${IMAGE}:${version}`,
    '```',
    '',
    'Then run the migration from the `appwrite` directory.',
    '',
    '```sh',
    'cd appwrite/',
    'docker compose exec appwrite migrate',
    '```',
    ...(upgrading === '' ? [] : ['', upgrading]),
    ...(links === ''
      ? []
      : [
          '',
          `If you skip releases, also follow the upgrade notes of each release you skip: ${links}.`,
        ]),
    '',
  ].join('\n')

  stage(UPDATES, content.slice(0, start + 1) + block + content.slice(end))
  console.log(
    `${UPDATES.slice(ROOT.length + 1)}: replaced the upgrade section with Upgrading to ${version}`,
  )
  replace(
    UPDATES,
    `(for example, \`${previous}\`)`,
    `(for example, \`${version}\`)`,
    1,
  )
}

/**
 * Copies the previous version's row in each SDK compatibility table. SDK
 * versions carry over unchanged; bump any SDK released for this version.
 */
function compatibility(version: string, previous: string): void {
  const content = current(INSTALLATION)
  const tables = content.split('{% /table %}')
  let added = 0
  const updated = tables.map((table, index) => {
    if (index === tables.length - 1) return table
    const rows = table.split('\n---\n')
    const last = rows[rows.length - 1]
    if (!last.startsWith(`* ${previous}\n`)) return table
    added++
    return `${table.trimEnd()}\n---\n${last.replace(`* ${previous}\n`, `* ${version}\n`).trimEnd()}\n`
  })
  if (added === 0)
    fail(`${INSTALLATION}: no SDK compatibility row for ${previous}`)
  stage(INSTALLATION, updated.join('{% /table %}'))
  console.log(
    `${INSTALLATION.slice(ROOT.length + 1)}: added ${version} to ${added} SDK compatibility tables (SDK versions copied from ${previous})`,
  )
}

function changelog(version: string, date: string, release: string): void {
  const [major, minor] = parse(version)
  const path = join(CHANGELOG, `${date}.markdoc`)
  if (existsSync(path)) fail(`${path} already exists; pass --date`)
  const highlights = section(release, 'Highlights')
  const entry = [
    '---',
    'layout: changelog',
    `title: Announcing Appwrite ${major}.${minor} for self-hosted deployments`,
    `date: ${date}`,
    `cover: /images/changelog/${date}.avif`,
    '---',
    '',
    `Appwrite ${major}.${minor} is now available for self-hosting.`,
    '',
    ...(highlights === ''
      ? []
      : ['This release includes:', '', highlights, '']),
    `{% arrow_link href="https://github.com/${REPOSITORY}/releases/tag/${version}" %}`,
    'View the release notes on GitHub',
    '{% /arrow_link %}',
    '',
  ].join('\n')
  stage(path, entry)
  console.log(`${path.slice(ROOT.length + 1)}: created`)
  const cover = join(ROOT, 'public', 'images', 'changelog', `${date}.avif`)
  if (!existsSync(cover))
    console.warn(
      `warning: add the cover image at ${cover.slice(ROOT.length + 1)}`,
    )
}

function checkout(version: string, appwrite: string): void {
  const constants = join(appwrite, 'app', 'init', 'constants.php')
  if (
    !existsSync(constants) ||
    !readFileSync(constants, 'utf-8').includes(
      `APP_VERSION_STABLE = '${version}'`,
    )
  ) {
    fail(
      `${appwrite} is not an appwrite checkout at ${version}; check it out at the release commit or pass --appwrite`,
    )
  }
}

/**
 * A real calendar date in YYYY-MM-DD form; it names the changelog file.
 */
function day(value: string): string {
  const parsed = new Date(`${value}T00:00:00Z`)
  if (
    !/^\d{4}-\d{2}-\d{2}$/.test(value) ||
    Number.isNaN(parsed.getTime()) ||
    parsed.toISOString().slice(0, 10) !== value
  ) {
    fail(`--date must be a calendar date in YYYY-MM-DD form, got "${value}"`)
  }
  return value
}

function compose(appwrite: string): void {
  run('bun', ['run', 'scripts/generate-compose-data.ts'], {
    env: { ...process.env, APPWRITE_REPO: appwrite },
  })
  console.log(
    `${COMPOSE_DATA.slice(ROOT.length + 1)}: regenerated from ${appwrite}`,
  )
}

function specs(version: string): void {
  const [major, minor] = parse(version)
  const line = `${major}.${minor}.x`
  const sha = run('gh', [
    'api',
    `repos/${SPECS_REPOSITORY}/commits/main`,
    '--jq',
    '.sha',
  ])
  try {
    run('gh', [
      'api',
      `repos/${SPECS_REPOSITORY}/contents/specs/${line}?ref=${sha}`,
      '--silent',
    ])
  } catch {
    fail(
      `${SPECS_REPOSITORY} main (${sha.slice(0, 7)}) has no specs/${line}; merge the specs PR first`,
    )
  }
  const manifest = readFileSync(MANIFEST, 'utf-8')
  const prefix = `"${SPECS_PACKAGE}": "github:${SPECS_REPOSITORY}#`
  const start = manifest.indexOf(prefix)
  if (start === -1)
    fail(`${MANIFEST}: ${SPECS_PACKAGE} is not pinned to ${SPECS_REPOSITORY}`)
  const end = manifest.indexOf('"', start + prefix.length)
  writeFileSync(
    MANIFEST,
    manifest.slice(0, start + prefix.length) + sha + manifest.slice(end),
  )
  run('bun', ['install'])
  run('bun', ['run', 'generate:specs'])
  if (!readFileSync(VERSIONS, 'utf-8').includes(`"${line}"`))
    fail(`${VERSIONS} does not list ${line} after regenerating`)
  console.log(
    `${SPECS_PACKAGE} → ${sha.slice(0, 7)}; reference versions include ${line}`,
  )
}

const args = process.argv.slice(2)
const options = Object.fromEntries(
  args
    .filter((arg) => arg.startsWith('--'))
    .map((arg) => {
      const [key, value] = arg.slice(2).split('=', 2)
      return [key, value ?? 'true']
    }),
)
const version =
  args.find((arg) => !arg.startsWith('--')) ??
  fail(
    'usage: bun run scripts/release-self-hosted.ts <version> [--notes=<file>] [--appwrite=<path>] [--date=YYYY-MM-DD] [--specs]',
  )
parse(version)

const previous =
  readFileSync(COMPOSE_DATA, 'utf-8').match(
    /APPWRITE_VERSION = "([^"]+)"/,
  )?.[1] ?? fail(`APPWRITE_VERSION not found in ${COMPOSE_DATA}`)
const appwrite =
  options.appwrite ??
  process.env.APPWRITE_REPO ??
  join(
    dirname(
      run('git', ['rev-parse', '--path-format=absolute', '--git-common-dir']),
    ),
    '..',
    'appwrite',
  )
const date = day(options.date ?? new Date().toISOString().slice(0, 10))

if (previous === version) {
  console.log(`docs already on ${version}`)
} else {
  checkout(version, appwrite)
  const release = notes(version, options.notes)
  snippets(version, previous)
  upgrade(version, previous, release, appwrite)
  compatibility(version, previous)
  changelog(version, date, release)
  compose(appwrite)
  for (const [path, content] of staged) writeFileSync(path, content)
}

if (options.specs) specs(version)

console.log(
  '\nnext: update environment variables for added or removed ones, review the diff, and add the changelog cover',
)
