/**
 * Site wizard domain input – passes baseDomain from WizardContext to shared component.
 */

import { DomainInput as SharedDomainInput } from '@/components/global/shared/DomainInput'
import { useWizard } from './WizardContext'

export { type DomainInputProps } from '@/components/global/shared/DomainInput'

export function DomainInput(
  props: Omit<React.ComponentProps<typeof SharedDomainInput>, 'baseDomain'> & {
    baseDomain?: string
  },
) {
  const { baseDomain: contextBase } = useWizard()
  const baseDomain = props.baseDomain ?? contextBase ?? 'appwrite.network'
  return <SharedDomainInput {...props} baseDomain={baseDomain} />
}
