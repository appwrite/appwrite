import type { DiffFormatter } from './DiffFormatter';

/**
 * A no-op [`DiffFormatter`](https://serenity-js.org/api/core/interface/DiffFormatter/) that produces output identical to input.
 *
 * @group Errors
 */
export class NoOpDiffFormatter implements DiffFormatter {
    expected(line: string): string {
        return line;
    }

    received(line: string): string {
        return line;
    }

    unchanged(line: string): string {
        return line;
    }
}
