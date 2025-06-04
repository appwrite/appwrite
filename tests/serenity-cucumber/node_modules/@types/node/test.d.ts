/**
 * The `node:test` module provides a standalone testing module.
 * @see [source](https://github.com/nodejs/node/blob/v18.x/lib/test.js)
 */
declare module "node:test" {
    /**
     * Programmatically start the test runner.
     * @since v18.9.0
     * @param options Configuration options for running tests.
     * @returns A {@link TestsStream} that emits events about the test execution.
     */
    function run(options?: RunOptions): TestsStream;
    /**
     * The `test()` function is the value imported from the test module. Each invocation of this
     * function results in reporting the test to the {@link TestsStream}.
     *
     * The {@link TestContext} object passed to the fn argument can be used to perform actions
     * related to the current test. Examples include skipping the test, adding additional
     * diagnostic information, or creating subtests.
     *
     * `test()` returns a {@link Promise} that resolves once the test completes. The return value
     * can usually be discarded for top level tests. However, the return value from subtests should
     * be used to prevent the parent test from finishing first and cancelling the subtest as shown
     * in the following example.
     *
     * ```js
     * test('top level test', async (t) => {
     *   // The setTimeout() in the following subtest would cause it to outlive its
     *   // parent test if 'await' is removed on the next line. Once the parent test
     *   // completes, it will cancel any outstanding subtests.
     *   await t.test('longer running subtest', async (t) => {
     *     return new Promise((resolve, reject) => {
     *       setTimeout(resolve, 1000);
     *     });
     *   });
     * });
     * ```
     * @since v18.0.0
     * @param name The name of the test, which is displayed when reporting test results.
     *    Default: The `name` property of fn, or `'<anonymous>'` if `fn` does not have a name.
     * @param options Configuration options for the test
     * @param fn The function under test. The first argument to this function is a
     *    {@link TestContext} object. If the test uses callbacks, the callback function is
     *    passed as the second argument. Default: A no-op function.
     * @returns A {@link Promise} resolved with `undefined` once the test completes.
     */
    function test(name?: string, fn?: TestFn): Promise<void>;
    function test(name?: string, options?: TestOptions, fn?: TestFn): Promise<void>;
    function test(options?: TestOptions, fn?: TestFn): Promise<void>;
    function test(fn?: TestFn): Promise<void>;
    namespace test {
        export { after, afterEach, before, beforeEach, describe, it, mock, only, run, skip, test, todo };
    }
    /**
     * @since v18.6.0
     * @param name The name of the suite, which is displayed when reporting suite results.
     *    Default: The `name` property of fn, or `'<anonymous>'` if `fn` does not have a name.
     * @param options Configuration options for the suite
     * @param fn The function under suite. Default: A no-op function.
     */
    function describe(name?: string, options?: TestOptions, fn?: SuiteFn): Promise<void>;
    function describe(name?: string, fn?: SuiteFn): Promise<void>;
    function describe(options?: TestOptions, fn?: SuiteFn): Promise<void>;
    function describe(fn?: SuiteFn): Promise<void>;
    namespace describe {
        /**
         * Shorthand for skipping a suite, same as `describe([name], { skip: true }[, fn])`.
         */
        function skip(name?: string, options?: TestOptions, fn?: SuiteFn): Promise<void>;
        function skip(name?: string, fn?: SuiteFn): Promise<void>;
        function skip(options?: TestOptions, fn?: SuiteFn): Promise<void>;
        function skip(fn?: SuiteFn): Promise<void>;
        /**
         * Shorthand for marking a suite as `TODO`, same as `describe([name], { todo: true }[, fn])`.
         */
        function todo(name?: string, options?: TestOptions, fn?: SuiteFn): Promise<void>;
        function todo(name?: string, fn?: SuiteFn): Promise<void>;
        function todo(options?: TestOptions, fn?: SuiteFn): Promise<void>;
        function todo(fn?: SuiteFn): Promise<void>;
        /**
         * Shorthand for marking a suite as `only`, same as `describe([name], { only: true }[, fn])`.
         * @since v18.15.0
         */
        function only(name?: string, options?: TestOptions, fn?: SuiteFn): Promise<void>;
        function only(name?: string, fn?: SuiteFn): Promise<void>;
        function only(options?: TestOptions, fn?: SuiteFn): Promise<void>;
        function only(fn?: SuiteFn): Promise<void>;
    }

