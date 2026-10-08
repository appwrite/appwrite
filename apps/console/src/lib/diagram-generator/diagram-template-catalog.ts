import type { DiagramTemplateId } from '@/lib/diagram-generator/types'

export type DiagramTemplateCatalogItem = {
  id: DiagramTemplateId
  label: string
  description: string
}

export const DIAGRAM_TEMPLATE_CATALOG: DiagramTemplateCatalogItem[] = [
  { id: 'blank', label: 'Blank', description: 'Start from an empty canvas' },
  {
    id: 'appwrite-platform',
    label: 'Appwrite platform',
    description: 'Clients, API, and core project services',
  },
  {
    id: 'appwrite-architecture',
    label: 'Appwrite architecture',
    description: 'Full platform stack from clients to workers',
  },
  {
    id: 'appwrite-auth',
    label: 'Appwrite auth',
    description: 'Sign in, sessions, and users table',
  },
  {
    id: 'appwrite-storage',
    label: 'Appwrite storage',
    description: 'Uploads, functions, and file metadata',
  },
  {
    id: 'appwrite-messaging',
    label: 'Appwrite messaging',
    description: 'Function triggers to email and push',
  },
  {
    id: 'three-tier',
    label: 'Three-tier',
    description: 'Client, API, and database',
  },
  {
    id: 'serverless',
    label: 'Serverless',
    description: 'Functions with data and storage',
  },
  {
    id: 'realtime-flow',
    label: 'Realtime',
    description: 'Subscribe and event flow',
  },
]

export function getDiagramTemplateLabel(templateId: DiagramTemplateId): string {
  return (
    DIAGRAM_TEMPLATE_CATALOG.find((item) => item.id === templateId)?.label ??
    'Diagram'
  )
}
