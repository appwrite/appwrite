import { describe, expect, test } from 'bun:test'
import { join } from 'path'

const DOCKERFILE = join(import.meta.dirname, '../../Dockerfile')

async function readInstructions(): Promise<string[]> {
  const text = await Bun.file(DOCKERFILE).text()
  return text
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line && !line.startsWith('#'))
}

describe('console image', () => {
  test('pins every base image by digest', async () => {
    const stages = new Set<string>()
    const images: string[] = []
    for (const line of await readInstructions()) {
      if (!line.startsWith('FROM ')) continue
      const words = line.split(/\s+/).filter((word) => !word.startsWith('--'))
      const image = words[1] ?? ''
      if (!stages.has(image)) images.push(image)
      if (words[2]?.toUpperCase() === 'AS' && words[3]) stages.add(words[3])
    }
    expect(images.length).toBeGreaterThan(0)
    for (const image of images) {
      expect(image).toContain('@sha256:')
    }
  })

  test('runs the server as a non-root user', async () => {
    const instructions = await readInstructions()
    const stages = instructions.map((line) => line.startsWith('FROM '))
    const final = instructions.slice(stages.lastIndexOf(true))
    const users = final.filter((line) => line.startsWith('USER '))
    const user = users[users.length - 1]
    expect(user).toBeDefined()
    expect(user).not.toMatch(/^USER\s+(root|0)(:|$)/)
    expect(final.indexOf(user!)).toBeLessThan(
      final.findIndex((line) => line.startsWith('CMD ')),
    )
  })
})
