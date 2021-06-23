declare module 'net' {
    import * as stream from 'stream';
    import { Abortable, EventEmitter } from 'events';
    import * as dns from 'dns';

    type LookupFunction = (
        hostname: string,
        options: dns.LookupOneOptions,
        callback: (err: NodeJS.ErrnoException | null, address: string, family: number) => void,
    ) => void;

    interface AddressInfo {
        address: string;
        family: string;
        port: number;
    }

    interface SocketConstructorOpts {
        fd?: number;
        allowHalfOpen?: boolean;
        readable?: boolean;
        writable?: boolean;
    }

    interface OnReadOpts {
        buffer: Uint8Array | (() => Uint8Array);
        /**
         * This function is called for every chunk of incoming data.
         * Two arguments are passed to it: the number of bytes written to buffer and a reference to buffer.
         * Return false from this function to implicitly pause() the socket.
         */
        callback(bytesWritten: number, buf: Uint8Array): boolean;
    }

    interface ConnectOpts {
        /**
         * If specified, incoming data is stored in a single buffer and passed to the supplied callback when data arrives on the socket.
         * Note: this will cause the streaming functionality to not provide any data, however events like 'error', 'end', and 'close' will
         * still be emitted as normal and methods like pause() and resume() will also behave as expected.
         */
        onread?: OnReadOpts;
    }

    interface TcpSocketConnectOpts extends ConnectOpts {
        port: number;
        host?: string;
        localAddress?: string;
        localPort?: number;
        hints?: number;
        family?: number;
        lookup?: LookupFunction;
    }

    interface IpcSocketConnectOpts extends ConnectOpts {
        path: string;
    }

    type SocketConnectOpts = TcpSocketConnectOpts | IpcSocketConnectOpts;

    class Socket extends stream.Duplex {
        constructor(options?: SocketConstructorOpts);

        // Extended base methods
        write(buffer: Uint8Array | string, cb?: (err?: Error) => void): boolean;
        write(str: Uint8Array | string, encoding?: BufferEncoding, cb?: (err?: Error) => void): boolean;

        connect(options: SocketConnectOpts, connectionListener?: () => void): this;
        connect(port: number, host: string, connectionListener?: () => void): this;
        connect(port: number, connectionListener?: () => void): this;
        connect(path: string, connectionListener?: () => void): this;

        setEncoding(encoding?: BufferEncoding): this;
        pause(): this;
        resume(): this;
        setTimeout(timeout: number, callback?: () => void): this;
        setNoDelay(noDelay?: boolean): this;
        setKeepAlive(enable?: boolean, initialDelay?: number): this;
        address(): AddressInfo | {};
        unref(): this;
        ref(): this;

        /** @deprecated since v14.6.0 - Use `writableLength` instead. */
        readonly bufferSize: number;
        readonly bytesRead: number;
        readonly bytesWritten: number;
        readonly connecting: boolean;
        readonly destroyed: boolean;
        readonly localAddress: string;
        readonly localPort: number;
        readonly remoteAddress?: string;
        readonly remoteFamily?: string;
        readonly remotePort?: number;

        // Extended base methods
        end(cb?: () => void): void;
        end(buffer: Uint8Array | string, cb?: () => void): void;
        end(str: Uint8Array | string, encoding?: BufferEncoding, cb?: () => void): void;

        /**
         * events.EventEmitter
         *   1. close
         *   2. connect
         *   3. data
         *   4. drain
         *   5. end
         *   6. error
         *   7. lookup
         *   8. timeout
         */
        addListener(event: string, listener: (...args: any[]) => void): this;
        addListener(event: "close", listener: (had_error: boolean) => void): this;
        addListener(event: "connect", listener: () => void): this;
        addListener(event: "data", listener: (data: Buffer) => void): this;
        addListener(event: "drain", listener: () => void): this;
        addListener(event: "end", listener: () => void): this;
        addListener(event: "error", listener: (err: Error) => void): this;
        addListener(event: "lookup", listener: (err: Error, address: string, family: string | number, host: string) => void): this;
        addListener(event: "timeout", listener: () => void): this;

        emit(event: string | symbol, ...args: any[]): boolean;
        emit(event: "close", had_error: boolean): boolean;
        emit(event: "connect"): boolean;
        emit(event: "data", data: Buffer): boolean;
        emit(event: "drain"): boolean;
        emit(event: "end"): boolean;
        emit(event: "error", err: Error): boolean;
        emit(event: "lookup", err: Error, address: string, family: string | number, host: string): boolean;
        emit(event: "timeout"): boolean;

        on(event: string, listener: (...args: any[]) => void): this;
        on(event: "close", listener: (had_error: boolean) => void): this;
        on(event: "connect", listener: () => void): this;
        on(event: "data", listener: (data: Buffer) => void): this;
        on(event: "drain", listener: () => void): this;
        on(event: "end", listener: () => void): this;
        on(event: "error", listener: (err: Error) => void): this;
        on(event: "lookup", listener: (err: Error, address: string, family: string | number, host: string) => void): this;
        on(event: "timeout", listener: () => void): this;

        once(event: string, listener: (...args: any[]) => void): this;
        once(event: "close", listener: (had_error: boolean) => void): this;
        once(event: "connect", listener: () => void): this;
        once(event: "data", listener: (data: Buffer) => void): this;
        once(event: "drain", listener: () => void): this;
        once(event: "end", listener: () => void): this;
        once(event: "error", listener: (err: Error) => void): this;
        once(event: "lookup", listener: (err: Error, address: string, family: string | number, host: string) => void): this;
        once(event: "timeout", listener: () => void): this;

        prependListener(event: string, listener: (...args: any[]) => void): this;
        prependListener(event: "close", listener: (had_error: boolean) => void): this;
        prependListener(event: "connect", listener: () => void): this;
        prependListener(event: "data", listener: (data: Buffer) => void): this;
        prependListener(event: "drain", listener: () => void): this;
        prependListener(event: "end", listener: () => void): this;
        prependListener(event: "error", listener: (err: Error) => void): this;
        prependListener(event: "lookup", listener: (err: Error, address: string, family: string | number, host: string) => void): this;
        prependListener(event: "timeout", listener: () => void): this;

        prependOnceListener(event: string, listener: (...args: any[]) => void): this;
        prependOnceListener(event: "close", listener: (had_error: boolean) => void): this;
        prependOnceListener(event: "connect", listener: () => void): this;
        prependOnceListener(event: "data", listener: (data: Buffer) => void): this;
        prependOnceListener(event: "drain", listener: () => void): this;
        prependOnceListener(event: "end", listener: () => void): this;
        prependOnceListener(event: "error", listener: (err: Error) => void): this;
        prependOnceListener(event: "lookup", listener: (err: Error, address: string, family: string | number, host: string) => void): this;
        prependOnceListener(event: "timeout", listener: () => void): this;
    }

    interface ListenOptions extends Abortable {
        port?: number;
        host?: string;
        backlog?: number;
        path?: string;
        exclusive?: boolean;
        readableAll?: boolean;
        writableAll?: boolean;
        /**
         * @default false
         */
        ipv6Only?: boolean;
    }

    interface ServerOpts {
        /**
         * Indicates whether half-opened TCP connections are allowed.
         * @default false
         */
        allowHalfOpen?: boolean;

        /**
         * Indicates whether the socket should be paused on incoming connections.
         * @default false
         */
        pauseOnConnect?: boolean;
    }

    // https://github.com/nodejs/node/blob/master/lib/net.js
    class Server extends EventEmitter {
        constructor(connectionListener?: (socket: Socket) => void);
        constructor(options?: ServerOpts, connectionListener?: (socket: Socket) => void);

        listen(port?: number, hostname?: string, backlog?: number, listeningListener?: () => void): this;
        listen(port?: number, hostname?: string, listeningListener?: () => void): this;
        listen(port?: number, backlog?: number, listeningListener?: () => void): this;
        listen(port?: number, listeningListener?: () => void): this;
        listen(path: string, backlog?: number, listeningListener?: () => void): this;
        listen(path: string, listeningListener?: () => void): this;
        listen(options: ListenOptions, listeningListener?: () => void): this;
        listen(handle: any, backlog?: number, listeningListener?: () => void): this;
        listen(handle: any, listeningListener?: () => void): this;
        close(callback?: (err?: Error) => void): this;
        address(): AddressInfo | string | null;
        getConnections(cb: (error: Error | null, count: number) => void): void;
        ref(): this;
        unref(): this;
        maxConnections: number;
        connections: number;
        listening: boolean;

        /**
         * events.EventEmitter
         *   1. close
         *   2. connection
         *   3. error
         *   4. listening
         */
        addListener(event: string, listener: (...args: any[]) => void): this;
        addListener(event: "close", listener: () => void): this;
        addListener(event: "connection", listener: (socket: Socket) => void): this;
        addListener(event: "error", listener: (err: Error) => void): this;
        addListener(event: "listening", listener: () => void): this;

        emit(event: string | symbol, ...args: any[]): boolean;
        emit(event: "close"): boolean;
        emit(event: "connection", socket: Socket): boolean;
        emit(event: "error", err: Error): boolean;
        emit(event: "listening"): boolean;

        on(event: string, listener: (...args: any[]) => void): this;
        on(event: "close", listener: () => void): this;
        on(event: "connection", listener: (socket: Socket) => void): this;
        on(event: "error", listener: (err: Error) => void): this;
        on(event: "listening", listener: () => void): this;

        once(event: string, listener: (...args: any[]) => void): this;
        once(event: "close", listener: () => void): this;
        once(event: "connection", listener: (socket: Socket) => void): this;
        once(event: "error", listener: (err: Error) => void): this;
        once(event: "listening", listener: () => void): this;

        prependListener(event: string, listener: (...args: any[]) => void): this;
        prependListener(event: "close", listener: () => void): this;
        prependListener(event: "connection", listener: (socket: Socket) => void): this;
        prependListener(event: "error", listener: (err: Error) => void): this;
        prependListener(event: "listening", listener: () => void): this;

        prependOnceListener(event: string, listener: (...args: any[]) => void): this;
        prependOnceListener(event: "close", listener: () => void): this;
        prependOnceListener(event: "connection", listener: (socket: Socket) => void): this;
        prependOnceListener(event: "error", listener: (err: Error) => void): this;
        prependOnceListener(event: "listening", listener: () => void): this;
    }

    type IPVersion = 'ipv4' | 'ipv6';

    class BlockList {
        /**
         * Adds a rule to block the given IP address.
         *
         * @param address An IPv4 or IPv6 address.
         * @param type Either 'ipv4' or 'ipv6'. Default: 'ipv4'.
         */
        addAddress(address: string, type?: IPVersion): void;

        /**
         * Adds a rule to block a range of IP addresses from start (inclusive) to end (inclusive).
         *
         * @param start The starting IPv4 or IPv6 address in the range.
         * @param end The ending IPv4 or IPv6 address in the range.
         * @param type Either 'ipv4' or 'ipv6'. Default: 'ipv4'.
         */
        addRange(start: string, end: string, type?: IPVersion): void;

        /**
         * Adds a rule to block a range of IP addresses specified as a subnet mask.
         *
         * @param net The network IPv4 or IPv6 address.
         * @param prefix The number of CIDR prefix bits.
         * For IPv4, this must be a value between 0 and 32. For IPv6, this must be between 0 and 128.
         * @param type Either 'ipv4' or 'ipv6'. Default: 'ipv4'.
         */
        addSubnet(net: string, prefix: number, type?: IPVersion): void;

        /**
         * Returns `true` if the given IP address matches any of the rules added to the `BlockList`.
         *
         * @param address The IP address to check
         * @param type Either 'ipv4' or 'ipv6'. Default: 'ipv4'.
         */
        check(address: string, type?: IPVersion): boolean;
    }

    interface TcpNetConnectOpts extends TcpSocketConnectOpts, SocketConstructorOpts {
        timeout?: number;
    }

    interface IpcNetConnectOpts extends IpcSocketConnectOpts, SocketConstructorOpts {
        timeout?: number;
    }

    type NetConnectOpts = TcpNetConnectOpts | IpcNetConnectOpts;

    function createServer(connectionListener?: (socket: Socket) => void): Server;
    function createServer(options?: ServerOpts, connectionListener?: (socket: Socket) => void): Server;
    function connect(options: NetConnectOpts, connectionListener?: () => void): Socket;
    function connect(port: number, host?: string, connectionListener?: () => void): Socket;
    function connect(path: string, connectionListener?: () => void): Socket;
    function createConnection(options: NetConnectOpts, connectionListener?: () => void): Socket;
    function createConnection(port: number, host?: string, connectionListener?: () => void): Socket;
    function createConnection(path: string, connectionListener?: () => void): Socket;
    function isIP(input: string): number;
    function isIPv4(input: string): boolean;
    function isIPv6(input: string): boolean;
}
