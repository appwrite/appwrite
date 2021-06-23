/**
 * @experimental
 */
declare module 'diagnostic_channel' {
    /**
     * Returns wether a named channel has subscribers or not.
     */
    function hasSubscribers(name: string): boolean;

    /**
     * Gets or create a diagnostic channel by name.
     */
    function channel(name: string): Channel;

    type ChannelListener = (name: string, message: unknown) => void;

    /**
     * Simple diagnostic channel that allows
     */
    class Channel {
        readonly name: string;
        readonly hashSubscribers: boolean;
        private constructor(name: string);

        /**
         * Add a listener to the message channel.
         */
        subscribe(listener: ChannelListener): void;
        /**
         * Removes a previously registered listener.
         */
        unsubscribe(listener: ChannelListener): void;
    }
}
