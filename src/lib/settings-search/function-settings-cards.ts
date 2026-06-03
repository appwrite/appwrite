import type { SettingsCardIndexEntry } from '@/lib/settings-search'

export const FUNCTION_SETTINGS_CARD_INDEX: SettingsCardIndexEntry[] = [
  {
    sectionId: 'general',
    title: 'Details',
    keywords: ['id', 'created', 'updated', 'identifiers', 'timestamps'],
  },
  {
    sectionId: 'general',
    title: 'Name',
    keywords: ['rename', 'display', 'function name'],
  },
  {
    sectionId: 'general',
    title: 'Status',
    keywords: ['enabled', 'disabled', 'toggle'],
  },
  {
    sectionId: 'general',
    title: 'Delete function',
    keywords: ['delete', 'remove', 'destroy', 'danger'],
  },
  {
    sectionId: 'git',
    title: 'Repository',
    keywords: [
      'git',
      'github',
      'branch',
      'connect',
      'disconnect',
      'root directory',
      'production',
    ],
  },
  {
    sectionId: 'git',
    title: 'Silent mode',
    keywords: ['comments', 'commits', 'pull request', 'deployment'],
  },
  {
    sectionId: 'build',
    title: 'Commands',
    keywords: ['install', 'build', 'package', 'script'],
  },
  {
    sectionId: 'build',
    title: 'Triggers',
    keywords: ['git', 'branch', 'path', 'glob', 'filter', 'deploy', 'pattern'],
  },
  {
    sectionId: 'build',
    title: 'Specification',
    keywords: ['vcpu', 'memory', 'worker', 'profile', 'cpu'],
  },
  {
    sectionId: 'runtime',
    title: 'Image',
    keywords: ['runtime', 'docker', 'container'],
  },
  {
    sectionId: 'runtime',
    title: 'Timeout',
    keywords: ['execute', 'seconds', 'limit'],
  },
  {
    sectionId: 'runtime',
    title: 'Logging',
    keywords: ['logs', 'stdout', 'stderr'],
  },
  {
    sectionId: 'runtime',
    title: 'Specification',
    keywords: ['vcpu', 'memory', 'cpu', 'resources'],
  },
  {
    sectionId: 'executions',
    title: 'Schedule',
    keywords: ['cron', 'scheduled', 'recurring'],
  },
  {
    sectionId: 'executions',
    title: 'Events',
    keywords: ['webhook', 'trigger', 'invoke', 'async'],
  },
]
