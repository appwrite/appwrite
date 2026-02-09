/**
 * Site wizard environment variables – re-exports shared card with WizardVariable type.
 */

import { EnvironmentVariablesCard } from '@/components/global/shared/EnvironmentVariablesCard'
import type { WizardVariable } from './WizardContext'

interface EnvironmentVariablesProps {
  variables: WizardVariable[]
  onChange: (variables: WizardVariable[]) => void
  disabled?: boolean
  className?: string
  defaultOpen?: boolean
}

export function EnvironmentVariables(props: EnvironmentVariablesProps) {
  return <EnvironmentVariablesCard {...props} />
}
