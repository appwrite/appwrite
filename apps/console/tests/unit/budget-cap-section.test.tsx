import { afterAll, afterEach, describe, expect, mock, test } from 'bun:test'
import { GlobalRegistrator } from '@happy-dom/global-registrator'
import { createElement } from 'react'
import { join } from 'node:path'
import * as actualHooks from '../../src/lib/react-query/hooks/index.ts'

GlobalRegistrator.register({ url: 'http://localhost/' })
const { cleanup, fireEvent, render, waitFor } = await import(
  '@testing-library/react'
)

const consoleRoot = join(import.meta.dir, '../..')

// The hooks pull in billing; loading Stripe would inject its script into the DOM
mock.module('@stripe/stripe-js', () => ({ loadStripe: async () => null }))

let billingBudget: number | null = null
const sent: Array<Record<string, unknown>> = []
mock.module(join(consoleRoot, 'src/lib/react-query/hooks/index.ts'), () => ({
  ...actualHooks,
  useOrganizationById: () => ({
    organization: { $id: 'org1', billingBudget, budgetAlerts: [75] },
    isLoading: false,
  }),
  useOrganizationPlan: () => ({ plan: { budgeting: true }, isLoading: false }),
  useUpdateOrganizationBudget: () => ({
    isPending: false,
    mutateAsync: async (params: Record<string, unknown>) => {
      sent.push(params)
    },
  }),
}))

const { BudgetCapSection } = await import(
  join(
    consoleRoot,
    'src/components/pages/organizations/$orgId/billing/BudgetCapSection.tsx',
  )
)

function renderSection(budget: number | null) {
  billingBudget = budget
  return render(createElement(BudgetCapSection, { orgId: 'org1' }))
}

afterEach(() => {
  cleanup()
  sent.length = 0
})

afterAll(() => {
  GlobalRegistrator.unregister()
})

describe('BudgetCapSection', () => {
  test('disabling the cap sends null, not a $0 cap', async () => {
    const view = renderSection(100)
    fireEvent.click(view.getByRole('switch'))

    await waitFor(() => expect(sent).toHaveLength(1))
    expect(sent[0]).toMatchObject({ organizationId: 'org1', budget: null })
  })

  test('a $0 cap shows as enabled so it can be turned off', async () => {
    const view = renderSection(0)
    const toggle = view.getByRole('switch')
    expect(toggle.getAttribute('aria-checked')).toBe('true')

    fireEvent.click(toggle)
    await waitFor(() => expect(sent).toHaveLength(1))
    expect(sent[0]?.budget).toBeNull()
  })

  test('enabling an unlimited budget sends the default cap', async () => {
    const view = renderSection(null)
    const toggle = view.getByRole('switch')
    expect(toggle.getAttribute('aria-checked')).toBe('false')

    fireEvent.click(toggle)
    await waitFor(() => expect(sent).toHaveLength(1))
    expect(sent[0]?.budget).toBe(100)
  })
})
