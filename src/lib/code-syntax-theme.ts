import type { editor } from 'monaco-editor'
import type { PrismTheme } from 'prism-react-renderer'

/**
 * Snapwrite-inspired syntax palette (https://github.com/appwrite/snapwrite).
 * Shared by CodeBlock (Prism) and Monaco editors.
 */
export type CodeSyntaxColors = {
  moduleKeyword: string
  function: string
  keyword: string
  escape: string
  comment: string
  property: string
  string: string
}

/** Dark chrome - matches snapwrite CodeMirror HighlightStyle. */
export const CODE_SYNTAX_COLORS_DARK: CodeSyntaxColors = {
  moduleKeyword: '#9685FE',
  function: '#67A3FE',
  keyword: '#fe86a8',
  escape: '#67A3FE',
  comment: '#6E6E71',
  property: '#67A3FE',
  string: '#4AD4AB',
}

/** Light chrome - same brand hues, slightly deeper for contrast on white. */
export const CODE_SYNTAX_COLORS_LIGHT: CodeSyntaxColors = {
  moduleKeyword: '#7B6AEE',
  function: '#4A8FE8',
  keyword: '#E85D8A',
  escape: '#4A8FE8',
  comment: '#6E6E71',
  property: '#4A8FE8',
  string: '#2DB88A',
}

export function getCodeSyntaxColors(isDarkChrome: boolean): CodeSyntaxColors {
  return isDarkChrome ? CODE_SYNTAX_COLORS_DARK : CODE_SYNTAX_COLORS_LIGHT
}

function monacoForeground(hex: string): string {
  return hex.replace(/^#/, '')
}

/** Monaco token rules for defineTheme({ rules }). */
export function monacoSyntaxHighlightRules(
  isDarkChrome: boolean,
): editor.ITokenThemeRule[] {
  const colors = getCodeSyntaxColors(isDarkChrome)

  return [
    { token: 'comment', foreground: monacoForeground(colors.comment) },
    { token: 'comment.doc', foreground: monacoForeground(colors.comment) },
    { token: 'comment.doc.tag', foreground: monacoForeground(colors.comment) },
    { token: 'string', foreground: monacoForeground(colors.string) },
    { token: 'string.escape', foreground: monacoForeground(colors.escape) },
    { token: 'string.regexp', foreground: monacoForeground(colors.string) },
    { token: 'keyword', foreground: monacoForeground(colors.keyword) },
    { token: 'keyword.control', foreground: monacoForeground(colors.keyword) },
    { token: 'keyword.operator', foreground: monacoForeground(colors.keyword) },
    { token: 'tag', foreground: monacoForeground(colors.keyword) },
    { token: 'attribute.name', foreground: monacoForeground(colors.property) },
    { token: 'attribute.value', foreground: monacoForeground(colors.string) },
    { token: 'type', foreground: monacoForeground(colors.moduleKeyword) },
    { token: 'type.identifier', foreground: monacoForeground(colors.moduleKeyword) },
    { token: 'namespace', foreground: monacoForeground(colors.moduleKeyword) },
    { token: 'metatag', foreground: monacoForeground(colors.moduleKeyword) },
    { token: 'annotation', foreground: monacoForeground(colors.moduleKeyword) },
    { token: 'function', foreground: monacoForeground(colors.function) },
    { token: 'function.predefined', foreground: monacoForeground(colors.function) },
    { token: 'support.function', foreground: monacoForeground(colors.function) },
    { token: 'method', foreground: monacoForeground(colors.function) },
    { token: 'property', foreground: monacoForeground(colors.property) },
    { token: 'variable.property', foreground: monacoForeground(colors.property) },
    {
      token: 'support.type.property-name',
      foreground: monacoForeground(colors.property),
    },
    { token: 'class', foreground: monacoForeground(colors.moduleKeyword) },
    { token: 'interface', foreground: monacoForeground(colors.moduleKeyword) },
    { token: 'struct', foreground: monacoForeground(colors.moduleKeyword) },
    { token: 'enum', foreground: monacoForeground(colors.moduleKeyword) },
    { token: 'enumMember', foreground: monacoForeground(colors.property) },
    { token: 'variable', foreground: monacoForeground(colors.property) },
    { token: 'variable.predefined', foreground: monacoForeground(colors.moduleKeyword) },
    { token: 'constant', foreground: monacoForeground(colors.moduleKeyword) },
    { token: 'constant.language', foreground: monacoForeground(colors.keyword) },
    { token: 'number', foreground: monacoForeground(colors.string) },
    { token: 'regexp', foreground: monacoForeground(colors.string) },
  ]
}

/** Prism token styles for CodeBlock / prism-react-renderer. */
export function prismSyntaxHighlightStyles(
  colors: CodeSyntaxColors,
  defaultColor?: string,
): PrismTheme['styles'] {
  const neutral = defaultColor ? { color: defaultColor } : undefined

  return [
    {
      types: ['comment', 'prolog', 'doctype', 'cdata'],
      style: { color: colors.comment },
    },
    { types: ['namespace'], style: { color: colors.moduleKeyword } },
    {
      types: ['keyword', 'builtin', 'changed', 'interpolation-punctuation'],
      style: { color: colors.keyword },
    },
    {
      types: ['tag'],
      languages: ['markup'],
      style: { color: colors.keyword },
    },
    { types: ['tag'], style: { color: colors.string } },
    { types: ['function'], style: { color: colors.function } },
    { types: ['class-name'], style: { color: colors.moduleKeyword } },
    {
      types: ['attr-name', 'variable', 'property'],
      style: { color: colors.property },
    },
    {
      types: [
        'deleted',
        'string',
        'attr-value',
        'template-punctuation',
        'char',
        'regexp',
      ],
      style: { color: colors.string },
    },
    { types: ['number', 'inserted', 'constant'], style: { color: colors.string } },
    {
      types: ['boolean'],
      style: { color: colors.keyword },
    },
    { types: ['selector'], style: { color: colors.keyword } },
    { types: ['punctuation', 'operator'], style: neutral ?? {} },
  ]
}
