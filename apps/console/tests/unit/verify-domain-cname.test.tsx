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
      a: undefined,
      aaaa: undefined,
      caa: undefined,
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
})

function renderContent(resourceType: 'api' | 'function' | 'site') {
  const rule = { domain: 'app.custom.com' } as Models.ProxyRule
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
