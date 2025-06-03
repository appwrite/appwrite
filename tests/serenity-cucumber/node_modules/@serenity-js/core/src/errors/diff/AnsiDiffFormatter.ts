import type { Chalk, Options } from 'chalk';    // eslint-disable-line unicorn/import-style
import { Instance as ChalkInstance } from 'chalk';   // eslint-disable-line unicorn/import-style

import type { DiffFormatter } from './DiffFormatter';

/**
 * A [`DiffFormatter`](https://serenity-js.org/api/core/interface/DiffFormatter/) that uses [ANSI escape codes](https://en.wikipedia.org/wiki/ANSI_escape_code)
 * to format the output.
 *
 * @group Errors
 */
export class AnsiDiffFormatter implements DiffFormatter {
    private readonly chalk: Chalk;

    /**
     * Instantiates an `AnsiDiffFormatter`, configured with colour support options for [Chalk](https://github.com/chalk/chalk).
     * When no `chalkOptions` object is provided, Chalk will auto-detect colour support automatically based on the execution environment.
     *
     * Available colour support levels:
     * - `0` - All colours disabled.
     * - `1` - Basic 16 colours support.
     * - `2` - ANSI 256 colours support.
     * - `3` - Truecolor - 16 million colours support.
     *
     * @param chalkOptions
     */
    constructor(chalkOptions?: Options) {
        this.chalk = new ChalkInstance(chalkOptions);
    }

    expected(line: string): string {
        return this.chalk.green(line);
    }

    received(line: string): string {
        return this.chalk.red(line);
    }

    unchanged(line: string): string {
        return line;
    }
}
