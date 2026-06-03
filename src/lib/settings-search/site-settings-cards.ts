import type { SettingsCardIndexEntry } from '@/lib/settings-search'

export const SITE_SETTINGS_CARD_INDEX: SettingsCardIndexEntry[] = [
  {
    sectionId: 'general',
    title: 'Details',
    keywords: ['id', 'created', 'updated', 'identifiers'],
  },
  {
    sectionId: 'general',
    title: 'Name',
    keywords: ['rename', 'display', 'site name'],
  },
  {
    sectionId: 'general',
    title: 'Delete site',
    keywords: ['delete', 'remove', 'destroy', 'danger'],
  },
  {
    sectionId: 'git',
    title: 'Repository',
    keywords: ['git', 'github', 'branch', 'connect', 'deployment'],
  },
  {
    sectionId: 'git',
    title: 'Silent mode',
    keywords: ['comments', 'commits', 'pull request'],
  },
  {
    sectionId: 'build',
    title: 'Framework',
    keywords: ['adapter', 'static', 'ssg', 'next', 'react'],
  },
  {
    sectionId: 'build',
    title: 'Commands',
    keywords: ['install', 'build', 'output', 'compile'],
  },
  {
    sectionId: 'build',
    title: 'Triggers',
    keywords: ['git', 'branch', 'path', 'glob', 'filter', 'deploy', 'pattern'],
  },
  {
    sectionId: 'build',
    title: 'Specification',
    keywords: ['vcpu', 'memory', 'worker', 'profile'],
  },
  {
    sectionId: 'runtime',
    title: 'Image',
    keywords: ['runtime', 'ssr', 'server'],
  },
  {
    sectionId: 'runtime',
    title: 'Timeout',
    keywords: ['execute', 'seconds'],
  },
  {
    sectionId: 'runtime',
    title: 'Logging',
    keywords: ['logs', 'stdout'],
  },
  {
    sectionId: 'runtime',
    title: 'Specification',
    keywords: ['vcpu', 'memory', 'cpu'],
  },
]