    /**
     * @since v18.6.0
     * @param name The name of the test, which is displayed when reporting test results.
     *    Default: The `name` property of fn, or `'<anonymous>'` if `fn` does not have a name.
     * @param options Configuration options for the test
     * @param fn The function under test. If the test uses callbacks, the callback function is
     *    passed as the second argument. Default: A no-op function.
     */
    function it(name?: string, options?: TestOptions, fn?: TestFn): Promise<void>;
    function it(name?: string, fn?: TestFn): Promise<void>;
    function it(options?: TestOptions, fn?: TestFn): Promise<void>;
    function it(fn?: TestFn): Promise<void>;
    namespace it {
        /**
         * Shorthand for skipping a test, same as `it([name], { skip: true }[, fn])`.
         */
        function skip(name?: string, options?: TestOptions, fn?: TestFn): Promise<void>;
        function skip(name?: string, fn?: TestFn): Promise<void>;
        function skip(options?: TestOptions, fn?: TestFn): Promise<void>;
        function skip(fn?: TestFn): Promise<void>;
        /**
         * Shorthand for marking a test as `TODO`, same as `it([name], { todo: true }[, fn])`.
         */
        function todo(name?: string, options?: TestOptions, fn?: TestFn): Promise<void>;
        function todo(name?: string, fn?: TestFn): Promise<void>;
        function todo(options?: TestOptions, fn?: TestFn): Promise<void>;
        function todo(fn?: TestFn): Promise<void>;
        /**
         * Shorthand for marking a test as `only`, same as `it([name], { only: true }[, fn])`.
         * @since v18.15.0
         */
        function only(name?: string, options?: TestOptions, fn?: TestFn): Promise<void>;
        function only(name?: string, fn?: TestFn): Promise<void>;
        function only(options?: TestOptions, fn?: TestFn): Promise<void>;
        function only(fn?: TestFn): Promise<void>;
    }
    /**
     * Shorthand for skipping a test, same as `test([name], { skip: true }[, fn])`.
     * @since v18.17.0
     */
    function skip(name?: string, options?: TestOptions, fn?: TestFn): Promise<void>;
    function skip(name?: string, fn?: TestFn): Promise<void>;
    function skip(options?: TestOptions, fn?: TestFn): Promise<void>;
    function skip(fn?: TestFn): Promise<void>;
    /**
     * Shorthand for marking a test as `TODO`, same as `test([name], { todo: true }[, fn])`.
     * @since v18.17.0
     */
    function todo(name?: string, options?: TestOptions, fn?: TestFn): Promise<void>;
    function todo(name?: string, fn?: TestFn): Promise<void>;
    function todo(options?: TestOptions, fn?: TestFn): Promise<void>;
    function todo(fn?: TestFn): Promise<void>;
    /**
     * Shorthand for marking a test as `only`, same as `test([name], { only: true }[, fn])`.
     * @since v18.17.0
     */
    function only(name?: string, options?: TestOptions, fn?: TestFn): Promise<void>;
    function only(name?: string, fn?: TestFn): Promise<void>;
    function only(options?: TestOptions, fn?: TestFn): Promise<void>;
    function only(fn?: TestFn): Promise<void>;
    /**
     * The type of a function under test. The first argument to this function is a
     * {@link TestContext} object. If the test uses callbacks, the callback function is passed as
     * the second argument.
     */
    type TestFn = (t: TestContext, done: (result?: any) => void) => any;
    /**
     * The type of a function under Suite.
     */
    type SuiteFn = (s: SuiteContext) => void | Promise<void>;
    interface TestShard {
        /**
         * A positive integer between 1 and `<total>` that specifies the index of the shard to run.
         */
        index: number;
        /**
         * A positive integer that specifies the total number of shards to split the test files to.
         */
        total: number;
    }
    interface RunOptions {
        /**
         * If a number is provided, then that many files would run in parallel.
         * If truthy, it would run (number of cpu cores - 1) files in parallel.
         * If falsy, it would only run one file at a time.
         * If unspecified, subtests inherit this value from their parent.
         * @default true
         */
        concurrency?: number | boolean | undefined;
        /**
         * An array containing the list of files to run.
         * If unspecified, the test runner execution model will be used.
         */
        files?: readonly string[] | undefined;
        /**
         * Allows aborting an in-progress test execution.
         * @default undefined
         */
        signal?: AbortSignal | undefined;
        /**
         * A number of milliseconds the test will fail after.
         * If unspecified, subtests inherit this value from their parent.
         * @default Infinity
         */
        timeout?: number | undefined;
        /**
         * Sets inspector port of test child process.
         * If a nullish value is provided, each process gets its own port,
         * incremented from the primary's `process.debugPort`.
         */
        inspectPort?: number | (() => number) | undefined;
        /**
         * That can be used to only run tests whose name matches the provided pattern.
         * Test name patterns are interpreted as JavaScript regular expressions.
         * For each test that is executed, any corresponding test hooks, such as `beforeEach()`, are also run.
         */
        testNamePatterns?: string | RegExp | ReadonlyArray<string | RegExp> | undefined;
        /**
         * If truthy, the test context will only run tests that have the `only` option set
         * @since v18.19.0
         */
        only?: boolean | undefined;
        /**
         * A function that accepts the TestsStream instance and can be used to setup listeners before any tests are run.
         */
        setup?: ((reporter: TestsStream) => void | Promise<void>) | undefined;
        /**
         * Whether to run in watch mode or not.
         * @default false
         */
        watch?: boolean | undefined;
        /**
         * Running tests in a specific shard.
         * @since v18.19.0
         * @default undefined
         */
        shard?: TestShard | undefined;
    }

