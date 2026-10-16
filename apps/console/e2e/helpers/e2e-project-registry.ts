import fs from 'node:fs'
import path from 'node:path'

export type RegisteredE2eProject = {
  projectId: string
  projectName: string
  createdAt: string
}

const registryDir = path.join('e2e', '.auth', 'created-projects')

function projectPath(projectId: string): string {
  const safeId = projectId.replace(/[^a-zA-Z0-9._-]/g, '_')
  return path.join(registryDir, `${safeId}.json`)
}

/**
 * Persist a created e2e project so teardown can delete it even if the worker
 * is killed after the API create succeeds (navigation failure, fixture timeout).
 * One file per id so parallel Playwright workers do not clobber each other.
 */
export function registerCreatedE2eProject(project: {
  projectId: string
  projectName: string
}): void {
  if (!project.projectId) return
  fs.mkdirSync(registryDir, { recursive: true })
  const record: RegisteredE2eProject = {
    projectId: project.projectId,
    projectName: project.projectName,
    createdAt: new Date().toISOString(),
  }
  fs.writeFileSync(
    projectPath(project.projectId),
    `${JSON.stringify(record)}\n`,
  )
}

export function unregisterCreatedE2eProject(projectId: string): void {
  if (!projectId) return
  try {
    fs.unlinkSync(projectPath(projectId))
  } catch (error) {
    if ((error as NodeJS.ErrnoException).code !== 'ENOENT') {
      throw error
    }
  }
}

export function listRegisteredE2eProjects(): RegisteredE2eProject[] {
  let entries: string[]
  try {
    entries = fs.readdirSync(registryDir)
  } catch (error) {
    if ((error as NodeJS.ErrnoException).code === 'ENOENT') return []
    throw error
  }

  return entries.flatMap((entry) => {
    if (!entry.endsWith('.json')) return []
    try {
      const raw = fs.readFileSync(path.join(registryDir, entry), 'utf-8')
      const parsed = JSON.parse(raw) as Partial<RegisteredE2eProject>
      if (typeof parsed.projectId !== 'string' || !parsed.projectId) return []
      return [
        {
          projectId: parsed.projectId,
          projectName:
            typeof parsed.projectName === 'string' ? parsed.projectName : '',
          createdAt:
            typeof parsed.createdAt === 'string'
              ? parsed.createdAt
              : new Date(0).toISOString(),
        },
      ]
    } catch {
      return []
    }
  })
}
