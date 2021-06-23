declare module 'timers/promises' {
    import { TimerOptions } from 'timers';

    /**
     * Returns a promise that resolves after the specified delay in milliseconds.
     * @param delay defaults to 1
     */
    function setTimeout<T = void>(delay?: number, value?: T, options?: TimerOptions): Promise<T>;

    /**
     * Returns a promise that resolves in the next tick.
     */
    function setImmediate<T = void>(value?: T, options?: TimerOptions): Promise<T>;

    /**
     *
     * Returns an async iterator that generates values in an interval of delay ms.
     * @param delay defaults to 1
     */
    function setInterval<T = void>(delay?: number, value?: T, options?: TimerOptions): AsyncIterable<T>;
}