    /**
     * A successful call of the `run()` method will return a new `TestsStream` object,
     * streaming a series of events representing the execution of the tests.
     * `TestsStream` will emit events in the order of the tests' definitions.
     * @since v18.9.0
     */
    interface TestsStream extends NodeJS.ReadableStream {
        addListener(event: "test:coverage", listener: (data: TestCoverage) => void): this;
        addListener(event: "test:dequeue", listener: (data: TestDequeue) => void): this;
        addListener(event: "test:diagnostic", listener: (data: DiagnosticData) => void): this;
        addListener(event: "test:enqueue", listener: (data: TestEnqueue) => void): this;
        addListener(event: "test:fail", listener: (data: TestFail) => void): this;
        addListener(event: "test:pass", listener: (data: TestPass) => void): this;
        addListener(event: "test:plan", listener: (data: TestPlan) => void): this;
        addListener(event: "test:start", listener: (data: TestStart) => void): this;
        addListener(event: "test:stderr", listener: (data: TestStderr) => void): this;
        addListener(event: "test:stdout", listener: (data: TestStdout) => void): this;
        addListener(event: "test:watch:drained", listener: () => void): this;
        addListener(event: string, listener: (...args: any[]) => void): this;
        emit(event: "test:coverage", data: TestCoverage): boolean;
        emit(event: "test:dequeue", data: TestDequeue): boolean;
        emit(event: "test:diagnostic", data: DiagnosticData): boolean;
        emit(event: "test:enqueue", data: TestEnqueue): boolean;
        emit(event: "test:fail", data: TestFail): boolean;
        emit(event: "test:pass", data: TestPass): boolean;
        emit(event: "test:plan", data: TestPlan): boolean;
        emit(event: "test:start", data: TestStart): boolean;
        emit(event: "test:stderr", data: TestStderr): boolean;
        emit(event: "test:stdout", data: TestStdout): boolean;
        emit(event: "test:watch:drained"): boolean;
        emit(event: string | symbol, ...args: any[]): boolean;
        on(event: "test:coverage", listener: (data: TestCoverage) => void): this;
        on(event: "test:dequeue", listener: (data: TestDequeue) => void): this;
        on(event: "test:diagnostic", listener: (data: DiagnosticData) => void): this;
        on(event: "test:enqueue", listener: (data: TestEnqueue) => void): this;
        on(event: "test:fail", listener: (data: TestFail) => void): this;
        on(event: "test:pass", listener: (data: TestPass) => void): this;
        on(event: "test:plan", listener: (data: TestPlan) => void): this;
        on(event: "test:start", listener: (data: TestStart) => void): this;
        on(event: "test:stderr", listener: (data: TestStderr) => void): this;
        on(event: "test:stdout", listener: (data: TestStdout) => void): this;
        on(event: "test:watch:drained", listener: () => void): this;
        on(event: string, listener: (...args: any[]) => void): this;
        once(event: "test:coverage", listener: (data: TestCoverage) => void): this;
        once(event: "test:dequeue", listener: (data: TestDequeue) => void): this;
        once(event: "test:diagnostic", listener: (data: DiagnosticData) => void): this;
        once(event: "test:enqueue", listener: (data: TestEnqueue) => void): this;
        once(event: "test:fail", listener: (data: TestFail) => void): this;
        once(event: "test:pass", listener: (data: TestPass) => void): this;
        once(event: "test:plan", listener: (data: TestPlan) => void): this;
        once(event: "test:start", listener: (data: TestStart) => void): this;
        once(event: "test:stderr", listener: (data: TestStderr) => void): this;
        once(event: "test:stdout", listener: (data: TestStdout) => void): this;
        once(event: "test:watch:drained", listener: () => void): this;
        once(event: string, listener: (...args: any[]) => void): this;
        prependListener(event: "test:coverage", listener: (data: TestCoverage) => void): this;
        prependListener(event: "test:dequeue", listener: (data: TestDequeue) => void): this;
        prependListener(event: "test:diagnostic", listener: (data: DiagnosticData) => void): this;
        prependListener(event: "test:enqueue", listener: (data: TestEnqueue) => void): this;
        prependListener(event: "test:fail", listener: (data: TestFail) => void): this;
        prependListener(event: "test:pass", listener: (data: TestPass) => void): this;
        prependListener(event: "test:plan", listener: (data: TestPlan) => void): this;
        prependListener(event: "test:start", listener: (data: TestStart) => void): this;
        prependListener(event: "test:stderr", listener: (data: TestStderr) => void): this;
        prependListener(event: "test:stdout", listener: (data: TestStdout) => void): this;
        prependListener(event: "test:watch:drained", listener: () => void): this;
        prependListener(event: string, listener: (...args: any[]) => void): this;
        prependOnceListener(event: "test:coverage", listener: (data: TestCoverage) => void): this;
        prependOnceListener(event: "test:dequeue", listener: (data: TestDequeue) => void): this;
        prependOnceListener(event: "test:diagnostic", listener: (data: DiagnosticData) => void): this;
        prependOnceListener(event: "test:enqueue", listener: (data: TestEnqueue) => void): this;
        prependOnceListener(event: "test:fail", listener: (data: TestFail) => void): this;
        prependOnceListener(event: "test:pass", listener: (data: TestPass) => void): this;
        prependOnceListener(event: "test:plan", listener: (data: TestPlan) => void): this;
        prependOnceListener(event: "test:start", listener: (data: TestStart) => void): this;
        prependOnceListener(event: "test:stderr", listener: (data: TestStderr) => void): this;
        prependOnceListener(event: "test:stdout", listener: (data: TestStdout) => void): this;
        prependOnceListener(event: "test:watch:drained", listener: () => void): this;
        prependOnceListener(event: string, listener: (...args: any[]) => void): this;
    }

    /**
     * An instance of `TestContext` is passed to each test function in order to interact with the
     * test runner. However, the `TestContext` constructor is not exposed as part of the API.
     * @since v18.0.0
     */
    export interface TestContext {
        /**
         * This function is used to create a hook running before subtest of the current test.
         * @param fn The hook function. The first argument to this function is a `TestContext` object.
         *    If the hook uses callbacks, the callback function is passed as the second argument.
         * @param options Configuration options for the hook.
         * @since v18.17.0
         */
        before(fn?: TestContextHookFn, options?: HookOptions): void;

