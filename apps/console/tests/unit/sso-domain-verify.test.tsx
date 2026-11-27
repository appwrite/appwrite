import { afterAll, afterEach, describe, expect, mock, test } from 'bun:test'
import { GlobalRegistrator } from '@happy-dom/global-registrator'
import { AppwriteException } from '@appwrite.io/console'
import { createElement } from 'react'
import { join } from 'node:path'
import * as actualHooks from '../../src/lib/react-query/hooks/index.ts'
import * as actualHelpers from '../../src/components/global/shared/sso/SSOCardHelpers.tsx'

GlobalRegistrator.register()
const { act, cleanup, fireEvent, render } =
  await import('@testing-library/react')

const consoleRoot = join(import.meta.dir, '../..')
const hooksPath = join(consoleRoot, 'src/lib/react-query/hooks/index.ts')
const helpersPath = join(
  consoleRoot,
  'src/components/global/shared/sso/SSOCardHelpers.tsx',
)
const dateTooltipPath = join(
  consoleRoot,
  'src/components/global/shared/DateTooltip.tsx',
)
const docsRouteLinkPath = join(
  consoleRoot,
  'src/components/pages/docs/DocsRouteLink.tsx',
)
const dialogPath = join(
  consoleRoot,
  'src/components/global/shared/sso/VerifyEmailDomain.tsx',
)

type Check = {
  status: 'verified' | 'missing' | 'mismatch' | 'misplaced' | 'failed'
  current?: string[]
  regions?: {
    id: string
    name: string
    status: 'verified' | 'missing' | 'mismatch' | 'failed'
    current: string[]
  }[]
}

function domainWith(check?: Check) {
  return {
    $id: 'domain1',
    $createdAt: '2026-11-01T00:00:00.000+00:00',
    $updatedAt: '2026-11-01T00:00:00.000+00:00',
    teamId: 'org1',
    domain: 'example.com',
    status: 'unverified',
    recordName: '_appwrite.example.com',
    recordValue: 'team:org1',
    check: check && {
      type: 'TXT',
      name: '_appwrite.example.com',
      expected: 'team:org1',
      current: check.current ?? [],
      regions: check.regions ?? [],
      status: check.status,
      message: '',
      checkedAt: '2026-11-02T00:00:00.000+00:00',
    },
  }
}

// What the list holds once a verify refetched it.
let domains: ReturnType<typeof domainWith>[] = []
// What the verify call answers, and the list it leaves behind.
let answer: { result?: { status: string }; error?: Error; after?: Check } = {}

const notFound = new AppwriteException(
  'No TXT record was found at _appwrite.example.com yet.',
  400,
  'rule_verification_failed',
)

mock.module(hooksPath, () => ({
  ...actualHooks,
  useSSODomains: () => ({ domains }),
  useVerifySSODomain: () => ({
    isPending: false,
    mutate: (
      _id: string,
      callbacks: {
        onSuccess: (domain: { status: string }) => void
        onError: (error: Error) => void
      },
    ) => {
      if (answer.after) domains = [domainWith(answer.after)]
      if (answer.error) callbacks.onError(answer.error)
      else if (answer.result) callbacks.onSuccess(answer.result)
    },
  }),
}))

mock.module(helpersPath, () => ({
  ...actualHelpers,
  useSSOAddonGate: () => ({ known: false, status: null, locked: false }),
  useSSOAddonErrorHandler: () => () => false,
}))

mock.module(dateTooltipPath, () => ({
  DateTooltip: ({ date }: { date: string }) => createElement('span', {}, date),
}))

mock.module(docsRouteLinkPath, () => ({
  DocsRouteLink: () => null,
}))

afterAll(async () => {
  cleanup()
  mock.restore()
  await GlobalRegistrator.unregister()
})

afterEach(() => {
  cleanup()
  domains = []
  answer = {}
})

async function renderDialog(
  rule: ReturnType<typeof domainWith>,
  props: {
    onOpenChange?: (open: boolean) => void
    onVerified?: () => void
  } = {},
) {
  const { VerifyEmailDomain } = await import(dialogPath)
  return render(
    createElement(VerifyEmailDomain, {
      rule,
      target: { scope: 'organization', organizationId: 'org1' },
      onOpenChange: props.onOpenChange ?? (() => {}),
      onVerified: props.onVerified,
    }),
  )
}

