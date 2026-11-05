import {
  coverLucideIconNodeToSvg,
  loadCoverLucideIconNode,
} from '@/lib/cover-generator/lucide-icon-svg'

export async function loadCoverLucideIconSvgBuffer(
  iconName: string,
  stroke: string,
): Promise<Buffer | null> {
  const iconNode = await loadCoverLucideIconNode(iconName)
  if (!iconNode) return null

  return Buffer.from(coverLucideIconNodeToSvg(iconNode, stroke), 'utf-8')
}
