/**
 * A tag function trimming the leading and trailing whitespace from multi-line strings.
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Template_literals#Tagged_template_literals
 *
 * @param templates
 * @param placeholders
 */
export function trimmed(templates: TemplateStringsArray, ...placeholders: Array<any>): string {

    const lines = templates
        .map((template, i) => i < placeholders.length
            ? [ template, placeholders[i] ]
            : [ template ])
        .reduce((acc, tuple) => acc.concat(tuple))
        .join('')
        .split('\n');

    const nonEmptyLines = lines
        .map(line => line.trim())
        .filter(line => !! line);

    return nonEmptyLines
        .map(line => line.replace(/\|\s?(.*)$/, '$1'))
        .join('\n');
}
