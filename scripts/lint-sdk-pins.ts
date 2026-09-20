/**
 * Fail when a dependency is pinned to a pkg.vc preview build. Previews are fine
 * on a branch, never on main: they ship endpoints no release carries and
 * whatever generator their Cloud branch pinned.
 *
 * Usage:
 *   bun run lint:sdk-pins
 */
import { join } from 'path'

const ROOT = join(import.meta.dirname, '..')
const PREVIEW_HOST = 'pkg.vc'

// Lockfiles too: a pin survives there after package.json is fixed.
const MANIFEST = 'package.json'
const LOCKFILES = ['bun.lock', 'pnpm-lock.yaml', 'package-lock.json']

type Finding = {
  file: string
  line: number
  text: string
}

async function main() {
  const findings: Finding[] = []

  const manifest = await Bun.file(join(ROOT, MANIFEST)).json()
  const dependencies: Record<string, string> = {
    ...(manifest.dependencies ?? {}),
    ...(manifest.devDependencies ?? {}),
    ...(manifest.optionalDependencies ?? {}),
  }

  const manifestLines = (await Bun.file(join(ROOT, MANIFEST)).text()).split(
    '\n',
  )

  for (const [name, version] of Object.entries(dependencies)) {
    if (!version.includes(PREVIEW_HOST)) {
      continue
    }
    const line =
      manifestLines.findIndex((text) => text.includes(`"${name}"`)) + 1
    findings.push({ file: MANIFEST, line, text: `${name}: ${version}` })
  }

  for (const file of LOCKFILES) {
    const path = join(ROOT, file)
    if (!(await Bun.file(path).exists())) {
      continue
    }
    const lines = (await Bun.file(path).text()).split('\n')
    lines.forEach((text, index) => {
      if (text.includes(PREVIEW_HOST)) {
        findings.push({ file, line: index + 1, text: text.trim() })
      }
    })
  }

  if (findings.length > 0) {
    console.error('Preview SDK pins found:\n')
    for (const finding of findings) {
      console.error(`  ${finding.file}:${finding.line}  ${finding.text}`)
    }
    console.error(
      `\nUse a ${PREVIEW_HOST} build on a branch if you need to, but pin a published version before merging.`,
    )
    process.exit(1)
  }

  console.log('SDK pins: no preview builds.')
}

if (import.meta.main) {
  await main()
}
