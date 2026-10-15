import { describe, expect, test } from 'bun:test'
import { createNativeDialogLinter } from '../../scripts/lint-native-dialogs'

describe('native dialog lint', () => {
  const eslint = createNativeDialogLinter()

  test.each([
    'alert("Saved")',
    'window.confirm("Delete?")',
    'globalThis.prompt("Name")',
    'self.alert("Saved")',
    'window["confirm"]("Delete?")',
    'const { prompt } = window; prompt("Name")',
  ])('rejects %s in shared UI components', async (source) => {
    const [result] = await eslint.lintText(source, {
      filePath: 'src/components/ui/native-dialog-probe.tsx',
    })

    expect(result.errorCount).toBeGreaterThan(0)
  })

  test('allows scoped names and does not enable unrelated lint rules', async () => {
    const [result] = await eslint.lintText(
      `const { confirm } = useConfirmDialog()
       const prompt = (value: string) => value
       const alert = () => {}
       // eslint-disable-next-line react-hooks/exhaustive-deps
       const unused = 1
       confirm()
       prompt('Name')
       alert()`,
      { filePath: 'src/components/ui/native-dialog-probe.tsx' },
    )

    expect(result.messages).toEqual([])
  })

  test('does not allow inline directives to disable the check', async () => {
    const [result] = await eslint.lintText(
      '// eslint-disable-next-line no-restricted-globals\nconfirm("Delete?")',
      { filePath: 'src/components/ui/native-dialog-probe.tsx' },
    )

    expect(result.errorCount).toBeGreaterThan(0)
  })
})
