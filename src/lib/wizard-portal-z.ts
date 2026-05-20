import { cn } from '@/lib/utils'

/** Fullscreen WizardLayout uses z-[9998]; stack portals above it. */
export const WIZARD_DIALOG_OVERLAY_Z = 'z-[10050]'
export const WIZARD_DIALOG_CONTENT_Z = 'z-[10051]'

export function wizardDialogContentClassName(className?: string) {
  return cn(WIZARD_DIALOG_CONTENT_Z, className)
}

export function wizardDropdownContentClassName(className?: string) {
  return cn(WIZARD_DIALOG_OVERLAY_Z, className)
}
