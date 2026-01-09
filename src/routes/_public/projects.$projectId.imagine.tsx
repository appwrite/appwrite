import { createFileRoute } from '@tanstack/react-router'
import { ImagineView } from '@/components/pages/projects/$projectId/imagine/View'

export const Route = createFileRoute('/_public/projects/$projectId/imagine')({
  component: ImaginePage,
})

function ImaginePage() {
  return <ImagineView />
}
