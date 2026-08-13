/**
 * Site Creation Wizard Context
 *
 * Provides shared state and utilities for the multi-step site creation wizard.
 * Manages form data, navigation state, and SDK calls across all wizard screens.
 */

import {
  createContext,
  useContext,
  useState,
  useCallback,
  useEffect,
  useMemo,
  ReactNode,
} from 'react'
import type { Models } from '@appwrite.io/console'
import { getFrameworkCreateDefaults } from '@/lib/frameworks'

/**
 * Environment variable entry for the wizard
 */
export interface WizardVariable {
  key: string
  value: string
  secret: boolean
}

/**
 * Wizard form data shape
 */
export interface WizardFormData {
  // Site details
  siteName: string
  siteId: string | undefined

  // Git configuration
  installationId: string | undefined
  providerRepositoryId: string | undefined
  providerBranch: string
  providerRootDirectory: string
  providerSilentMode: boolean

  // Template configuration
  templateId: string | undefined
  template: Models.TemplateSite | undefined

  // Framework and build settings
  framework: string
  buildRuntime: string | undefined
  installCommand: string
  buildCommand: string
  startCommand: string
  outputDirectory: string
  fallbackFile: string

  // Environment variables
  variables: WizardVariable[]

  // Domain configuration
  domain: string
  domainValid: boolean

  // Repository info (for display)
  repositoryOwner: string | undefined
  repositoryName: string | undefined
  repositoryUrl: string | undefined

  // Created resources (for deploying/finish screens)
  createdSiteId: string | undefined
  createdDeploymentId: string | undefined

  // Upload file (for manual path)
  uploadFile: File | undefined
}

/**
 * Default wizard form data
 */
const defaultFormData: WizardFormData = {
  siteName: '',
  siteId: undefined,
  installationId: undefined,
  providerRepositoryId: undefined,
  providerBranch: '',
  providerRootDirectory: './',
  providerSilentMode: false,
  templateId: undefined,
  template: undefined,
  framework: '',
  buildRuntime: undefined,
  installCommand: '',
  buildCommand: '',
  startCommand: '',
  outputDirectory: '',
  fallbackFile: '',
  variables: [],
  domain: '',
  domainValid: false,
  repositoryOwner: undefined,
  repositoryName: undefined,
  repositoryUrl: undefined,
  createdSiteId: undefined,
  createdDeploymentId: undefined,
  uploadFile: undefined,
}

const FORM_DATA_STORAGE_KEY = 'sites-create-wizard-form-data'

/**
 * Wizard path type
 */
export type WizardPath = 'repository' | 'template' | 'manual' | 'deploy'

/**
 * Wizard context value
 */
interface WizardContextValue {
  // Form data
  formData: WizardFormData
  updateFormData: (updates: Partial<WizardFormData>) => void
  resetFormData: () => void

  // Navigation
  currentPath: WizardPath | undefined
  setCurrentPath: (path: WizardPath | undefined) => void

  // Installations (shared across wizard)
  installations: Models.Installation[]
  setInstallations: (installations: Models.Installation[]) => void

  // Frameworks (shared across wizard)
  frameworks: Models.Framework[]
  setFrameworks: (frameworks: Models.Framework[]) => void

  // Helper to get framework by key
  getFramework: (key: string) => Models.Framework | undefined

  // Helper to get default build settings for a framework (from SDK listFrameworks: adapters + buildRuntime)
  getFrameworkDefaults: (frameworkKey: string) => {
    installCommand: string
    buildCommand: string
    outputDirectory: string
    buildRuntime: string
    adapter: string
    fallbackFile: string
  }

  // Domain generation helper
  generateDomain: (name: string) => string
  baseDomain: string
  setBaseDomain: (domain: string) => void
}

const WizardContext = createContext<WizardContextValue | null>(null)

/**
 * Hook to access wizard context
 */
export function useWizard() {
  const context = useContext(WizardContext)
  if (!context) {
    throw new Error('useWizard must be used within a WizardProvider')
  }
  return context
}

/**
 * Wizard provider component
 */
export function WizardProvider({ children }: { children: ReactNode }) {
  // Reconnecting a Git installation leaves the page and comes back, and the
  // path keeps an abandoned run out of the next site created in the same tab
  const [formData, setFormData] = useState<WizardFormData>(() => {
    if (typeof window === 'undefined') return defaultFormData
    try {
      const raw = sessionStorage.getItem(FORM_DATA_STORAGE_KEY)
      if (!raw) return defaultFormData
      const { path, formData: stored } = JSON.parse(raw) as {
        path?: string
        formData?: Partial<WizardFormData>
      }
      if (path !== window.location.pathname) return defaultFormData
      return { ...defaultFormData, ...stored }
    } catch {
      return defaultFormData
    }
  })

  useEffect(() => {
    // `variables` holds values typed into secret fields; the rest are either
    // refetched or not serialisable
    const {
      variables: _variables,
      uploadFile: _uploadFile,
      template: _template,
      createdSiteId: _createdSiteId,
      createdDeploymentId: _createdDeploymentId,
      ...stored
    } = formData

    try {
      sessionStorage.setItem(
        FORM_DATA_STORAGE_KEY,
        JSON.stringify({ path: window.location.pathname, formData: stored }),
      )
    } catch {
      // ignore quota errors
    }
  }, [formData])
  const [currentPath, setCurrentPath] = useState<WizardPath | undefined>(
    undefined,
  )
  const [installations, setInstallations] = useState<Models.Installation[]>([])
  const [frameworks, setFrameworks] = useState<Models.Framework[]>([])
  const [baseDomain, setBaseDomain] = useState<string>('appwrite.network')

  const updateFormData = useCallback((updates: Partial<WizardFormData>) => {
    setFormData((prev) => ({ ...prev, ...updates }))
  }, [])

  const resetFormData = useCallback(() => {
    setFormData(defaultFormData)
  }, [])

  const getFramework = useCallback(
    (key: string) => {
      return frameworks.find((f) => f.key === key)
    },
    [frameworks],
  )

  const getFrameworkDefaults = useCallback(
    (frameworkKey: string) =>
      getFrameworkCreateDefaults(getFramework(frameworkKey)),
    [getFramework],
  )

  const generateDomain = useCallback(
    (name: string) => {
      // Convert name to URL-safe subdomain
      const subdomain = name
        .toLowerCase()
        .replace(/[^a-z0-9-]/g, '-')
        .replace(/-+/g, '-')
        .replace(/^-|-$/g, '')
        .substring(0, 63) // Max subdomain length

      return subdomain ? `${subdomain}.${baseDomain}` : ''
    },
    [baseDomain],
  )

  const value = useMemo<WizardContextValue>(
    () => ({
      formData,
      updateFormData,
      resetFormData,
      currentPath,
      setCurrentPath,
      installations,
      setInstallations,
      frameworks,
      setFrameworks,
      getFramework,
      getFrameworkDefaults,
      generateDomain,
      baseDomain,
      setBaseDomain,
    }),
    [
      formData,
      updateFormData,
      resetFormData,
      currentPath,
      setCurrentPath,
      installations,
      setInstallations,
      frameworks,
      setFrameworks,
      getFramework,
      getFrameworkDefaults,
      generateDomain,
      baseDomain,
      setBaseDomain,
    ],
  )

  return (
    <WizardContext.Provider value={value}>{children}</WizardContext.Provider>
  )
}
