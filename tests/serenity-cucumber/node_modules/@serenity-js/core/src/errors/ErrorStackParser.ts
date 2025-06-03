import * as parser from 'error-stack-parser';

/**
 * A thin wrapper around error-stack-parser module
 *
 * ## Learn more
 * - [Error stack parser](https://www.npmjs.com/package/error-stack-parser)
 *
 * @group Errors
 */
export class ErrorStackParser {
    parse(error: Error): parser.StackFrame[] {
        return parser.parse(error);
    }
}