        /**
         * This function is used to create a hook running before each subtest of the current test.
         * @param fn The hook function. The first argument to this function is a `TestContext` object.
         *    If the hook uses callbacks, the callback function is passed as the second argument.
         * @param options Configuration options for the hook.
         * @since v18.8.0
         */
        beforeEach(fn?: TestContextHookFn, options?: HookOptions): void;

        /**
         * This function is used to create a hook that runs after the current test finishes.
         * @param fn The hook function. The first argument to this function is a `TestContext` object.
         *    If the hook uses callbacks, the callback function is passed as the second argument.
         * @param options Configuration options for the hook.
         * @since v18.13.0
         */
        after(fn?: TestContextHookFn, options?: HookOptions): void;

        /**
         * This function is used to create a hook running after each subtest of the current test.
         * @param fn The hook function. The first argument to this function is a `TestContext` object.
         *    If the hook uses callbacks, the callback function is passed as the second argument.
         * @param options Configuration options for the hook.
         * @since v18.8.0
         */
        afterEach(fn?: TestContextHookFn, options?: HookOptions): void;

        /**
         * This function is used to write diagnostics to the output. Any diagnostic information is
         * included at the end of the test's results. This function does not return a value.
         * @param message Message to be reported.
         * @since v18.0.0
         */
        diagnostic(message: string): void;

        /**
         * The name of the test.
         * @since v18.8.0
         */
        readonly name: string;

        /**
         * If `shouldRunOnlyTests` is truthy, the test context will only run tests that have the `only`
         * option set. Otherwise, all tests are run. If Node.js was not started with the `--test-only`
         * command-line option, this function is a no-op.
         * @param shouldRunOnlyTests Whether or not to run `only` tests.
         * @since v18.0.0
         */
        runOnly(shouldRunOnlyTests: boolean): void;

        /**
         * Can be used to abort test subtasks when the test has been aborted.
         * @since v18.7.0
         */
        readonly signal: AbortSignal;

        /**
         * This function causes the test's output to indicate the test as skipped. If `message` is
         * provided, it is included in the output. Calling `skip()` does not terminate execution of
         * the test function. This function does not return a value.
         * @param message Optional skip message.
         * @since v18.0.0
         */
        skip(message?: string): void;

        /**
         * This function adds a `TODO` directive to the test's output. If `message` is provided, it is
         * included in the output. Calling `todo()` does not terminate execution of the test
         * function. This function does not return a value.
         * @param message Optional `TODO` message.
         * @since v18.0.0
         */
        todo(message?: string): void;

        /**
         * This function is used to create subtests under the current test. This function behaves in
         * the same fashion as the top level {@link test} function.
         * @since v18.0.0
         * @param name The name of the test, which is displayed when reporting test results.
         *    Default: The `name` property of fn, or `'<anonymous>'` if `fn` does not have a name.
         * @param options Configuration options for the test
         * @param fn The function under test. This first argument to this function is a
         *    {@link TestContext} object. If the test uses callbacks, the callback function is
         *    passed as the second argument. Default: A no-op function.
         * @returns A {@link Promise} resolved with `undefined` once the test completes.
         */
        test: typeof test;
        /**
         * Each test provides its own MockTracker instance.
         */
        readonly mock: MockTracker;
    }

    /**
     * An instance of `SuiteContext` is passed to each suite function in order to
     * interact with the test runner. However, the `SuiteContext` constructor is not
     * exposed as part of the API.
     * @since v18.7.0, v16.17.0
     */
    export interface SuiteContext {
        /**
         * The name of the suite.
         * @since v18.8.0, v16.18.0
         */
        readonly name: string;
        /**
         * Can be used to abort test subtasks when the test has been aborted.
         * @since v18.7.0, v16.17.0
         */
        readonly signal: AbortSignal;
    }
    interface TestOptions {
        /**
         * If a number is provided, then that many tests would run in parallel.
         * If truthy, it would run (number of cpu cores - 1) tests in parallel.
         * For subtests, it will be `Infinity` tests in parallel.
         * If falsy, it would only run one test at a time.
         * If unspecified, subtests inherit this value from their parent.
         * @default false
         */
        concurrency?: number | boolean | undefined;

        /**
         * If truthy, and the test context is configured to run `only` tests, then this test will be
         * run. Otherwise, the test is skipped.
         * @default false
         */
        only?: boolean | undefined;

        /**
         * Allows aborting an in-progress test.
         * @since v18.8.0
         */
        signal?: AbortSignal | undefined;

        /**
         * If truthy, the test is skipped. If a string is provided, that string is displayed in the
         * test results as the reason for skipping the test.
         * @default false
         */
        skip?: boolean | string | undefined;

        /**
         * A number of milliseconds the test will fail after. If unspecified, subtests inherit this
         * value from their parent.
         * @default Infinity
         * @since v18.7.0
         */
        timeout?: number | undefined;

        /**
         * If truthy, the test marked as `TODO`. If a string is provided, that string is displayed in
         * the test results as the reason why the test is `TODO`.
         * @default false
         */
        todo?: boolean | string | undefined;
    }

    /**
     * This function is used to create a hook running before running a suite.
     * @param fn The hook function. If the hook uses callbacks, the callback function is passed as
     *    the second argument. Default: A no-op function.
     * @param options Configuration options for the hook.
     * @since v18.8.0
     */
    function before(fn?: HookFn, options?: HookOptions): void;