/** The alert an element sits in. */
function alertOf(element: HTMLElement) {
  return element.closest('[role="alert"]')?.className ?? ''
}

describe('VerifyEmailDomain record check', () => {
  test('a missing record reads as pending, not as a failure', async () => {
    // Test for SUCCESS
    answer = { error: notFound, after: { status: 'missing' } }
    domains = [domainWith()]
    const view = await renderDialog(domainWith())

    fireEvent.click(view.getByText('Verify', { selector: 'button' }))
    await act(async () => {})

    expect(view.getByText('Not detected')).toBeTruthy()
    const banner = view.getByText(
      'No TXT record was found at _appwrite.example.com yet. Add the record below, then verify again.',
    )
    expect(alertOf(banner)).toContain('amber')
    // The banner says what the check found, so the pending alert stays away.
    expect(view.queryByText('Domain not verified yet')).toBeNull()
    expect(view.getByText('2026-11-02T00:00:00.000+00:00')).toBeTruthy()
    expect(
      view.getByText(
        "Enter only _appwrite in your DNS provider's Host or Name field. Most providers add example.com for you.",
      ),
    ).toBeTruthy()
  })

  test('a wrong value shows what DNS holds and the fix', async () => {
    // Test for FAILURE
    const rule = domainWith({ status: 'mismatch', current: ['team:other'] })
    domains = [rule]
    const view = await renderDialog(rule)

    expect(view.getByText('Wrong value')).toBeTruthy()
    expect(view.getByText('Found:')).toBeTruthy()
    expect(view.getByText('team:other')).toBeTruthy()
    const banner = view.getByText(
      'It holds team:other. Change it to team:org1.',
    )
    expect(alertOf(banner)).toContain('red')
  })

  test('a record at the doubled name shows where it was found', async () => {
    // Test for FAILURE
    const rule = domainWith({
      status: 'misplaced',
      current: ['team:org1'],
    })
    domains = [rule]
    const view = await renderDialog(rule)

    expect(view.getByText('Wrong name')).toBeTruthy()
    expect(view.getByText('Found at:')).toBeTruthy()
    expect(
      view.getByText('_appwrite.example.com.example.com', { selector: 'span' }),
    ).toBeTruthy()
    const banner = view.getByText(
      'It was added as _appwrite.example.com.example.com. In your DNS provider, enter only _appwrite as the host.',
    )
    expect(alertOf(banner)).toContain('red')
  })

  test('a record some regions see is propagating, not wrong', async () => {
    // Test for SUCCESS
    const rule = domainWith({
      status: 'missing',
      regions: [
        {
          id: 'fra',
          name: 'Frankfurt',
          status: 'verified',
          current: ['team:org1'],
        },
        { id: 'nyc', name: 'New York', status: 'missing', current: [] },
      ],
    })
    domains = [rule]
    const view = await renderDialog(rule)

    expect(
      view.getByLabelText('Propagating: 1 of 2 regions see your record'),
    ).toBeTruthy()
    expect(view.queryByText('Not detected')).toBeNull()
    const banner = view.getByText(
      '1 of 2 regions see your new record. This usually finishes within an hour. Verify again in a few minutes.',
    )
    expect(alertOf(banner)).toContain('amber')
    expect(view.getByText('Checked from 2 regions')).toBeTruthy()
  })

  test('without a check from the server, a failed check is pending, in amber', async () => {
    // Test for FAILURE
    answer = { error: notFound }
    domains = [domainWith()]
    const view = await renderDialog(domainWith())

    fireEvent.click(view.getByText('Verify', { selector: 'button' }))
    await act(async () => {})

    expect(view.getByText('Domain not verified yet')).toBeTruthy()
    const message = view.getByText(
      'No TXT record was found at _appwrite.example.com yet.',
    )
    expect(alertOf(message)).toContain('amber')
    expect(view.queryByText('Not detected')).toBeNull()
  })

  test('a verified record closes the dialog', async () => {
    // Test for SUCCESS
    answer = { result: { status: 'verified' } }
    domains = [domainWith()]
    const verified: string[] = []
    const opened: boolean[] = []
    const view = await renderDialog(domainWith(), {
      onOpenChange: (open) => opened.push(open),
      onVerified: () => verified.push('domain1'),
    })

    fireEvent.click(view.getByText('Verify', { selector: 'button' }))
    await act(async () => {})

    expect(verified).toEqual(['domain1'])
    expect(opened).toEqual([false])
  })
})
