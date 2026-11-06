import { afterAll, afterEach, describe, expect, mock, test } from 'bun:test'
import { GlobalRegistrator } from '@happy-dom/global-registrator'
import { AppwriteException } from '@appwrite.io/console'
import * as actualRouter from '@tanstack/react-router'
import { createElement } from 'react'
import { join } from 'node:path'
import * as actualConsoleProfile from '../../src/hooks/use-console-profile.ts'
import * as actualConsoleProfiles from '../../src/lib/console-profiles.ts'
import * as actualHooks from '../../src/lib/react-query/hooks/index.ts'
import * as actualProxyDomains from '../../src/lib/utils/proxy-domains.ts'

GlobalRegistrator.register()
const { act, cleanup, fireEvent, render } = await import(
  '@testing-library/react'
)

const consoleRoot = join(import.meta.dir, '../..')

const consoleProfilePath = join(consoleRoot, 'src/hooks/use-console-profile.ts')
const consoleProfilesPath = join(consoleRoot, 'src/lib/console-profiles.ts')
const hooksPath = join(consoleRoot, 'src/lib/react-query/hooks/index.ts')
const proxyDomainsPath = join(consoleRoot, 'src/lib/utils/proxy-domains.ts')
const inputPath = join(consoleRoot, 'src/components/ui/input.tsx')
const docsRouteLinkPath = join(
  consoleRoot,
  'src/components/pages/docs/DocsRouteLink.tsx',
)
const wizardPath = join(
  consoleRoot,
  'src/components/pages/projects/$projectId/functions/domains/AddSmtpDomainWizard.tsx',
)

let creates: string[] = []

// React DOM wires up typing only if a DOM existed when it first loaded, which
// depends on the files run before this one, so the test types through props.
let typeDomain: (value: string) => void = () => {}
function FakeInput(props: {
  placeholder?: string
  value?: string
  onChange?: (event: { target: { value: string } }) => void
}) {
  typeDomain = (value) => props.onChange?.({ target: { value } })
  return createElement('input', {
    placeholder: props.placeholder,
    value: props.value,
    readOnly: true,
  })
}

function installMocks() {
  mock.module('@tanstack/react-router', () => ({
    ...actualRouter,
    useNavigate: () => () => {},
    useParams: () => ({ projectId: 'project1', functionId: 'function1' }),
  }))

  mock.module(consoleProfilePath, () => ({
    ...actualConsoleProfile,
    useConsoleProfile: () => ({ isCloud: true }),
  }))

  mock.module(consoleProfilesPath, () => ({
    ...actualConsoleProfiles,
    isCloudProfile: () => true,
  }))

  mock.module(proxyDomainsPath, () => ({
    ...actualProxyDomains,
    ensureApexDomainInOrganization: async () => {},
  }))

  const mutation = (mutateAsync: (arg: never) => Promise<unknown>) => ({
    mutateAsync,
    isPending: false,
  })
  mock.module(hooksPath, () => ({
    ...actualHooks,
    useProject: () => ({ project: { teamId: 'team1', region: 'fra' } }),
    useProjectFunction: () => ({ data: { emailEnabled: true } }),
    useCreateSmtpDomainRule: () =>
      mutation(async ({ domain }: { domain: string }) => {
        creates.push(domain)
        throw new AppwriteException(
          'Domain is already used by another project.',
          409,
          'rule_claim_required',
        )
      }),
    useVerifyDomain: () => mutation(async () => ({})),
    useDeleteDomain: () => mutation(async () => ({})),
  }))

  mock.module(inputPath, () => ({ Input: FakeInput }))

  mock.module(docsRouteLinkPath, () => ({
    DocsRouteLink: () => null,
  }))
}

installMocks()

afterAll(async () => {
  cleanup()
  mock.restore()
  await GlobalRegistrator.unregister()
})

afterEach(() => {
  cleanup()
  creates = []
})

describe('AddSmtpDomainWizard claim step', () => {
  test('asks for the TXT record when another project holds the domain, and verifies by creating again', async () => {
    const { AddSmtpDomainWizard } = await import(wizardPath)
    const view = render(createElement(AddSmtpDomainWizard))

    act(() => typeDomain('mail.example.com'))
    fireEvent.click(view.getByText('Add'))

    expect(await view.findByText('Verify domain ownership')).toBeTruthy()
    expect(
      view.getByText(
        'This domain is used by another project. Add this TXT record to prove it is yours, then verify to move it here.',
      ),
    ).toBeTruthy()
    expect(view.getAllByText('_appwrite.mail').length).toBeGreaterThan(0)

    fireEvent.click(view.getByText('Verify'))
    await view.findByRole('alert')
    expect(creates).toEqual(['mail.example.com', 'mail.example.com'])
  })
})
