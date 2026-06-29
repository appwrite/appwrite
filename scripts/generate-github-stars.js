import { mkdir, readFile, writeFile } from 'node:fs/promises'

const outFile = 'src/lib/generated/github-stars.json'

async function run() {
  try {
    const res = await fetch('https://api.github.com/repos/appwrite/appwrite', {
      headers: { Accept: 'application/vnd.github+json' },
    })
    if (!res.ok) {
      throw new Error(`GitHub API request failed: ${res.status} ${res.statusText}`)
    }
    const data = await res.json()
    const stars = typeof data.stargazers_count === 'number' ? data.stargazers_count : 0

    await mkdir('src/lib/generated', { recursive: true })
    await writeFile(
      outFile,
      JSON.stringify({ stars, fetchedAt: new Date().toISOString() }, null, 2),
    )
  } catch (err) {
    try {
      await readFile(outFile, 'utf8')
      console.warn(`Using existing ${outFile}. Reason:`, err.message)
    } catch {
      throw err
    }
  }
}

await run()
