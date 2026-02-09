/**
 * Function Creation Wizard Context
 *
 * Provides shared state for the function creation wizard.
 * Manages form data and installations across wizard screens.
 */

import {
  createContext,
  useContext,
  useState,
  useCallback,
  useMemo,
  ReactNode,
} from 'react'
import type { Models } from '@appwrite.io/console'

export interface FunctionWizardFormData {
  installationId: string | undefined
  providerRepositoryId: string | undefined
  repositoryOwner: string | undefined
  repositoryName: string | undefined
  repositoryUrl: string | undefined
  functionName: string
  runtime: string | undefined
  template: Models.TemplateFunction | undefined
  templateId: string | undefined
}

const defaultFormData: FunctionWizardFormData = {
  installationId: undefined,
  providerRepositoryId: undefined,
  repositoryOwner: undefined,
  repositoryName: undefined,
  repositoryUrl: undefined,
  functionName: '',
  runtime: undefined,
  template: undefined,
  templateId: undefined,
}

interface FunctionWizardContextValue {
  formData: FunctionWizardFormData
  updateFormData: (updates: Partial<FunctionWizardFormData>) => void
  resetFormData: () => void
  installations: Models.Installation[]
  setInstallations: (installations: Models.Installation[]) => void
  baseDomain: string
  setBaseDomain: (domain: string) => void
  generateDomain: (name: string) => string
}

const FunctionWizardContext = createContext<FunctionWizardContextValue | null>(
  null,
)

export function useFunctionWizard() {
  const context = useContext(FunctionWizardContext)
  if (!context) {
    throw new Error('useFunctionWizard must be used within FunctionWizardProvider')
  }
  return context
}

export function FunctionWizardProvider({ children }: { children: ReactNode }) {
  const [formData, setFormData] = useState<FunctionWizardFormData>(defaultFormData)
  const [installations, setInstallations] = useState<Models.Installation[]>([])
  const [baseDomain, setBaseDomain] = useState<string>('appwrite.network')

  const updateFormData = useCallback((updates: Partial<FunctionWizardFormData>) => {
    setFormData((prev) => ({ ...prev, ...updates }))
  }, [])

  const resetFormData = useCallback(() => {
    setFormData(defaultFormData)
  }, [])

  const generateDomain = useCallback(
    (name: string) => {
      const subdomain = name
        .toLowerCase()
        .replace(/[^a-z0-9-]/g, '-')
        .replace(/-+/g, '-')
        .replace(/^-|-$/g, '')
        .substring(0, 63)
      return subdomain ? `${subdomain}.${baseDomain}` : ''
    },
    [baseDomain],
  )

  const value = useMemo<FunctionWizardContextValue>(
    () => ({
      formData,
      updateFormData,
      resetFormData,
      installations,
      setInstallations,
      baseDomain,
      setBaseDomain,
      generateDomain,
    }),
    [formData, updateFormData, resetFormData, installations, baseDomain, generateDomain],
  )

  return (
    <FunctionWizardContext.Provider value={value}>
      {children}
    </FunctionWizardContext.Provider>
  )
}
