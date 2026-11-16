import { afterAll, afterEach, describe, expect, mock, test } from 'bun:test'
import { GlobalRegistrator } from '@happy-dom/global-registrator'
import { createElement } from 'react'

// PlanLimitWarning's import graph loads billing, which injects Stripe.
mock.module('@stripe/stripe-js', () => ({ loadStripe: async () => null }))

GlobalRegistrator.register({ url: 'http://localhost/' })
const { cleanup, render } = await import('@testing-library/react')
const { PlanLimitWarning } = await import(
  '../../src/components/pages/projects/$projectId/shared/PlanLimitWarning.tsx'
)

afterEach(() => cleanup())
afterAll(() => GlobalRegistrator.unregister())

function renderWarning(
  limit: number | bigint,
  currentCount: number,
  resourceName = 'domains',
  resourceNameSingular = 'domain',
) {
  return render(
    createElement(PlanLimitWarning, {
      currentCount,
      limit,
      planName: 'Free',
      resourceName,
      resourceNameSingular,
      orgId: null,
    }),
  )
}

describe('PlanLimitWarning', () => {
  test('a limit of 1 uses the singular resource name', () => {
    const view = renderWarning(1, 1)

    expect(view.container.textContent).toContain(
      "You've reached the limit of 1 domain",
    )
    expect(view.container.textContent).toContain('includes up to 1 domain.')
    expect(view.container.textContent).not.toContain('1 domains')
  })

  test('a bigint limit of 1 uses the singular resource name', () => {
    const view = renderWarning(1n, 1)

    expect(view.container.textContent).toContain(
      "You've reached the limit of 1 domain",
    )
    expect(view.container.textContent).not.toContain('1 domains')
  })

  test('a limit above 1 keeps the plural resource name', () => {
    const view = renderWarning(2, 2)

    expect(view.container.textContent).toContain(
      "You've reached the limit of 2 domains",
    )
    expect(view.container.textContent).toContain('includes up to 2 domains.')
  })

  test('approaching a limit above 1 stays plural', () => {
    const view = renderWarning(4, 2)

    expect(view.container.textContent).toContain(
      'Approaching the limit for domains',
    )
    expect(view.container.textContent).toContain('includes up to 4 domains.')
    expect(view.container.textContent).not.toContain('domain.')
  })

  test('multi-word resources pluralize from the singular label', () => {
    const view = renderWarning(1, 1, 'firewall rules', 'firewall rule')

    expect(view.container.textContent).toContain('1 firewall rule')
    expect(view.container.textContent).not.toContain('1 firewall rules')
  })

  test('hides the alert below half the limit and when the plan is unlimited', () => {
    expect(renderWarning(4, 1).container.textContent).toBe('')
    cleanup()
    expect(renderWarning(0, 3).container.textContent).toBe('')
  })
})
