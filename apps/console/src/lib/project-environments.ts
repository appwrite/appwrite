/**
 * A row in the environment switcher: either the project itself (the default
 * environment, which has no environment document of its own) or one of the
 * environments listed under it. Every row is a project the Console can open.
 */
export interface ProjectEnvironmentOption {
  id: string
  name: string
  /** The project itself. It cannot be renamed or deleted from the switcher. */
  isDefault: boolean
}

/**
 * Dot colors for the switcher. The project itself always takes the first one;
 * every other environment keeps the same color between renders because the
 * color is derived from its id (an environment has no type to key on).
 */
const PROJECT_ENVIRONMENT_DOT_CLASSES = [
  'bg-emerald-500',
  'bg-amber-500',
  'bg-sky-500',
  'bg-violet-500',
  'bg-rose-500',
  'bg-cyan-500',
] as const

function hashEnvironmentId(environmentId: string): number {
  let hash = 0
  for (let index = 0; index < environmentId.length; index += 1) {
    hash = (hash << 5) - hash + environmentId.charCodeAt(index)
    hash |= 0
  }
  return Math.abs(hash)
}

export function projectEnvironmentDotClass(
  environment: ProjectEnvironmentOption,
): string {
  if (environment.isDefault) return PROJECT_ENVIRONMENT_DOT_CLASSES[0]
  const palette = PROJECT_ENVIRONMENT_DOT_CLASSES.slice(1)
  return palette[hashEnvironmentId(environment.id) % palette.length]!
}
