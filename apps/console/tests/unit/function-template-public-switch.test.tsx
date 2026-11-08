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
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, fireEvent, render, waitFor } from '@testing-library/react'
import { createElement, useEffect } from 'react'
import { join } from 'node:path'
import * as actualRouter from '@tanstack/react-router'
import * as actualHooks from '../../src/lib/react-query/hooks/index.ts'
import { sdk } from '../../src/lib/appwrite/sdk.ts'

const consoleRoot = join(import.meta.dir, '../..')
const hooksPath = join(consoleRoot, 'src/lib/react-query/hooks/index.ts')
const templateConfigViewPath = join(
  consoleRoot,
  'src/components/pages/projects/$projectId/functions/create/TemplateConfigView.tsx',
)
const wizardLayoutPath = join(
  consoleRoot,
  'src/components/global/shared/WizardLayout.tsx',
)
const wizardContextPath = join(
  consoleRoot,
  'src/components/pages/projects/$projectId/functions/create/WizardContext.tsx',
)
const domainCardPath = join(
  consoleRoot,
  'src/components/pages/projects/$projectId/functions/create/_components/FunctionDomainCard.tsx',
)
const connectRepoPath = join(
  consoleRoot,
  'src/components/global/shared/ConnectRepositorySection.tsx',
)
const variablesCardPath = join(
  consoleRoot,
  'src/components/global/shared/VariablesSettingsCard.tsx',
)

type MockTemplate = {
  id: string
  name: string
  permissions?: string[]
  runtimes?: Array<{ name: string; entrypoint?: string; commands?: string }>
  variables?: Array<{ name: string; value: string; secret: boolean }>
  providerRepositoryId?: string
  providerOwner?: string
  providerVersion?: string
}

let currentTemplate: MockTemplate | null = null
let currentTemplateLoading = false
const mockCreateFunction = mock(async (...args: unknown[]) => {
  void args
  return { $id: 'func-1' }
})
const mockCreateTemplateDeployment = mock(async () => ({ $id: 'dep-1' }))
const mockCreateFunctionRule = mock(async () => ({}))
const mockCreateVariable = mock(async () => ({}))

function getLastCreateCallArgs(): { execute?: string[] } {
  const calls = mockCreateFunction.mock.calls as unknown as Array<
    [{ execute?: string[] }]
  >
  return calls[0]?.[0] ?? {}
}

function installMocks() {
  mock.module(wizardLayoutPath, () => ({
    WizardLayout: ({
      children,
      footer,
    }: {
      children: React.ReactNode
      footer?: React.ReactNode
    }) =>
      createElement(
        'div',
        { 'data-testid': 'wizard-layout' },
        children,
        footer,
      ),
  }))

  mock.module(wizardContextPath, () => ({
    useFunctionWizard: () => ({
      formData: {},
      updateFormData: () => {},
      installations: [],
      generateDomain: (name: string) => `${name}.appwrite.test`,
    }),
    FunctionWizardProvider: ({ children }: { children: React.ReactNode }) =>
      children,
  }))

  mock.module(domainCardPath, () => ({
    FunctionDomainCard: ({
      setDomainValid,
    }: {
      setDomainValid: (valid: boolean) => void
    }) => {
      // automatically mark domain as valid for tests
      useEffect(() => {
        setDomainValid(true)
      }, [setDomainValid])
      return createElement('div', { 'data-testid': 'mock-domain-card' })
    },
  }))

  mock.module(connectRepoPath, () => ({
    ConnectRepositorySection: () => null,
  }))

  mock.module(variablesCardPath, () => ({
    VariablesSettingsCard: () => null,
  }))

  mock.module('@tanstack/react-router', () => ({
    ...actualRouter,
    useParams: () => ({ projectId: 'test-project' }),
    useNavigate: () => () => {},
  }))

  mock.module(hooksPath, () => ({
    ...actualHooks,
    useProject: () => ({
      project: {
        $id: 'test-project',
        name: 'Test Project',
        teamId: 'team-1',
        region: 'fra',
      },
    }),
    useFunctionTemplate: () => ({
      data: currentTemplate,
      isLoading: currentTemplateLoading,
    }),
    useFunctionSpecifications: () => ({
      data: { specifications: [] },
    }),
  }))
}

