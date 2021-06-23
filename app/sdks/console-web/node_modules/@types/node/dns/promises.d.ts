declare module "dns/promises" {
    import {
        LookupAddress,
        LookupOneOptions,
        LookupAllOptions,
        LookupOptions,
        AnyRecord,
        CaaRecord,
        MxRecord,
        NaptrRecord,
        SoaRecord,
        SrvRecord,
        ResolveWithTtlOptions,
        RecordWithTtl,
        ResolveOptions,
        ResolverOptions,
    } from "dns";

    function getServers(): string[];

    function lookup(hostname: string, family: number): Promise<LookupAddress>;
    function lookup(hostname: string, options: LookupOneOptions): Promise<LookupAddress>;
    function lookup(hostname: string, options: LookupAllOptions): Promise<LookupAddress[]>;
    function lookup(hostname: string, options: LookupOptions): Promise<LookupAddress | LookupAddress[]>;
    function lookup(hostname: string): Promise<LookupAddress>;

    function lookupService(address: string, port: number): Promise<{ hostname: string, service: string }>;

    function resolve(hostname: string): Promise<string[]>;
    function resolve(hostname: string, rrtype: "A"): Promise<string[]>;
    function resolve(hostname: string, rrtype: "AAAA"): Promise<string[]>;
    function resolve(hostname: string, rrtype: "ANY"): Promise<AnyRecord[]>;
    function resolve(hostname: string, rrtype: "CAA"): Promise<CaaRecord[]>;
    function resolve(hostname: string, rrtype: "CNAME"): Promise<string[]>;
    function resolve(hostname: string, rrtype: "MX"): Promise<MxRecord[]>;
    function resolve(hostname: string, rrtype: "NAPTR"): Promise<NaptrRecord[]>;
    function resolve(hostname: string, rrtype: "NS"): Promise<string[]>;
    function resolve(hostname: string, rrtype: "PTR"): Promise<string[]>;
    function resolve(hostname: string, rrtype: "SOA"): Promise<SoaRecord>;
    function resolve(hostname: string, rrtype: "SRV"): Promise<SrvRecord[]>;
    function resolve(hostname: string, rrtype: "TXT"): Promise<string[][]>;
    function resolve(hostname: string, rrtype: string): Promise<string[] | MxRecord[] | NaptrRecord[] | SoaRecord | SrvRecord[] | string[][] | AnyRecord[]>;

    function resolve4(hostname: string): Promise<string[]>;
    function resolve4(hostname: string, options: ResolveWithTtlOptions): Promise<RecordWithTtl[]>;
    function resolve4(hostname: string, options: ResolveOptions): Promise<string[] | RecordWithTtl[]>;

    function resolve6(hostname: string): Promise<string[]>;
    function resolve6(hostname: string, options: ResolveWithTtlOptions): Promise<RecordWithTtl[]>;
    function resolve6(hostname: string, options: ResolveOptions): Promise<string[] | RecordWithTtl[]>;

    function resolveAny(hostname: string): Promise<AnyRecord[]>;

    function resolveCaa(hostname: string): Promise<CaaRecord[]>;

    function resolveCname(hostname: string): Promise<string[]>;

    function resolveMx(hostname: string): Promise<MxRecord[]>;

    function resolveNaptr(hostname: string): Promise<NaptrRecord[]>;

    function resolveNs(hostname: string): Promise<string[]>;

    function resolvePtr(hostname: string): Promise<string[]>;

    function resolveSoa(hostname: string): Promise<SoaRecord>;

    function resolveSrv(hostname: string): Promise<SrvRecord[]>;

    function resolveTxt(hostname: string): Promise<string[][]>;

    function reverse(ip: string): Promise<string[]>;

    function setServers(servers: ReadonlyArray<string>): void;

    class Resolver {
        constructor(options?: ResolverOptions);

        cancel(): void;
        getServers: typeof getServers;
        resolve: typeof resolve;
        resolve4: typeof resolve4;
        resolve6: typeof resolve6;
        resolveAny: typeof resolveAny;
        resolveCname: typeof resolveCname;
        resolveMx: typeof resolveMx;
        resolveNaptr: typeof resolveNaptr;
        resolveNs: typeof resolveNs;
        resolvePtr: typeof resolvePtr;
        resolveSoa: typeof resolveSoa;
        resolveSrv: typeof resolveSrv;
        resolveTxt: typeof resolveTxt;
        reverse: typeof reverse;
        setLocalAddress(ipv4?: string, ipv6?: string): void;
        setServers: typeof setServers;
    }
}