    /**
     * This function is used to create a hook running after running a suite.
     * @param fn The hook function. If the hook uses callbacks, the callback function is passed as
     *    the second argument. Default: A no-op function.
     * @param options Configuration options for the hook.
     * @since v18.8.0
     */
    function after(fn?: HookFn, options?: HookOptions): void;

    /**
     * This function is used to create a hook running before each subtest of the current suite.
     * @param fn The hook function. If the hook uses callbacks, the callback function is passed as
     *    the second argument. Default: A no-op function.
     * @param options Configuration options for the hook.
     * @since v18.8.0
     */
    function beforeEach(fn?: HookFn, options?: HookOptions): void;

    /**
     * This function is used to create a hook running after each subtest of the current test.
     * @param fn The hook function. If the hook uses callbacks, the callback function is passed as
     *    the second argument. Default: A no-op function.
     * @param options Configuration options for the hook.
     * @since v18.8.0
     */
    function afterEach(fn?: HookFn, options?: HookOptions): void;

    /**
     * The hook function. The first argument is the context in which the hook is called.
     * If the hook uses callbacks, the callback function is passed as the second argument.
     */
    type HookFn = (c: TestContext | SuiteContext, done: (result?: any) => void) => any;

    /**
     * The hook function. The first argument is a `TestContext` object.
     * If the hook uses callbacks, the callback function is passed as the second argument.
     */
    type TestContextHookFn = (t: TestContext, done: (result?: any) => void) => any;

    /**
     * Configuration options for hooks.
     * @since v18.8.0
     */
    interface HookOptions {
        /**
         * Allows aborting an in-progress hook.
         */
        signal?: AbortSignal | undefined;

        /**
         * A number of milliseconds the hook will fail after. If unspecified, subtests inherit this
         * value from their parent.
         * @default Infinity
         */
        timeout?: number | undefined;
    }

    interface MockFunctionOptions {
        /**
         * The number of times that the mock will use the behavior of `implementation`.
         * Once the mock function has been called `times` times,
         * it will automatically restore the behavior of `original`.
         * This value must be an integer greater than zero.
         * @default Infinity
         */
        times?: number | undefined;
    }

    interface MockMethodOptions extends MockFunctionOptions {
        /**
         * If `true`, `object[methodName]` is treated as a getter.
         * This option cannot be used with the `setter` option.
         */
        getter?: boolean | undefined;

        /**
         * If `true`, `object[methodName]` is treated as a setter.
         * This option cannot be used with the `getter` option.
         */
        setter?: boolean | undefined;
    }

    type Mock<F extends Function> = F & {
        mock: MockFunctionContext<F>;
    };

    type NoOpFunction = (...args: any[]) => undefined;

    type FunctionPropertyNames<T> = {
        [K in keyof T]: T[K] extends Function ? K : never;
    }[keyof T];

    interface MockTracker {
        /**
         * This function is used to create a mock function.
         * @param original An optional function to create a mock on.
         * @param implementation An optional function used as the mock implementation for `original`.
         *  This is useful for creating mocks that exhibit one behavior for a specified number of calls and then restore the behavior of `original`.
         * @param options Optional configuration options for the mock function.
         */
        fn<F extends Function = NoOpFunction>(original?: F, options?: MockFunctionOptions): Mock<F>;
        fn<F extends Function = NoOpFunction, Implementation extends Function = F>(
            original?: F,
            implementation?: Implementation,
            options?: MockFunctionOptions,
        ): Mock<F | Implementation>;
        /**
         * This function is used to create a mock on an existing object method.
         * @param object The object whose method is being mocked.
         * @param methodName The identifier of the method on `object` to mock. If `object[methodName]` is not a function, an error is thrown.
         * @param implementation An optional function used as the mock implementation for `object[methodName]`.
         * @param options Optional configuration options for the mock method.
         */
        method<
            MockedObject extends object,
            MethodName extends FunctionPropertyNames<MockedObject>,
        >(
            object: MockedObject,
            methodName: MethodName,
            options?: MockFunctionOptions,
        ): MockedObject[MethodName] extends Function ? Mock<MockedObject[MethodName]>
            : never;
        method<
            MockedObject extends object,
            MethodName extends FunctionPropertyNames<MockedObject>,
            Implementation extends Function,
        >(
            object: MockedObject,
            methodName: MethodName,
            implementation: Implementation,
            options?: MockFunctionOptions,
        ): MockedObject[MethodName] extends Function ? Mock<MockedObject[MethodName] | Implementation>
            : never;
        method<MockedObject extends object>(
            object: MockedObject,
            methodName: keyof MockedObject,
            options: MockMethodOptions,
        ): Mock<Function>;
        method<MockedObject extends object>(
            object: MockedObject,
            methodName: keyof MockedObject,
            implementation: Function,
            options: MockMethodOptions,
        ): Mock<Function>;
        /**
         * This function is syntax sugar for {@link MockTracker.method} with `options.getter` set to `true`.
         */
        getter<
            MockedObject extends object,
            MethodName extends keyof MockedObject,
        >(
            object: MockedObject,
            methodName: MethodName,
            options?: MockFunctionOptions,
        ): Mock<() => MockedObject[MethodName]>;
        getter<
            MockedObject extends object,
            MethodName extends keyof MockedObject,
            Implementation extends Function,
        >(
            object: MockedObject,
            methodName: MethodName,
            implementation?: Implementation,
            options?: MockFunctionOptions,
        ): Mock<(() => MockedObject[MethodName]) | Implementation>;
        /**
         * This function is syntax sugar for {@link MockTracker.method} with `options.setter` set to `true`.
         */
        setter<
            MockedObject extends object,
            MethodName extends keyof MockedObject,
        >(
            object: MockedObject,
            methodName: MethodName,
            options?: MockFunctionOptions,
        ): Mock<(value: MockedObject[MethodName]) => void>;
        setter<
            MockedObject extends object,
            MethodName extends keyof MockedObject,
            Implementation extends Function,
        >(
            object: MockedObject,
            methodName: MethodName,
            implementation?: Implementation,
            options?: MockFunctionOptions,
        ): Mock<((value: MockedObject[MethodName]) => void) | Implementation>;
        /**
         * This function restores the default behavior of all mocks that were previously created by this `MockTracker`
         * and disassociates the mocks from the `MockTracker` instance. Once disassociated, the mocks can still be used,
         * but the `MockTracker` instance can no longer be used to reset their behavior or otherwise interact with them.
         *
         * After each test completes, this function is called on the test context's `MockTracker`.
         * If the global `MockTracker` is used extensively, calling this function manually is recommended.
         */
        reset(): void;
        /**
         * This function restores the default behavior of all mocks that were previously created by this `MockTracker`.
         * Unlike `mock.reset()`, `mock.restoreAll()` does not disassociate the mocks from the `MockTracker` instance.
         */
        restoreAll(): void;
        timers: MockTimers;
    }

