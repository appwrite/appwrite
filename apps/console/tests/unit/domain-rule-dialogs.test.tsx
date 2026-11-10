import { afterAll, afterEach, describe, expect, mock, test } from 'bun:test'
import { GlobalRegistrator } from '@happy-dom/global-registrator'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createElement, type ReactNode } from 'react'
import { join } from 'node:path'
import type { Models } from '@appwrite.io/console'
// Loaded before the DOM exists, so it does not sync its clock with the server
import '../../src/lib/appwrite/sdk.ts'

GlobalRegistrator.register()
const { cleanup, render } = await import('@testing-library/react')

const consoleRoot = join(import.meta.dir, '../..')
const domainsDir = join(
  consoleRoot,
  'src/components/pages/projects/$projectId/settings/domains',
)

// The hooks pull in billing; loading Stripe would inject its script into the DOM
mock.module('@stripe/stripe-js', () => ({ loadStripe: async () => null }))

mock.module(
  join(consoleRoot, 'src/components/pages/docs/DocsRouteLink.tsx'),
  () => ({
    DocsRouteLink: ({ children }: { children?: ReactNode }) =>
      createElement('a', null, children),
  }),
)

// Radix mounts a dialog's portal only if a DOM existed when it first loaded,
// which depends on the files run before this one, so the dialog renders in
// place. The real module is copied to put it back afterwards.
const dialogPath = join(consoleRoot, 'src/components/ui/dialog.tsx')
const realDialog = { ...(await import(dialogPath)) }
const part = ({ children }: { children?: ReactNode }) =>
  createElement('div', null, children)
mock.module(dialogPath, () => ({
  ...realDialog,
  Dialog: ({ open, children }: { open?: boolean; children?: ReactNode }) =>
    open ? createElement('div', null, children) : null,
  DialogContent: part,
  DialogHeader: part,
  DialogTitle: part,
  DialogDescription: part,
}))

const { ClaimDomainContent } = await import(
  join(domainsDir, 'ClaimDomainContent.tsx')
)
const { DeleteDomainDialog } = await import(
  join(domainsDir, 'DeleteDomain.tsx')
)
const { RuleStatusBadge } = await import(
  join(domainsDir, 'RuleStatusBadge.tsx')
)
const { TooltipProvider } = await import(
  join(consoleRoot, 'src/components/ui/tooltip.tsx')
)

function wrap(element: ReactNode) {
  return render(
    createElement(
      QueryClientProvider,
      { client: new QueryClient() },
      createElement(TooltipProvider, null, element),
    ),
  )
}

const rule = (fields: Record<string, string>) =>
  ({
    $id: 'rule1',
    domain: 'shop.example.com',
    status: 'verified',
    logs: '',
    certificateStatus: '',
    ...fields,
  }) as Models.ProxyRule

afterEach(() => cleanup())

afterAll(async () => {
  mock.module(dialogPath, () => realDialog)
  mock.restore()
  await GlobalRegistrator.unregister()
})

describe('ClaimDomainContent', () => {
  test('says another project uses the domain when the create needs a claim', () => {
    const view = wrap(
      createElement(ClaimDomainContent, {
        domain: 'shop.example.com',
        projectId: 'project1',
        claimRequired: true,
      }),
    )

    expect(
      view.getByText(
        'This domain is used by another project. Add this TXT record to prove it is yours, then verify to move it here.',
      ),
    ).toBeTruthy()
    expect(view.queryByText(/already points to Appwrite/)).toBeNull()
  })

  test('says the domain already points to Appwrite when no project holds it', () => {
    const view = wrap(
      createElement(ClaimDomainContent, {
        domain: 'shop.example.com',
        projectId: 'project1',
      }),
    )

    expect(
      view.getByText(
        'This domain already points to Appwrite. Add this TXT record to prove it is yours, then verify.',
      ),
    ).toBeTruthy()
    expect(view.queryByText(/used by another project/)).toBeNull()
  })
})

describe('DeleteDomainDialog', () => {
  test('names the domain it deletes', () => {
    const view = wrap(
      createElement(DeleteDomainDialog, {
        open: true,
        onOpenChange: () => {},
        projectId: 'project1',
        rule: rule({}),
        onDeleteSuccess: () => {},
      }),
    )

    expect(view.getByText('shop.example.com').tagName).toBe('STRONG')
  })
})

describe('RuleStatusBadge', () => {
  test('shows a verifying rule as being issued', () => {
    const view = wrap(
      createElement(RuleStatusBadge, { rule: rule({ status: 'verifying' }) }),
    )

    expect(
      view.container.querySelector(
        '[title="SSL certificate is being issued. This usually takes a couple of minutes."]',
      ),
    ).toBeTruthy()
    expect(view.container.querySelector('.animate-spin')).toBeTruthy()
  })

  test('shows no spinner on a verifying rule blocked on a DNS record', () => {
    const view = wrap(
      createElement(RuleStatusBadge, {
        rule: rule({
          status: 'verifying',
          certificateStatus: 'blocked',
          logs: 'SSL certificate is waiting on domain verification: add the CAA record.',
        }),
      }),
    )

    expect(view.getByText('Certificate blocked')).toBeTruthy()
    expect(view.container.querySelector('[title]')).toBeNull()
    expect(view.container.querySelector('.animate-spin')).toBeNull()
  })
})
