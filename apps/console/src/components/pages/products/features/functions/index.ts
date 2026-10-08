import type { ComponentType } from 'react'
import { FunctionsBuildsVisual } from '@/components/pages/products/features/functions/FunctionsBuildsVisual'
import { FunctionsExecutionsVisual } from '@/components/pages/products/features/functions/FunctionsExecutionsVisual'
import { FunctionsGitVisual } from '@/components/pages/products/features/functions/FunctionsGitVisual'
import { FunctionsHttpVisual } from '@/components/pages/products/features/functions/FunctionsHttpVisual'
import { FunctionsLocalVisual } from '@/components/pages/products/features/functions/FunctionsLocalVisual'
import { FunctionsRuntimesVisual } from '@/components/pages/products/features/functions/FunctionsRuntimesVisual'
import { FunctionsTemplatesVisual } from '@/components/pages/products/features/functions/FunctionsTemplatesVisual'
import { FunctionsTriggersVisual } from '@/components/pages/products/features/functions/FunctionsTriggersVisual'

export const FUNCTIONS_FEATURE_VISUALS: Record<string, ComponentType> = {
  git: FunctionsGitVisual,
  builds: FunctionsBuildsVisual,
  http: FunctionsHttpVisual,
  triggers: FunctionsTriggersVisual,
  executions: FunctionsExecutionsVisual,
  runtimes: FunctionsRuntimesVisual,
  local: FunctionsLocalVisual,
  templates: FunctionsTemplatesVisual,
}