    const mock: MockTracker;

    interface MockFunctionCall<
        F extends Function,
        ReturnType = F extends (...args: any) => infer T ? T
            : F extends abstract new(...args: any) => infer T ? T
            : unknown,
        Args = F extends (...args: infer Y) => any ? Y
            : F extends abstract new(...args: infer Y) => any ? Y
            : unknown[],
    > {
        /**
         * An array of the arguments passed to the mock function.
         */
        arguments: Args;
        /**
         * If the mocked function threw then this property contains the thrown value.
         */
        error: unknown | undefined;
        /**
         * The value returned by the mocked function.
         *
         * If the mocked function threw, it will be `undefined`.
         */
        result: ReturnType | undefined;
        /**
         * An `Error` object whose stack can be used to determine the callsite of the mocked function invocation.
         */
        stack: Error;
        /**
         * If the mocked function is a constructor, this field contains the class being constructed.
         * Otherwise this will be `undefined`.
         */
        target: F extends abstract new(...args: any) => any ? F : undefined;
        /**
         * The mocked function's `this` value.
         */
        this: unknown;
    }

    interface MockFunctionContext<F extends Function> {
        /**
         * A getter that returns a copy of the internal array used to track calls to the mock.
         */
        readonly calls: Array<MockFunctionCall<F>>;

        /**
         * This function returns the number of times that this mock has been invoked.
         * This function is more efficient than checking `ctx.calls.length`
         * because `ctx.calls` is a getter that creates a copy of the internal call tracking array.
         */
        callCount(): number;

        /**
         * This function is used to change the behavior of an existing mock.
         * @param implementation The function to be used as the mock's new implementation.
         */
        mockImplementation(implementation: F): void;

        /**
         * This function is used to change the behavior of an existing mock for a single invocation.
         * Once invocation `onCall` has occurred, the mock will revert to whatever behavior
         * it would have used had `mockImplementationOnce()` not been called.
         * @param implementation The function to be used as the mock's implementation for the invocation number specified by `onCall`.
         * @param onCall The invocation number that will use `implementation`.
         *  If the specified invocation has already occurred then an exception is thrown.
         */
        mockImplementationOnce(implementation: F, onCall?: number): void;

        /**
         * Resets the call history of the mock function.
         */
        resetCalls(): void;

        /**
         * Resets the implementation of the mock function to its original behavior.
         * The mock can still be used after calling this function.
         */
        restore(): void;
    }

