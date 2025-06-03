/**
 * Configuration options for [`Question.formattedValue`](https://serenity-js.org/api/core/class/Question/#formattedValue) and
 * the [`the`](https://serenity-js.org/api/core/function/the/) function.
 *
 * @group Questions
 */
export interface DescriptionFormattingOptions {
    /**
     * The maximum length of the string representation of the value.
     * String representations longer than this value will be truncated and appended with an ellipsis.
     */
    maxLength: number;
}
