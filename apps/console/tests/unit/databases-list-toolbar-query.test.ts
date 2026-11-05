import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { expect, test } from 'bun:test'

const root = join(import.meta.dir, '../..')

test('databases list empty-state total uses the unified console list', () => {
  const view = readFileSync(
    join(root, 'src/components/pages/projects/$projectId/databases/View.tsx'),
    'utf8',
  )
  const route = readFileSync(
    join(root, 'src/routes/_public/projects.$projectId.databases.index.tsx'),
    'utf8',
  )

  expect(view).toContain('consoleDatabasesQueryOptions(')
  expect(view).not.toMatch(/\bdatabasesQueryOptions\(/)
  expect(route).toContain('consoleDatabasesQueryOptions(')
  expect(route).not.toMatch(/\bdatabasesQueryOptions\(/)
})