    type Timer = "setInterval" | "clearInterval" | "setTimeout" | "clearTimeout";
    /**
     * Mocking timers is a technique commonly used in software testing to simulate and
     * control the behavior of timers, such as `setInterval` and `setTimeout`,
     * without actually waiting for the specified time intervals.
     *
     * The `MockTracker` provides a top-level `timers` export
     * which is a `MockTimers` instance.
     * @since v18.19.0
     * @experimental
     */
    class MockTimers {
        /**
         * Enables timer mocking for the specified timers.
         *
         * **Note:** When you enable mocking for a specific timer, its associated
         * clear function will also be implicitly mocked.
         *
         * Example usage:
         *
         * ```js
         * import { mock } from 'node:test';
         * mock.timers.enable(['setInterval']);
         * ```
         *
         * The above example enables mocking for the `setInterval` timer and
         * implicitly mocks the `clearInterval` function. Only the `setInterval` and `clearInterval` functions from `node:timers`, `node:timers/promises`, and`globalThis` will be mocked.
         *
         * Alternatively, if you call `mock.timers.enable()` without any parameters:
         *
         * All timers (`'setInterval'`, `'clearInterval'`, `'setTimeout'`, and `'clearTimeout'`)
         * will be mocked. The `setInterval`, `clearInterval`, `setTimeout`, and `clearTimeout` functions from `node:timers`, `node:timers/promises`,
         * and `globalThis` will be mocked.
         * @since v18.19.0
         */
        enable(timers?: Timer[]): void;
        /**
         * This function restores the default behavior of all mocks that were previously
         * created by this `MockTimers` instance and disassociates the mocks
         * from the `MockTracker` instance.
         *
         * **Note:** After each test completes, this function is called on
         * the test context's `MockTracker`.
         *
         * ```js
         * import { mock } from 'node:test';
         * mock.timers.reset();
         * ```
         * @since v18.19.0
         */
        reset(): void;
        /**
         * Advances time for all mocked timers.
         *
         * **Note:** This diverges from how `setTimeout` in Node.js behaves and accepts
         * only positive numbers. In Node.js, `setTimeout` with negative numbers is
         * only supported for web compatibility reasons.
         *
         * The following example mocks a `setTimeout` function and
         * by using `.tick` advances in
         * time triggering all pending timers.
         *
         * ```js
         * import assert from 'node:assert';
         * import { test } from 'node:test';
         *
         * test('mocks setTimeout to be executed synchronously without having to actually wait for it', (context) => {
         *   const fn = context.mock.fn();
         *
         *   context.mock.timers.enable(['setTimeout']);
         *
         *   setTimeout(fn, 9999);
         *
         *   assert.strictEqual(fn.mock.callCount(), 0);
         *
         *   // Advance in time
         *   context.mock.timers.tick(9999);
         *
         *   assert.strictEqual(fn.mock.callCount(), 1);
         * });
         * ```
         *
         * Alternativelly, the `.tick` function can be called many times
         *
         * ```js
         * import assert from 'node:assert';
         * import { test } from 'node:test';
         *
         * test('mocks setTimeout to be executed synchronously without having to actually wait for it', (context) => {
         *   const fn = context.mock.fn();
         *   context.mock.timers.enable(['setTimeout']);
         *   const nineSecs = 9000;
         *   setTimeout(fn, nineSecs);
         *
         *   const twoSeconds = 3000;
         *   context.mock.timers.tick(twoSeconds);
         *   context.mock.timers.tick(twoSeconds);
         *   context.mock.timers.tick(twoSeconds);
         *
         *   assert.strictEqual(fn.mock.callCount(), 1);
         * });
         * ```
         * @since v18.19.0
         */
        tick(milliseconds: number): void;
        /**
         * Triggers all pending mocked timers immediately.
         *
         * The example below triggers all pending timers immediately,
         * causing them to execute without any delay.
         *
         * ```js
         * import assert from 'node:assert';
         * import { test } from 'node:test';
         *
         * test('runAll functions following the given order', (context) => {
         *   context.mock.timers.enable(['setTimeout']);
         *   const results = [];
         *   setTimeout(() => results.push(1), 9999);
         *
         *   // Notice that if both timers have the same timeout,
         *   // the order of execution is guaranteed
         *   setTimeout(() => results.push(3), 8888);
         *   setTimeout(() => results.push(2), 8888);
         *
         *   assert.deepStrictEqual(results, []);
         *
         *   context.mock.timers.runAll();
         *
         *   assert.deepStrictEqual(results, [3, 2, 1]);
         * });
         * ```
         *
         * **Note:** The `runAll()` function is specifically designed for
         * triggering timers in the context of timer mocking.
         * It does not have any effect on real-time system
         * clocks or actual timers outside of the mocking environment.
         * @since v18.19.0
         */
        runAll(): void;
        /**
         * Calls {@link MockTimers.reset()}.
         */
        [Symbol.dispose](): void;
    }

    export { after, afterEach, before, beforeEach, describe, it, mock, run, test, test as default };
}

