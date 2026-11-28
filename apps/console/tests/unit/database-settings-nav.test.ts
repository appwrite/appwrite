import { describe, expect, test } from 'bun:test'
import { visibleDatabaseSettingsNav } from '@/components/pages/projects/$projectId/databases/settings/nav'
import { hasDedicatedSpecification } from '@/lib/databases/database-compute'

describe('database settings navigation', () => {
  test.each([[true], [false]])(
    'lists point-in-time recovery only for a dedicated database (%p)',
    (showPitr) => {
      const ids = visibleDatabaseSettingsNav({
        showSpecification: true,
        showReplication: false,
        showPitr,
      }).map((item) => item.id)

      expect(ids.includes('pitr')).toBe(showPitr)
    },
  )

  test.each([
    ['s-2vcpu-4gb', true],
    ['shared', false],
    ['serverless', false],
    ['', false],
    [null, false],
  ])('treats specification %p as dedicated: %p', (specification, expected) => {
    expect(hasDedicatedSpecification(specification)).toBe(expected)
  })
})
