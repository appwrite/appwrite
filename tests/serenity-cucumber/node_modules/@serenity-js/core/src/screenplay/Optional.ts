import type { Answerable } from './Answerable';

/**
 * `Optional` is a container object, which holds a value that may or may not be "present",
 *
 * The meaning of being "present" depends on the context and typically means a value that:
 * - is other than `null` or `undefined`
 * - is retrievable, so retrieving it doesn't throw any errors
 *
 * Additionally, `Optional` can also have a context-specific meaning. For example, `Optional#isPresent()`:
 * - in the context of a `PageElement` means that the element exists in the DOM.
 * - in the context of a `ModalWindow` means that the modal window is open.
 * - in the case of a REST API response, `LastResponse.body().books[0].author.name.isPresent()`
 *   will inform us if a given entry exists (so `books[0].author.name`),
 *   and if all the links of the property chain leading to the entry of interest exist too
 *   (so `books` is present, and so is `books[0]`, `books[0].author`, `books[0].author.name`).
 *
 * @group Questions
 */
export interface Optional {
    /**
     * Returns an [`Answerable`](https://serenity-js.org/api/core/#Answerable) that resolves to `true` when the optional value
     * is present, `false` otherwise.
     */
    isPresent(): Answerable<boolean>;
}
