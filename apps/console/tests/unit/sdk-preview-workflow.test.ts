import { describe, expect, test } from 'bun:test'
import { join } from 'path'

const WORKFLOW = join(
  import.meta.dirname,
  '../../../../.github/workflows/sdk-preview.yml',
)

describe('SDK preview workflow', () => {
  // pkg.pr.new serves these builds under this repository's name, and the
  // Console pins them, so a pull request from a fork must not publish one.
  test('publishes Console SDK previews only from this repository', async () => {
    const workflow = Bun.YAML.parse(await Bun.file(WORKFLOW).text()) as {
      jobs: Record<string, { if?: string }>
    }
    expect(workflow.jobs.console?.if).toContain(
      'github.event.pull_request.head.repo.full_name == github.repository',
    )
  })
})
