import { ensure, isNotBlank, isString } from 'tiny-types';

/**
 * Represents a Cucumber.js formatter
 *
 * ## Learn more
 *
 * - [Cucumber formatters](https://github.com/cucumber/cucumber-js/blob/master/docs/cli.md#built-in-formatters)
 *
 * @group Integration
 */
export class CucumberFormat {
    public readonly formatter: string;
    public readonly output: string;

    /**
     * @param value
     *  Cucumber format expression, like `pretty` or `json:out.json`
     */
    constructor(public readonly value: string) {
        [ this.formatter, this.output ] = CucumberFormat.split(
            ensure('format', value, isString(), isNotBlank())
        );
    }

    /**
     * See https://github.com/cucumber/cucumber-js/blob/master/src/cli/option_splitter.ts
     *
     * @param format
     */
    private static split(format: string): [string, string] {
        const parts = format.split(/([^A-Z]):(?!\\)/);

        const result = parts.reduce((memo: string[], part: string, i: number) => {
            if (partNeedsRecombined(i)) {
                memo.push(parts.slice(i, i + 2).join(''));
            }

            return memo;
        }, []);

        if (result.length === 1) {
            result.push('');
        }

        return result as [string, string];
    }
}

/**
 * @private
 */
function partNeedsRecombined(i: number): boolean {
    return i % 2 === 0;
}
