import type { Tab } from '../../shared/ServiceHeader'

export function getVideosServiceTabs(projectId: string): Tab[] {
  return [
    {
      id: 'videos',
      label: 'Videos',
      to: '/projects/$projectId/videos/',
      params: { projectId },
    },
    {
      id: 'profiles',
      label: 'Encoding profiles',
      to: '/projects/$projectId/videos/profiles',
      params: { projectId },
    },
  ]
}
