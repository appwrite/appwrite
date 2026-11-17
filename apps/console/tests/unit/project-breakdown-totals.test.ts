import { describe, expect, test } from 'bun:test'
import { getProjectBreakdownTotals } from '@/lib/billing/project-breakdown-totals'

describe('getProjectBreakdownTotals', () => {
  const items = [
    { projectId: 'project-a', amount: 150 },
    { projectId: 'project-b', amount: 1200 },
    { projectId: 'project-b', amount: 300 },
    { projectId: 'project-c', amount: 0 },
    { projectId: null, amount: -1000 },
    { projectId: null, amount: 0 },
  ]

  test('sorts projects by total descending and keeps their items', () => {
    const { projects } = getProjectBreakdownTotals(
      ['project-a', 'project-b', 'project-c'],
      items,
    )

    expect(projects.map((project) => project.projectId)).toEqual([
      'project-b',
      'project-a',
      'project-c',
    ])
    expect(projects[0]).toEqual({
      projectId: 'project-b',
      items: [items[1], items[2]],
      total: 15,
    })
  })

  test('subtotal sums project lines only, leaving out org-level lines', () => {
    const { subtotal } = getProjectBreakdownTotals(
      ['project-a', 'project-b', 'project-c'],
      items,
    )

    expect(subtotal).toBe(16.5)
  })

  test('projects without items total zero and ties keep the incoming order', () => {
    const { projects, subtotal } = getProjectBreakdownTotals(
      ['project-z', 'project-c', 'project-y'],
      items,
    )

    expect(projects).toEqual([
      { projectId: 'project-z', items: [], total: 0 },
      { projectId: 'project-c', items: [items[3]], total: 0 },
      { projectId: 'project-y', items: [], total: 0 },
    ])
    expect(subtotal).toBe(0)
  })

  test('keeps cent precision across many small lines', () => {
    const { subtotal } = getProjectBreakdownTotals(
      ['project-a'],
      Array.from({ length: 10 }, () => ({
        projectId: 'project-a',
        amount: 10,
      })),
    )

    expect(subtotal).toBe(1)
  })
})
