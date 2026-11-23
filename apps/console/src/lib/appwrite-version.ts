function isNumeric(value: string): boolean {
  return value.length > 0 && [...value].every((ch) => ch >= '0' && ch <= '9')
}

export function getReleaseLine(version: string): string {
  const [major = '', rest = ''] = version.trim().split('.')
  const minor = rest.split('-')[0]
  if (!isNumeric(major) || !isNumeric(minor)) return ''
  return `${major}.${minor}`
}
