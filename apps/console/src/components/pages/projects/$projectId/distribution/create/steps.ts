export type CreateAppStep = 'name' | 'framework' | 'platforms' | 'repository'

export const CREATE_APP_STEPS: { id: CreateAppStep; label: string }[] = [
  { id: 'name', label: 'Name' },
  { id: 'framework', label: 'Framework' },
  { id: 'platforms', label: 'Platforms' },
  { id: 'repository', label: 'Repository' },
]

export type AppSource = 'repository' | 'upload'