beforeAll(async () => {
  GlobalRegistrator.register()
  installMocks()

  const projectSdk = sdk.forProject('test-project')
  projectSdk.functions.create =
    mockCreateFunction as unknown as typeof projectSdk.functions.create
  projectSdk.functions.createTemplateDeployment =
    mockCreateTemplateDeployment as unknown as typeof projectSdk.functions.createTemplateDeployment
  projectSdk.functions.createVariable =
    mockCreateVariable as unknown as typeof projectSdk.functions.createVariable
  projectSdk.proxy.createFunctionRule =
    mockCreateFunctionRule as unknown as typeof projectSdk.proxy.createFunctionRule
})

afterAll(async () => {
  cleanup()
  mock.restore()
  await GlobalRegistrator.unregister()
})

afterEach(() => {
  cleanup()
  mockCreateFunction.mockClear()
  mockCreateTemplateDeployment.mockClear()
  currentTemplate = null
  currentTemplateLoading = false
})

async function renderTemplateConfig(templateId = 'starter') {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  })

  const { TemplateConfigView } = await import(templateConfigViewPath)
  return render(
    createElement(
      QueryClientProvider,
      { client: queryClient },
      createElement(TemplateConfigView, { templateId }),
    ),
  )
}

describe('TemplateConfigView Public switch & permissions', () => {
  test('initializes Public switch to ON when template has permissions: ["any"]', async () => {
    currentTemplate = {
      id: 'starter',
      name: 'Starter Function',
      permissions: ['any'],
      runtimes: [{ name: 'node-18.0' }],
      providerRepositoryId: 'starter-repo',
      providerOwner: 'appwrite',
    }

    const view = await renderTemplateConfig('starter')
    const switchElement = view.getByRole('switch')
    expect(switchElement.getAttribute('aria-checked')).toBe('true')
  })

  test('sends execute: [] when Public switch is toggled OFF even if template has permissions: ["any"]', async () => {
    currentTemplate = {
      id: 'starter',
      name: 'Starter Function',
      permissions: ['any'],
      runtimes: [{ name: 'node-18.0' }],
      providerRepositoryId: 'starter-repo',
      providerOwner: 'appwrite',
    }

    const view = await renderTemplateConfig('starter')
    const switchElement = view.getByRole('switch')
    expect(switchElement.getAttribute('aria-checked')).toBe('true')

    // Turn switch OFF
    fireEvent.click(switchElement)
    expect(switchElement.getAttribute('aria-checked')).toBe('false')

    // Click "Create and deploy"
    const deployButton = view.getByText('Create and deploy')
    fireEvent.click(deployButton)

    await waitFor(() => expect(mockCreateFunction).toHaveBeenCalledTimes(1))
    // Must be [] and NOT fall back to ['any']
    expect(getLastCreateCallArgs().execute).toEqual([])
  })

  test('sends execute: ["any"] when Public switch is ON', async () => {
    currentTemplate = {
      id: 'starter',
      name: 'Starter Function',
      permissions: ['any'],
      runtimes: [{ name: 'node-18.0' }],
      providerRepositoryId: 'starter-repo',
      providerOwner: 'appwrite',
    }

    const view = await renderTemplateConfig('starter')
    const deployButton = view.getByText('Create and deploy')
    fireEvent.click(deployButton)

    await waitFor(() => expect(mockCreateFunction).toHaveBeenCalledTimes(1))
    expect(getLastCreateCallArgs().execute).toEqual(['any'])
  })

  test('does not reset user choice on later renders with preloaded template', async () => {
    currentTemplate = {
      id: 'starter',
      name: 'Starter Function',
      permissions: ['any'],
      runtimes: [{ name: 'node-18.0' }],
      providerRepositoryId: 'starter-repo',
      providerOwner: 'appwrite',
    }

    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    })
    const { TemplateConfigView } = await import(templateConfigViewPath)
    const view = render(
      createElement(
        QueryClientProvider,
        { client: queryClient },
        createElement(TemplateConfigView, { templateId: 'starter' }),
      ),
    )

    const switchElement = view.getByRole('switch')
    expect(switchElement.getAttribute('aria-checked')).toBe('true')

    // User explicitly turns OFF the switch
    fireEvent.click(switchElement)
    expect(switchElement.getAttribute('aria-checked')).toBe('false')

    // Force component to re-render
    view.rerender(
      createElement(
        QueryClientProvider,
        { client: queryClient },
        createElement(TemplateConfigView, { templateId: 'starter' }),
      ),
    )

    // Switch MUST stay OFF and not reset to true!
    expect(switchElement.getAttribute('aria-checked')).toBe('false')

    // Deploy and verify execute is still []
    const deployButton = view.getByText('Create and deploy')
    fireEvent.click(deployButton)

    await waitFor(() => expect(mockCreateFunction).toHaveBeenCalledTimes(1))
    expect(getLastCreateCallArgs().execute).toEqual([])
  })

  test('initializes Public switch to OFF when template has permissions: []', async () => {
    currentTemplate = {
      id: 'private-template',
      name: 'Private Function',
      permissions: [],
      runtimes: [{ name: 'node-18.0' }],
      providerRepositoryId: 'private-repo',
      providerOwner: 'appwrite',
    }

    const view = await renderTemplateConfig('private-template')
    const switchElement = view.getByRole('switch')
    expect(switchElement.getAttribute('aria-checked')).toBe('false')

    // Deploy with default OFF
    const deployButton = view.getByText('Create and deploy')
    fireEvent.click(deployButton)

    await waitFor(() => expect(mockCreateFunction).toHaveBeenCalledTimes(1))
    expect(getLastCreateCallArgs().execute).toEqual([])
  })

  test('turning switch ON for a template with permissions: [] sends execute: ["any"]', async () => {
    currentTemplate = {
      id: 'private-template',
      name: 'Private Function',
      permissions: [],
      runtimes: [{ name: 'node-18.0' }],
      providerRepositoryId: 'private-repo',
      providerOwner: 'appwrite',
    }

    const view = await renderTemplateConfig('private-template')
    const switchElement = view.getByRole('switch')
    expect(switchElement.getAttribute('aria-checked')).toBe('false')

    // Turn switch ON
    fireEvent.click(switchElement)
    expect(switchElement.getAttribute('aria-checked')).toBe('true')

    const deployButton = view.getByText('Create and deploy')
    fireEvent.click(deployButton)

    await waitFor(() => expect(mockCreateFunction).toHaveBeenCalledTimes(1))
    expect(getLastCreateCallArgs().execute).toEqual(['any'])
  })

  test('initializes Public switch to ON when template has permissions: undefined', async () => {
    currentTemplate = {
      id: 'default-template',
      name: 'Default Function',
      permissions: undefined,
      runtimes: [{ name: 'node-18.0' }],
      providerRepositoryId: 'default-repo',
      providerOwner: 'appwrite',
    }

    const view = await renderTemplateConfig('default-template')
    const switchElement = view.getByRole('switch')
    expect(switchElement.getAttribute('aria-checked')).toBe('true')
  })

  test('initializes correctly when template loads asynchronously', async () => {
    currentTemplate = null
    currentTemplateLoading = true

    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    })
    const { TemplateConfigView } = await import(templateConfigViewPath)
    const view = render(
      createElement(
        QueryClientProvider,
        { client: queryClient },
        createElement(TemplateConfigView, { templateId: 'async-template' }),
      ),
    )

    // While loading, the switch is not rendered (loader is shown)
    expect(view.queryByRole('switch')).toBeNull()

    // Template finishes loading with permissions: []
    currentTemplate = {
      id: 'async-template',
      name: 'Async Private Function',
      permissions: [],
      runtimes: [{ name: 'node-18.0' }],
      providerRepositoryId: 'async-repo',
      providerOwner: 'appwrite',
    }
    currentTemplateLoading = false

    view.rerender(
      createElement(
        QueryClientProvider,
        { client: queryClient },
        createElement(TemplateConfigView, { templateId: 'async-template' }),
      ),
    )

    // Once loaded, switch reflects template's default (false)
    const switchElement = await view.findByRole('switch')
    expect(switchElement.getAttribute('aria-checked')).toBe('false')

    // User toggles switch ON
    fireEvent.click(switchElement)
    expect(switchElement.getAttribute('aria-checked')).toBe('true')

    // Later render does not reset user choice
    view.rerender(
      createElement(
        QueryClientProvider,
        { client: queryClient },
        createElement(TemplateConfigView, { templateId: 'async-template' }),
      ),
    )
    expect(switchElement.getAttribute('aria-checked')).toBe('true')

    // Deploy sends execute: ['any']
    const deployButton = view.getByText('Create and deploy')
    fireEvent.click(deployButton)

    await waitFor(() => expect(mockCreateFunction).toHaveBeenCalledTimes(1))
    expect(getLastCreateCallArgs().execute).toEqual(['any'])
  })
})
