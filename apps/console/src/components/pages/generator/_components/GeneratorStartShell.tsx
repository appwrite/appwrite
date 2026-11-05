import type { ReactNode } from 'react'
import {
  CreateWizardLeftColumn,
  CreateWizardRightColumn,
} from '@/components/global/shared/CreateWizardColumns'

type GeneratorStartShellProps = {
  title: string
  description: string
  savedTitle: string
  templatesTitle: string
  saved: ReactNode
  templates: ReactNode
}

export function GeneratorStartShell({
  title,
  description,
  savedTitle,
  templatesTitle,
  saved,
  templates,
}: GeneratorStartShellProps) {
  return (
    <div className="flex h-full min-h-0 flex-col overflow-hidden bg-background">
      <div className="flex min-h-0 flex-1 flex-col px-4 py-6 sm:px-6 lg:py-8">
        <div className="mb-6 shrink-0 space-y-2">
          <h2 className="text-[22px] font-semibold tracking-tight text-foreground">
            {title}
          </h2>
          <p className="max-w-3xl text-[13px] text-muted-foreground">{description}</p>
        </div>

        <div className="grid min-h-0 min-w-0 flex-1 gap-8 overflow-hidden lg:grid-cols-5 lg:gap-10">
          <CreateWizardLeftColumn title={savedTitle}>{saved}</CreateWizardLeftColumn>
          <CreateWizardRightColumn title={templatesTitle}>{templates}</CreateWizardRightColumn>
        </div>
      </div>
    </div>
  )
}
