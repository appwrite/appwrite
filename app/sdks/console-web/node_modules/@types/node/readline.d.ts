declare module 'readline' {
    import { Abortable, EventEmitter } from 'events';

    interface Key {
        sequence?: string;
        name?: string;
        ctrl?: boolean;
        meta?: boolean;
        shift?: boolean;
    }

    class Interface extends EventEmitter {
        readonly terminal: boolean;

        // Need direct access to line/cursor data, for use in external processes
        // see: https://github.com/nodejs/node/issues/30347
        /** The current input data */
        readonly line: string;
        /** The current cursor position in the input line */
        readonly cursor: number;

        /**
         * NOTE: According to the documentation:
         *
         * > Instances of the `readline.Interface` class are constructed using the
         * > `readline.createInterface()` method.
         *
         * @see https://nodejs.org/dist/latest-v10.x/docs/api/readline.html#readline_class_interface
         */
        protected constructor(input: NodeJS.ReadableStream, output?: NodeJS.WritableStream, completer?: Completer | AsyncCompleter, terminal?: boolean);
        /**
         * NOTE: According to the documentation:
         *
         * > Instances of the `readline.Interface` class are constructed using the
         * > `readline.createInterface()` method.
         *
         * @see https://nodejs.org/dist/latest-v10.x/docs/api/readline.html#readline_class_interface
         */
        protected constructor(options: ReadLineOptions);

        getPrompt(): string;
        setPrompt(prompt: string): void;
        prompt(preserveCursor?: boolean): void;
        question(query: string, callback: (answer: string) => void): void;
        question(query: string, options: Abortable, callback: (answer: string) => void): void;
        pause(): this;
        resume(): this;
        close(): void;
        write(data: string | Buffer, key?: Key): void;

        /**
         * Returns the real position of the cursor in relation to the input
         * prompt + string.  Long input (wrapping) strings, as well as multiple
         * line prompts are included in the calculations.
         */
        getCursorPos(): CursorPos;

        /**
         * events.EventEmitter
         * 1. close
         * 2. line
         * 3. pause
         * 4. resume
         * 5. SIGCONT
         * 6. SIGINT
         * 7. SIGTSTP
         * 8. history
         */
        addListener(event: string, listener: (...args: any[]) => void): this;
        addListener(event: "close", listener: () => void): this;
        addListener(event: "line", listener: (input: string) => void): this;
        addListener(event: "pause", listener: () => void): this;
        addListener(event: "resume", listener: () => void): this;
        addListener(event: "SIGCONT", listener: () => void): this;
        addListener(event: "SIGINT", listener: () => void): this;
        addListener(event: "SIGTSTP", listener: () => void): this;
        addListener(event: "history", listener: (history: string[]) => void): this;

        emit(event: string | symbol, ...args: any[]): boolean;
        emit(event: "close"): boolean;
        emit(event: "line", input: string): boolean;
        emit(event: "pause"): boolean;
        emit(event: "resume"): boolean;
        emit(event: "SIGCONT"): boolean;
        emit(event: "SIGINT"): boolean;
        emit(event: "SIGTSTP"): boolean;
        emit(event: "history", history: string[]): boolean;

        on(event: string, listener: (...args: any[]) => void): this;
        on(event: "close", listener: () => void): this;
        on(event: "line", listener: (input: string) => void): this;
        on(event: "pause", listener: () => void): this;
        on(event: "resume", listener: () => void): this;
        on(event: "SIGCONT", listener: () => void): this;
        on(event: "SIGINT", listener: () => void): this;
        on(event: "SIGTSTP", listener: () => void): this;
        on(event: "history", listener: (history: string[]) => void): this;

        once(event: string, listener: (...args: any[]) => void): this;
        once(event: "close", listener: () => void): this;
        once(event: "line", listener: (input: string) => void): this;
        once(event: "pause", listener: () => void): this;
        once(event: "resume", listener: () => void): this;
        once(event: "SIGCONT", listener: () => void): this;
        once(event: "SIGINT", listener: () => void): this;
        once(event: "SIGTSTP", listener: () => void): this;
        once(event: "history", listener: (history: string[]) => void): this;

        prependListener(event: string, listener: (...args: any[]) => void): this;
        prependListener(event: "close", listener: () => void): this;
        prependListener(event: "line", listener: (input: string) => void): this;
        prependListener(event: "pause", listener: () => void): this;
        prependListener(event: "resume", listener: () => void): this;
        prependListener(event: "SIGCONT", listener: () => void): this;
        prependListener(event: "SIGINT", listener: () => void): this;
        prependListener(event: "SIGTSTP", listener: () => void): this;
        prependListener(event: "history", listener: (history: string[]) => void): this;

        prependOnceListener(event: string, listener: (...args: any[]) => void): this;
        prependOnceListener(event: "close", listener: () => void): this;
        prependOnceListener(event: "line", listener: (input: string) => void): this;
        prependOnceListener(event: "pause", listener: () => void): this;
        prependOnceListener(event: "resume", listener: () => void): this;
        prependOnceListener(event: "SIGCONT", listener: () => void): this;
        prependOnceListener(event: "SIGINT", listener: () => void): this;
        prependOnceListener(event: "SIGTSTP", listener: () => void): this;
        prependOnceListener(event: "history", listener: (history: string[]) => void): this;

        [Symbol.asyncIterator](): AsyncIterableIterator<string>;
    }

    type ReadLine = Interface; // type forwarded for backwards compatibility

    type Completer = (line: string) => CompleterResult;
    type AsyncCompleter = (line: string, callback: (err?: null | Error, result?: CompleterResult) => void) => any;

    type CompleterResult = [string[], string];

    interface ReadLineOptions {
        input: NodeJS.ReadableStream;
        output?: NodeJS.WritableStream;
        completer?: Completer | AsyncCompleter;
        terminal?: boolean;
        /**
         *  Initial list of history lines. This option makes sense
         * only if `terminal` is set to `true` by the user or by an internal `output`
         * check, otherwise the history caching mechanism is not initialized at all.
         * @default []
         */
        history?: string[];
        historySize?: number;
        prompt?: string;
        crlfDelay?: number;
        /**
         * If `true`, when a new input line added
         * to the history list duplicates an older one, this removes the older line
         * from the list.
         * @default false
         */
        removeHistoryDuplicates?: boolean;
        escapeCodeTimeout?: number;
        tabSize?: number;
    }

    function createInterface(input: NodeJS.ReadableStream, output?: NodeJS.WritableStream, completer?: Completer | AsyncCompleter, terminal?: boolean): Interface;
    function createInterface(options: ReadLineOptions): Interface;
    function emitKeypressEvents(stream: NodeJS.ReadableStream, readlineInterface?: Interface): void;

    type Direction = -1 | 0 | 1;

    interface CursorPos {
        rows: number;
        cols: number;
    }

    /**
     * Clears the current line of this WriteStream in a direction identified by `dir`.
     */
    function clearLine(stream: NodeJS.WritableStream, dir: Direction, callback?: () => void): boolean;
    /**
     * Clears this `WriteStream` from the current cursor down.
     */
    function clearScreenDown(stream: NodeJS.WritableStream, callback?: () => void): boolean;
    /**
     * Moves this WriteStream's cursor to the specified position.
     */
    function cursorTo(stream: NodeJS.WritableStream, x: number, y?: number, callback?: () => void): boolean;
    /**
     * Moves this WriteStream's cursor relative to its current position.
     */
    function moveCursor(stream: NodeJS.WritableStream, dx: number, dy: number, callback?: () => void): boolean;
}
