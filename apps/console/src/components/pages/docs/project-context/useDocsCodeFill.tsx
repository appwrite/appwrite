'use client'

import {
  fillDocsCodePlaceholders,
  hasDocsCodePlaceholders,
} from '@/lib/docs/code-placeholders'
import { DocsProjectPicker } from './DocsProjectPicker'
import { useDocsProject } from './DocsProjectContext'

/**
 * Code sample with the reader's project filled in, plus the picker to show in
 * the code block header. Samples without placeholders get neither.
 */
export function useDocsCodeFill(code: string) {
  const { available, project } = useDocsProject()
  const hasPlaceholders = available && hasDocsCodePlaceholders(code)

  return {
    code:
      hasPlaceholders && project
        ? fillDocsCodePlaceholders(code, project)
        : code,
    actions: hasPlaceholders ? <DocsProjectPicker variant="chip" /> : undefined,
  }
}
