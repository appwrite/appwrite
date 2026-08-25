export type InitTicketStackId =
  | 'appwrite'
  | 'react'
  | 'next'
  | 'vue'
  | 'nuxt'
  | 'sveltekit'
  | 'angular'
  | 'solid'
  | 'tanstack'
  | 'astro'
  | 'flutter'
  | 'react-native'
  | 'node'
  | 'python'
  | 'go'
  | 'php'
  | 'ruby'
  | 'dotnet'
  | 'dart'
  | 'deno'
  | 'swift'
  | 'kotlin'
  | 'android'
  | 'apple'

export type InitTicketStackOption = {
  id: InitTicketStackId
  label: string
  iconKey: string
}

/** Technologies Appwrite supports - used for ticket stack picker. */
export const INIT_TICKET_STACK_OPTIONS: InitTicketStackOption[] = [
  { id: 'appwrite', label: 'Appwrite', iconKey: 'appwrite' },
  { id: 'react', label: 'React', iconKey: 'react' },
  { id: 'tanstack', label: 'TanStack Start', iconKey: 'tanstack' },
  { id: 'next', label: 'Next.js', iconKey: 'next' },
  { id: 'vue', label: 'Vue.js', iconKey: 'vue' },
  { id: 'nuxt', label: 'Nuxt', iconKey: 'nuxt' },
  { id: 'sveltekit', label: 'SvelteKit', iconKey: 'sveltekit' },
  { id: 'angular', label: 'Angular', iconKey: 'angular' },
  { id: 'solid', label: 'Solid', iconKey: 'solid' },
  { id: 'astro', label: 'Astro', iconKey: 'astro' },
  { id: 'flutter', label: 'Flutter', iconKey: 'flutter' },
  { id: 'react-native', label: 'React Native', iconKey: 'react-native' },
  { id: 'node', label: 'Node.js', iconKey: 'node' },
  { id: 'python', label: 'Python', iconKey: 'python' },
  { id: 'go', label: 'Go', iconKey: 'go' },
  { id: 'php', label: 'PHP', iconKey: 'php' },
  { id: 'ruby', label: 'Ruby', iconKey: 'ruby' },
  { id: 'dotnet', label: '.NET', iconKey: 'dotnet' },
  { id: 'dart', label: 'Dart', iconKey: 'dart' },
  { id: 'deno', label: 'Deno', iconKey: 'deno' },
  { id: 'swift', label: 'Swift', iconKey: 'swift' },
  { id: 'kotlin', label: 'Kotlin', iconKey: 'kotlin' },
  { id: 'android', label: 'Android', iconKey: 'android' },
  { id: 'apple', label: 'Apple', iconKey: 'apple' },
]

export const INIT_TICKET_STACK_IDS = new Set(
  INIT_TICKET_STACK_OPTIONS.map((option) => option.id),
)

export const INIT_TICKET_MAX_STACK = 6

export const INIT_TICKET_DEFAULT_STACK: InitTicketStackId[] = ['appwrite']

export function getInitTicketStackOption(
  id: string,
): InitTicketStackOption | undefined {
  return INIT_TICKET_STACK_OPTIONS.find((option) => option.id === id)
}

export function parseInitTicketStack(value: unknown): InitTicketStackId[] {
  if (!Array.isArray(value)) return []
  return value.filter(
    (item): item is InitTicketStackId =>
      typeof item === 'string' && INIT_TICKET_STACK_IDS.has(item as InitTicketStackId),
  )
}
