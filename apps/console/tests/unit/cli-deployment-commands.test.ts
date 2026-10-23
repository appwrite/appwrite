import { describe, expect, test } from 'bun:test'
import { buildCommands } from '@/components/pages/projects/$projectId/shared/CreateCliDeploymentModal'

const site = (config: Record<string, string>) =>
  buildCommands('site', 'project1', 'site1', { framework: 'react', ...config })

describe('CLI deployment commands', () => {
  test('quote build settings so the shell does not expand them', () => {
    const { unix, powershell } = site({
      buildCommand: 'npm run build && echo "$HOME" `id` $(id)',
      installCommand: "npm ci --prefix 'app'",
      outputDirectory: 'dist',
    })
    expect(unix).toContain(
      `--build-command='npm run build && echo "$HOME" \`id\` $(id)'`,
    )
    expect(unix).toContain(`--install-command='npm ci --prefix '\\''app'\\'''`)
    expect(unix).toContain(`--output-directory='dist'`)
    expect(powershell).toContain(
      `--build-command='npm run build && echo "$HOME" \`id\` $(id)'`,
    )
    expect(powershell).toContain(`--install-command='npm ci --prefix ''app'''`)
  })

  test('double typographic quotes for PowerShell', () => {
    const { powershell } = site({ buildCommand: 'echo ’; calc' })
    expect(powershell).toContain(`--build-command='echo ’’; calc'`)
  })

  test('leave out values cmd.exe would expand or split', () => {
    const { cmd } = site({
      buildCommand: 'echo %USERPROFILE%',
      installCommand: 'npm ci" & calc & "',
      outputDirectory: 'dist',
    })
    expect(cmd).not.toContain('--build-command')
    expect(cmd).not.toContain('--install-command')
    expect(cmd).toContain('--output-directory="dist"')
  })
})