interface TestError extends Error {
    cause: Error;
}
interface TestLocationInfo {
    /**
     * The column number where the test is defined, or
     * `undefined` if the test was run through the REPL.
     */
    column?: number;
    /**
     * The path of the test file, `undefined` if test is not ran through a file.
     */
    file?: string;
    /**
     * The line number where the test is defined, or
     * `undefined` if the test was run through the REPL.
     */
    line?: number;
}
interface DiagnosticData extends TestLocationInfo {
    /**
     * The diagnostic message.
     */
    message: string;
    /**
     * The nesting level of the test.
     */
    nesting: number;
}
interface TestCoverage {
    /**
     * An object containing the coverage report.
     */
    summary: {
        /**
         * An array of coverage reports for individual files.
         */
        files: Array<{
            /**
             * The absolute path of the file.
             */
            path: string;
            /**
             * The total number of lines.
             */
            totalLineCount: number;
            /**
             * The total number of branches.
             */
            totalBranchCount: number;
            /**
             * The total number of functions.
             */
            totalFunctionCount: number;
            /**
             * The number of covered lines.
             */
            coveredLineCount: number;
            /**
             * The number of covered branches.
             */
            coveredBranchCount: number;
            /**
             * The number of covered functions.
             */
            coveredFunctionCount: number;
            /**
             * The percentage of lines covered.
             */
            coveredLinePercent: number;
            /**
             * The percentage of branches covered.
             */
            coveredBranchPercent: number;
            /**
             * The percentage of functions covered.
             */
            coveredFunctionPercent: number;
            /**
             * An array of integers representing line numbers that are uncovered.
             */
            uncoveredLineNumbers: number[];
        }>;
        /**
         * An object containing a summary of coverage for all files.
         */
        totals: {
            /**
             * The total number of lines.
             */
            totalLineCount: number;
            /**
             * The total number of branches.
             */
            totalBranchCount: number;
            /**
             * The total number of functions.
             */
            totalFunctionCount: number;
            /**
             * The number of covered lines.
             */
            coveredLineCount: number;
            /**
             * The number of covered branches.
             */
            coveredBranchCount: number;
            /**
             * The number of covered functions.
             */
            coveredFunctionCount: number;
            /**
             * The percentage of lines covered.
             */
            coveredLinePercent: number;
            /**
             * The percentage of branches covered.
             */
            coveredBranchPercent: number;
            /**
             * The percentage of functions covered.
             */
            coveredFunctionPercent: number;
        };
        /**
         * The working directory when code coverage began. This
         * is useful for displaying relative path names in case
         * the tests changed the working directory of the Node.js process.
         */
        workingDirectory: string;
    };
    /**
     * The nesting level of the test.
     */
    nesting: number;
}
interface TestDequeue extends TestLocationInfo {
    /**
     * The test name
     */
    name: string;
    /**
     * The nesting level of the test.
     */
    nesting: number;
}
interface TestEnqueue extends TestLocationInfo {
    /**
     * The test name
     */
    name: string;
    /**
     * The nesting level of the test.
     */
    nesting: number;
}
interface TestFail extends TestLocationInfo {
    /**
     * Additional execution metadata.
     */
    details: {
        /**
         * The duration of the test in milliseconds.
         */
        duration_ms: number;
        /**
         * The error thrown by the test.
         */
        error: TestError;
        /**
         * The type of the test, used to denote whether this is a suite.
         * @since 18.17.0
         */
        type?: "suite";
    };
    /**
     * The test name.
     */
    name: string;
    /**
     * The nesting level of the test.
     */
    nesting: number;
    /**
     * The ordinal number of the test.
     */
    testNumber: number;
    /**
     * Present if `context.todo` is called.
     */
    todo?: string | boolean;
    /**
     * Present if `context.skip` is called.
     */
    skip?: string | boolean;
}
interface TestPass extends TestLocationInfo {
    /**
     * Additional execution metadata.
     */
    details: {
        /**
         * The duration of the test in milliseconds.
         */
        duration_ms: number;
        /**
         * The type of the test, used to denote whether this is a suite.
         * @since 18.17.0
         */
        type?: "suite";
    };
    /**
     * The test name.
     */
    name: string;
    /**
     * The nesting level of the test.
     */
    nesting: number;
    /**
     * The ordinal number of the test.
     */
    testNumber: number;
    /**
     * Present if `context.todo` is called.
     */
    todo?: string | boolean;
    /**
     * Present if `context.skip` is called.
     */
    skip?: string | boolean;
}
interface TestPlan extends TestLocationInfo {
    /**
     * The nesting level of the test.
     */
    nesting: number;
    /**
     * The number of subtests that have ran.
     */
    count: number;
}
interface TestStart extends TestLocationInfo {
    /**
     * The test name.
     */
    name: string;
    /**
     * The nesting level of the test.
     */
    nesting: number;
}
interface TestStderr extends TestLocationInfo {
    /**
     * The message written to `stderr`
     */
    message: string;
}
interface TestStdout extends TestLocationInfo {
    /**
     * The message written to `stdout`
     */
    message: string;
}

/**
 * The `node:test/reporters` module exposes the builtin-reporters for `node:test`.
 * To access it:
 *
 * ```js
 * import test from 'node:test/reporters';
 * ```
 *
 * This module is only available under the `node:` scheme. The following will not
 * work:
 *
 * ```js
 * import test from 'test/reporters';
 * ```
 * @since v18.17.0
 * @see [source](https://github.com/nodejs/node/blob/v18.17.0/lib/test/reporters.js)
 */
declare module "node:test/reporters" {
    import { Transform } from "node:stream";

    type TestEvent =
        | { type: "test:coverage"; data: TestCoverage }
        | { type: "test:dequeue"; data: TestDequeue }
        | { type: "test:diagnostic"; data: DiagnosticData }
        | { type: "test:enqueue"; data: TestEnqueue }
        | { type: "test:fail"; data: TestFail }
        | { type: "test:pass"; data: TestPass }
        | { type: "test:plan"; data: TestPlan }
        | { type: "test:start"; data: TestStart }
        | { type: "test:stderr"; data: TestStderr }
        | { type: "test:stdout"; data: TestStdout }
        | { type: "test:watch:drained"; data: undefined };
    type TestEventGenerator = AsyncGenerator<TestEvent, void>;

    /**
     * The `dot` reporter outputs the test results in a compact format,
     * where each passing test is represented by a `.`,
     * and each failing test is represented by a `X`.
     */
    function dot(source: TestEventGenerator): AsyncGenerator<"\n" | "." | "X", void>;
    /**
     * The `tap` reporter outputs the test results in the [TAP](https://testanything.org/) format.
     */
    function tap(source: TestEventGenerator): AsyncGenerator<string, void>;
    /**
     * The `spec` reporter outputs the test results in a human-readable format.
     */
    class Spec extends Transform {
        constructor();
    }
    /**
     * The `junit` reporter outputs test results in a jUnit XML format
     */
    function junit(source: TestEventGenerator): AsyncGenerator<string, void>;
    export { dot, junit, Spec as spec, tap, TestEvent };
}
