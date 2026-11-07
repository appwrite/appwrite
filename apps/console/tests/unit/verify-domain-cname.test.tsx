import {
  afterAll,
  afterEach,
  beforeAll,
  describe,
  expect,
  mock,
  test,
} from 'bun:test'
import { GlobalRegistrator } from '@happy-dom/global-registrator'
import { cleanup, render } from '@testing-library/react'
import { createElement } from 'react'
import { join } from 'node:path'
import type { Models } from '@appwrite.io/console'
import * as actualConsoleProfile from '../../src/hooks/use-console-profile.ts'
import * as actualConsoleVariables from '../../src/lib/react-query/hooks/console-variables.ts'

const consoleRoot = join(import.meta.dir, '../..')

const consoleProfilePath = join(consoleRoot, 'src/hooks/use-console-profile.ts')
const consoleVariablesPath = join(
  consoleRoot,
  'src/lib/react-query/hooks/console-variables.ts',
)
const docsRouteLinkPath = join(
  consoleRoot,
  'src/components/pages/docs/DocsRouteLink.tsx',
)
const verifyDomainContentPath = join(
  consoleRoot,
  'src/components/pages/projects/$projectId/settings/domains/VerifyDomainContent.tsx',
)

let edgeNetwork = false
let caa: string | undefined
let a: string | undefined

function installMocks() {
  mock.module(consoleProfilePath, () => ({
    ...actualConsoleProfile,
    useConsoleProfile: () => ({
      profileId: 'self-hosted',
      profile: { id: 'self-hosted', features: { edgeNetwork } },
      features: { edgeNetwork },
    }),
  }))

  mock.module(consoleVariablesPath, () => ({
    ...actualConsoleVariables,
    useConsoleVariables: () => ({
      cname: 'target.example.com',
      a,
      aaaa: undefined,
      caa,
      nameservers: [],
      functionsDomain: 'functions.example.com',
      sitesDomain: 'sites.example.com',
      isLoading: false,
      error: null,
    }),
  }))

  mock.module(docsRouteLinkPath, () => ({
    DocsRouteLink: () => null,
  }))
}

beforeAll(() => {
  GlobalRegistrator.register()
  installMocks()
})

afterAll(async () => {
  cleanup()
  mock.restore()
  await GlobalRegistrator.unregister()
})

afterEach(() => {
  cleanup()
  edgeNetwork = false
  caa = undefined
  a = undefined
})

function renderContent(
  resourceType: 'api' | 'function' | 'site',
  domain = 'app.custom.com',
) {
  const rule = { domain } as Models.ProxyRule
  return import(verifyDomainContentPath).then(({ VerifyDomainContent }) =>
    render(createElement(VerifyDomainContent, { rule, resourceType })),
  )
}

describe('VerifyDomainContent CNAME target', () => {
  test('function domains target the API CNAME when the edge network is off', async () => {
    const view = await renderContent('function')
    expect(view.queryAllByText('target.example.com').length).toBeGreaterThan(0)
    expect(view.queryAllByText('functions.example.com')).toHaveLength(0)
  })

  test('site domains target the sites domain when the edge network is off', async () => {
    const view = await renderContent('site')
    expect(view.queryAllByText('sites.example.com').length).toBeGreaterThan(0)
    expect(view.queryAllByText('target.example.com')).toHaveLength(0)
  })

  test('API domains target the API CNAME', async () => {
    const view = await renderContent('api')
    expect(view.queryAllByText('target.example.com').length).toBeGreaterThan(0)
  })

  test('function domains target the edge network when it is on', async () => {
    edgeNetwork = true
    const view = await renderContent('function')
    expect(view.queryAllByText('appwrite.network').length).toBeGreaterThan(0)
    expect(view.queryAllByText('target.example.com')).toHaveLength(0)
  })
})

describe('VerifyDomainContent CAA record', () => {
  test('recommends the CAA record the server reports', async () => {
    caa = '0 issue "certainly.com"'
    const view = await renderContent('api')
    expect(
      view.queryAllByText('0 issue "certainly.com"').length,
    ).toBeGreaterThan(0)
  })

  test('leaves out a CAA record with no issuer, which would block every CA', async () => {
    caa = '0 issue ""'
    const view = await renderContent('api')
    expect(view.queryAllByText('0 issue ""')).toHaveLength(0)
    expect(view.queryAllByText('CAA')).toHaveLength(0)
  })
})

describe('VerifyDomainContent apex domain', () => {
  test('opens on the A record, not a CNAME on @', async () => {
    a = '1.2.3.4'
    const view = await renderContent('api', 'custom.com')
    expect(view.queryAllByText('1.2.3.4').length).toBeGreaterThan(0)
    expect(view.queryAllByText('target.example.com')).toHaveLength(0)
  })

  test('offers no A record for a site on the edge network, which it does not serve', async () => {
    edgeNetwork = true
    a = '1.2.3.4'
    const view = await renderContent('site', 'custom.com')
    expect(view.queryAllByText('1.2.3.4')).toHaveLength(0)
    expect(view.queryAllByText('appwrite.network').length).toBeGreaterThan(0)
  })
})
