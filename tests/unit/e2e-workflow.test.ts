import { describe, expect, test } from 'bun:test'
import { readFileSync } from 'node:fs'
import { load } from 'js-yaml'

const workflow = load(
  readFileSync(
    new URL('../../.github/workflows/e2e.yml', import.meta.url),
    'utf8',
  ),
) as {
  jobs: {
    e2e: {
      concurrency: { group: string; 'cancel-in-progress': boolean }
      strategy: {
        'fail-fast': boolean
        matrix: { include: Array<{ project: string; args: string }> }
      }
      steps: Array<{ name?: string; run?: string }>
    }
  }
}

// Load the real project dependency graph without credentials or a live backend.
const result = Bun.spawnSync(
  [
    process.execPath,
    '-e',
    `
    import config from './playwright.config.ts'
    console.log(JSON.stringify(config.projects?.map(({ name, dependencies }) => ({ name, dependencies }))))
  `,
  ],
  {
    cwd: new URL('../../', import.meta.url).pathname,
    env: {
      ...process.env,
      VITE_APPWRITE_ENDPOINT: 'https://example.invalid/v1',
      E2E_TEST_SESSION_SECRET: 'unused-for-config-inspection',
      E2E_TEST_EMAIL: 'test@example.invalid',
      E2E_TEST_PASSWORD: 'unused-for-config-inspection',
    },
  },
)
if (result.exitCode !== 0) {
  throw new Error(
    `Cannot inspect Playwright config: ${result.stderr.toString()}`,
  )
}
const projects = JSON.parse(result.stdout.toString()) as Array<{
  name: string
  dependencies?: string[]
}>
const localOnly = new Set([
  'self-hosted-usage',
  'self-hosted-organizations',
  'console-usage-live',
])
const job = workflow.jobs.e2e
const lanes = job.strategy.matrix.include
const selectedProjects = (args: string) =>
  args
    .trim()
    .split(/\s+/)
    .map((arg) => {
      expect(arg.startsWith('--project=')).toBe(true)
      return arg.slice('--project='.length)
    })

describe('E2E shared-account isolation', () => {
  test('all CI projects needing auth use one Playwright invocation', () => {
    const authenticated = projects
      .filter(
        (project) =>
          project.dependencies?.includes('setup') &&
          !localOnly.has(project.name),
      )
      .map((project) => project.name)
      .sort()
    const live = lanes.filter((lane) => lane.project === 'console-live')
    expect(live).toHaveLength(1)
    expect(selectedProjects(live[0]!.args).sort()).toEqual(authenticated)
    for (const lane of lanes.filter(
      (lane) => lane.project !== 'console-live',
    )) {
      expect(
        selectedProjects(lane.args).some((name) =>
          authenticated.includes(name),
        ),
      ).toBe(false)
    }
    expect(job.steps.find((step) => step.name === 'E2E Tests')?.run).toBe(
      'bun run e2e -- ${{ matrix.args }}',
    )
  })

  test('keeps public and mocked coverage without duplicate or local-only projects', () => {
    expect(lanes.flatMap((lane) => selectedProjects(lane.args)).sort()).toEqual(
      projects
        .filter(
          (project) => project.name !== 'setup' && !localOnly.has(project.name),
        )
        .map((project) => project.name)
        .sort(),
    )
    expect(job.strategy['fail-fast']).toBe(false)
  })

  test('live concurrency is account-scoped, not PR-scoped, and allows teardown', () => {
    expect(job.concurrency.group).toBe(
      "e2e-${{ matrix.project }}-${{ matrix.project == 'console-live' && 'shared-account' || github.run_id }}",
    )
    expect(job.concurrency['cancel-in-progress']).toBe(false)
  })
})
