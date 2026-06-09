import { comparisonTables } from './comparison-data'

export function getComparisonTableAnchorId(title: string): string {
  return title.toLowerCase().replace(/\s+/g, '-')
}

export type ComparisonPageSection = {
  id: string
  label: string
}

export const comparisonPageSections: readonly ComparisonPageSection[] =
  comparisonTables.map((table) => ({
    id: getComparisonTableAnchorId(table.title),
    label: table.title,
  }))
