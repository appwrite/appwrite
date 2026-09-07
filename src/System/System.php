<?php

namespace Utopia\System;

use Exception;

class System
{
    public const X86 = 'x86';

    public const PPC = 'ppc';

    public const ARM64 = 'arm64';

    public const ARMV7 = 'armv7';

    public const ARMV8 = 'armv8';

    private const RegExX86 = '/(x86*|i386|i686)/';

    private const RegexARM64 = '/(arm64|aarch64)/';

    private const RegexARMV7 = '/(armv7)/';

    private const RegexARMV8 = '/(armv8)/';

    private const RegExPPC = '/(ppc*)/';

    /**
     * A list of Linux Disks that are not considered valid
     * These are usually virtual drives or other non-physical devices such as loopback or ram.
     *
     * This list is ran through a contains, meaning for example if 'loop' was in the list,
     * A 'loop0' interface would be considered invalid and not computed.
     *
     * Documentation:
     * Loop - https://man7.org/linux/man-pages/man4/loop.4.html
     * Ram - https://man7.org/linux/man-pages/man4/ram.4.html
     *
     * @var array<int, string>
     */
    private const INVALID_DISKS = [
        'loop',
        'ram',
    ];

    /**
     * A list of Linux Network Interfaces that are not considered valid
     * These are usually virtual interfaces created by tools such as Docker or VirtualBox
     *
     * This list is ran through a contains, meaning for example if 'vboxnet' was in the list,
     * A 'vboxnet0' interface would be considered invalid and not computed.
     *
     * Documentation:
     * veth - https://man7.org/linux/man-pages/man4/veth.4.html
     * docker - https://docs.docker.com/network/
     * lo - Localhost Loopback device, https://man7.org/linux/man-pages/man4/loop.4.html
     * tun - Linux Layer 3 Interface, https://www.kernel.org/doc/html/v5.8/networking/tuntap.html
     * vboxnet - Virtual Machine Networking Interface, https://www.virtualbox.org/manual/ch06.html
     * bonding_masters - https://www.kernel.org/doc/Documentation/networking/bonding.txt
     */
    private const INVALIDNETINTERFACES = [
        'veth',
        'docker',
        'lo',
        'tun',
        'vboxnet',
        '.',
        'bonding_masters',
    ];

    /**
     * Returns the system's OS.
     */
    public static function getOS(): string
    {
        return php_uname('s');
    }

    /**
     * Returns the architecture of the system's processor.
     */
    public static function getArch(): string
    {
        return php_uname('m');
    }

    /**
     * Returns the architecture's Enum of the system's processor.
     *
     *
     * @throws Exception
     */
    public static function getArchEnum(): string
    {
        $arch = self::getArch();
        return match (1) {
            preg_match(self::RegExX86, $arch) => System::X86,
            preg_match(self::RegExPPC, $arch) => System::PPC,
            preg_match(self::RegexARM64, $arch) => System::ARM64,
            preg_match('/' . self::ARMV7 . '/', $arch) => System::ARMV7,
            preg_match('/' . self::ARMV8 . '/', $arch) => System::ARMV8,
            default => throw new Exception("'{$arch}' enum not found."),
        };
    }

    /**
     * Returns the system's hostname.
     */
    public static function getHostname(): string
    {
        return php_uname('n');
    }

    /**
     * Gets the system's total amount of CPU cores.
     *
     *
     * @throws Exception
     * @deprecated Use {@see self::getCPU()} instead, which returns a float and
     *             honours cgroup CPU limits inside Docker/Kubernetes containers.
     */
    public static function getCPUCores(): int
    {
        switch (self::getOS()) {
            case 'Linux':
                $cpuInfo = file_get_contents('/proc/cpuinfo');
                $matches[] = [];

                if ($cpuInfo) {
                    preg_match_all('/^processor/m', $cpuInfo, $matches);
                }

                return \count($matches[0]);
            case 'Darwin':
                return \intval(shell_exec('sysctl -n hw.ncpu'));
            case 'Windows':
                return \intval(shell_exec('wmic cpu get NumberOfCores'));
            default:
                throw new Exception(self::getOS() . ' not supported.');
        }
    }

    /**
     * Gets the amount of CPU available to the current process as a float.
     *
     * On Linux, this respects cgroup v2 (`/sys/fs/cgroup/cpu.max`) and cgroup v1
     * (`cpu.cfs_quota_us` / `cpu.cfs_period_us`) so it returns the effective limit
     * inside Docker or Kubernetes containers (e.g. `0.5` for a 500m CPU limit).
     * Also honours cpuset pinning (`--cpuset-cpus`); if both a quota and a cpuset
     * are set, the smaller of the two is returned. Falls back to the host's
     * physical core count when no limit is configured.
     *
     *
     * @throws Exception
     */
    public static function getCPU(): float
    {
        switch (self::getOS()) {
            case 'Linux':
                $limits = [
                    self::getCgroupCPULimit(),
                    self::getCgroupCpusetCount(),
                ];
                $limits = array_filter($limits, fn(?float $v): bool => $v !== null);

                if ($limits !== []) {
                    return min($limits);
                }

                $cpuInfo = file_get_contents('/proc/cpuinfo');
                if ($cpuInfo === false) {
                    throw new Exception('Unable to determine CPU count: /proc/cpuinfo is not readable and no cgroup limits are configured.');
                }
                preg_match_all('/^processor/m', $cpuInfo, $matches);
                $hostCores = \count($matches[0]);
                if ($hostCores === 0) {
                    throw new Exception('Unable to determine CPU count: /proc/cpuinfo contained no processor entries.');
                }

                return (float) $hostCores;
            case 'Darwin':
                $output = shell_exec('sysctl -n hw.ncpu');
                if (! \is_string($output) || ! preg_match('/\d+/', $output, $m) || (int) $m[0] <= 0) {
                    throw new Exception('Unable to determine CPU count via sysctl.');
                }
                return (float) $m[0];
            case 'Windows':
                $output = shell_exec('wmic cpu get NumberOfCores');
                if (! \is_string($output) || ! preg_match_all('/\d+/', $output, $m)) {
                    throw new Exception('Unable to determine CPU count via wmic.');
                }
                $total = array_sum(array_map('intval', $m[0]));
                if ($total <= 0) {
                    throw new Exception('Unable to determine CPU count via wmic.');
                }
                return (float) $total;
            default:
                throw new Exception(self::getOS() . ' not supported.');
        }
    }

    /**
     * Reads the cgroup CPU quota for the current process, supporting both
     * cgroup v2 and v1. Returns null when no limit is set or the files are
     * not readable.
     */
    private static function getCgroupCPULimit(): ?float
    {
        $v2 = '/sys/fs/cgroup/cpu.max';
        if (is_readable($v2)) {
            $contents = trim((string) @file_get_contents($v2));
            if ($contents !== '') {
                $parts = preg_split('/\s+/', $contents);
                if ($parts !== false && \count($parts) >= 2) {
                    [$quota, $period] = $parts;
                    if ($quota !== 'max' && is_numeric($quota) && is_numeric($period) && (float) $period > 0) {
                        return (float) $quota / (float) $period;
                    }
                }
            }
        }

        $quotaFile = '/sys/fs/cgroup/cpu/cpu.cfs_quota_us';
        $periodFile = '/sys/fs/cgroup/cpu/cpu.cfs_period_us';
        if (is_readable($quotaFile) && is_readable($periodFile)) {
            $quota = trim((string) @file_get_contents($quotaFile));
            $period = trim((string) @file_get_contents($periodFile));
            if (is_numeric($quota) && is_numeric($period) && (float) $quota > 0 && (float) $period > 0) {
                return (float) $quota / (float) $period;
            }
        }

        return null;
    }

    /**
     * Counts the CPUs allowed by the current process's cpuset cgroup
     * (e.g. `docker run --cpuset-cpus=0-1,3`). Supports cgroup v2 and v1.
     * Returns null when no cpuset is configured or the files are not readable.
     */
    private static function getCgroupCpusetCount(): ?float
    {
        foreach (['/sys/fs/cgroup/cpuset.cpus.effective', '/sys/fs/cgroup/cpuset/cpuset.cpus'] as $file) {
            if (! is_readable($file)) {
                continue;
            }
            $contents = trim((string) @file_get_contents($file));
            if ($contents === '') {
                continue;
            }

            $count = self::countCpuList($contents);
            if ($count <= 0) {
                continue;
            }

            // If the cpuset matches every online CPU, no user-visible restriction
            // is in effect (cgroup v2 always exposes the full set). Treat as null.
            $online = @file_get_contents('/sys/devices/system/cpu/online');
            if ($online !== false) {
                $onlineCount = self::countCpuList(trim($online));
                if ($onlineCount > 0 && $count >= $onlineCount) {
                    return null;
                }
            }

            return (float) $count;
        }

        return null;
    }

    /**
     * Counts CPUs in a Linux cpu list string like "0-3,5,7-8".
     */
    private static function countCpuList(string $list): int
    {
        $count = 0;
        foreach (explode(',', $list) as $range) {
            if ($range === '') {
                continue;
            }
            if (str_contains($range, '-')) {
                [$start, $end] = explode('-', $range, 2);
                if (is_numeric($start) && is_numeric($end) && (int) $start <= (int) $end) {
                    $count += ((int) $end - (int) $start) + 1;
                }
            } elseif (is_numeric($range)) {
                $count += 1;
            }
        }

        return $count;
    }

    /**
     * Helper function to read a Linux System's /proc/stat data and convert it into an array.
     *
     * @return array<int|string, array<string, mixed>>
     */
    private static function getProcStatData(): array
    {
        $data = [];

        $totalCPUExists = false;

        $cpustats = file_get_contents('/proc/stat');

        if (!$cpustats) {
            throw new Exception('Unable to read /proc/stat');
        }

        $cpus = explode("\n", $cpustats);

        // Remove non-CPU lines
        $cpus = array_filter($cpus, fn(string $cpu): bool => (bool) preg_match('/^cpu[0-999]/', $cpu));

        foreach ($cpus as $cpu) {
            $cpu = explode(' ', $cpu);

            // get CPU number
            $cpuNumber = substr($cpu[0], 3);

            if ($cpu[0] === 'cpu') {
                $totalCPUExists = true;
                $cpuNumber = 'total';
            }

            $data[$cpuNumber]['user'] = $cpu[1] ?? 0;
            $data[$cpuNumber]['nice'] = $cpu[2] ?? 0;
            $data[$cpuNumber]['system'] = $cpu[3] ?? 0;
            $data[$cpuNumber]['idle'] = $cpu[4] ?? 0;
            $data[$cpuNumber]['iowait'] = $cpu[5] ?? 0;
            $data[$cpuNumber]['irq'] = $cpu[6] ?? 0;
            $data[$cpuNumber]['softirq'] = $cpu[7] ?? 0;

            // These might not exist on older kernels.
            $data[$cpuNumber]['steal'] = $cpu[8] ?? 0;
            $data[$cpuNumber]['guest'] = $cpu[9] ?? 0;
        }

        if (! $totalCPUExists) {
            // Combine all values
            $data['total'] = [
                'user' => 0,
                'nice' => 0,
                'system' => 0,
                'idle' => 0,
                'iowait' => 0,
                'irq' => 0,
                'softirq' => 0,
                'steal' => 0,
                'guest' => 0,
            ];

            foreach ($data as $cpu) {
                $data['total']['user'] += \intval($cpu['user']);
                $data['total']['nice'] += \intval($cpu['nice']);
                $data['total']['system'] += \intval($cpu['system']);
                $data['total']['idle'] += \intval($cpu['idle']);
                $data['total']['iowait'] += \intval($cpu['iowait']);
                $data['total']['irq'] += \intval($cpu['irq']);
                $data['total']['softirq'] += \intval($cpu['softirq']);
                $data['total']['steal'] += \intval($cpu['steal']);
                $data['total']['guest'] += \intval($cpu['guest']);
            }
        }

        return $data;
    }

    /**
     * Get percentage CPU usage (between 0 and 100)
     * Reference for formula: https://stackoverflow.com/a/23376195/17300412
     *
     *
     * @throws Exception
     */
    public static function getCPUUsage(int $duration = 1): float
    {
        switch (self::getOS()) {
            case 'Linux':
                $startCpu = self::getProcStatData()['total'];
                sleep($duration);
                $endCpu = self::getProcStatData()['total'];

                $prevIdle = $startCpu['idle'] + $startCpu['iowait'];
                $idle = $endCpu['idle'] + $endCpu['iowait'];

                $prevNonIdle = $startCpu['user'] + $startCpu['nice'] + $startCpu['system'] + $startCpu['irq'] + $startCpu['softirq'] + $startCpu['steal'];
                $nonIdle = $endCpu['user'] + $endCpu['nice'] + $endCpu['system'] + $endCpu['irq'] + $endCpu['softirq'] + $endCpu['steal'];

                $prevTotal = $prevIdle + $prevNonIdle;
                $total = $idle + $nonIdle;

                $totalDiff = $total - $prevTotal;
                $idleDiff = $idle - $prevIdle;

                $percentage = ($totalDiff - $idleDiff) / $totalDiff;

                return $percentage * 100;
            default:
                throw new Exception(self::getOS() . ' not supported.');
        }
    }

    private static function getProcMemoryInfo(string $field): int
    {
        $memInfo = file_get_contents('/proc/meminfo');
        if (!$memInfo) {
            throw new Exception('Unable to read /proc/meminfo');
        }

        preg_match(\sprintf('/%s:\s+(\d+)/', $field), $memInfo, $matches);
        if (isset($matches[1])) {
            return \intval(\intval($matches[1]) / 1024);
        }
        throw new Exception("Unable to find {$field} in /proc/meminfo.");

    }

    /**
     * Returns the total amount of RAM available on the system as Megabytes.
     *
     *
     * @throws Exception
     */
    public static function getMemoryTotal(): int
    {
        return match (self::getOS()) {
            'Linux' => self::getProcMemoryInfo('MemTotal'),
            'Darwin' => \intval((\intval(shell_exec('sysctl -n hw.memsize'))) / 1024 / 1024),
            default => throw new Exception(self::getOS() . ' not supported.'),
        };
    }

    /**
     * Returns the effective memory capacity in MiB, rounded down.
     *
     * On Linux, reads cgroup v2 memory.max or cgroup v1 memory.limit_in_bytes
     * at the standard container mount paths. The result is capped at the
     * host's total RAM, including when v1 reports a large unlimited sentinel.
     * Falls back to getMemoryTotal() when no readable, finite limit is set.
     *
     * @throws Exception
     */
    public static function getMemory(): int
    {
        $total = self::getMemoryTotal();
        if (self::getOS() !== 'Linux') {
            return $total;
        }

        $limit = self::getCgroupMemoryLimit();

        return $limit === null ? $total : min($total, intdiv($limit, 1024 * 1024));
    }

    /**
     * Reads a numeric cgroup memory limit in bytes, or null when unavailable.
     */
    private static function getCgroupMemoryLimit(): ?int
    {
        foreach (['/sys/fs/cgroup/memory.max', '/sys/fs/cgroup/memory/memory.limit_in_bytes'] as $file) {
            if (! is_readable($file)) {
                continue;
            }

            $contents = trim((string) @file_get_contents($file));
            if ($contents === 'max' || $contents === '-1') {
                return null;
            }

            $limit = filter_var($contents, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($limit !== false) {
                return $limit;
            }
        }

        return null;
    }

    /**
     * Returns the total amount of Free RAM available on the system as Megabytes.
     *
     *
     * @throws Exception
     */
    public static function getMemoryFree(): int
    {
        return match (self::getOS()) {
            'Linux' => self::getProcMemoryInfo('MemFree'),
            'Darwin' => \intval(\intval(shell_exec('sysctl -n vm.page_free_count')) / 1024 / 1024),
            default => throw new Exception(self::getOS() . ' not supported.'),
        };
    }

    /**
     * Returns the total amount of Available RAM on the system as Megabytes.
     *
     *
     * @throws Exception
     */
    public static function getMemoryAvailable(): int
    {
        switch (self::getOS()) {
            case 'Linux':
                return self::getProcMemoryInfo('MemAvailable');
            case 'Darwin':
                throw new Exception(self::getOS() . ' not supported.');
            default:
                throw new Exception(self::getOS() . ' not supported.');
        }
    }

    /**
     * Returns the total amount of Disk space on the system as Megabytes.
     *
     *
     * @throws Exception
     */
    public static function getDiskTotal(string $directory = __DIR__): int
    {
        $totalSpace = disk_total_space($directory);

        if ($totalSpace === false) {
            throw new Exception('Unable to get disk space');
        }

        return \intval($totalSpace / 1024 / 1024);
    }

    /**
     * Returns the total amount of Disk space free on the system as Megabytes.
     *
     *
     * @throws Exception
     */
    public static function getDiskFree(string $directory = __DIR__): int
    {
        $totalSpace = disk_free_space($directory);

        if ($totalSpace === false) {
            throw new Exception('Unable to get free disk space');
        }

        return \intval($totalSpace / 1024 / 1024);
    }

    /**
     * Helper function to read a Linux System's /proc/diskstats data and convert it into an array.
     *
     * @return array<string, array<int, mixed>>
     */
    private static function getDiskStats(): array
    {
        // Read /proc/diskstats
        $diskStats = @file_get_contents('/proc/diskstats');

        if (!$diskStats) {
            throw new Exception('Unable to read /proc/diskstats');
        }

        // Split the data
        $diskStats = explode("\n", $diskStats);

        // Remove excess spaces
        $diskStats = array_map(fn($data): ?string => preg_replace('/\t+/', ' ', trim($data)), $diskStats);

        // Remove empty lines
        $diskStats = array_filter($diskStats, fn($data): bool => ! empty($data));

        $data = [];
        foreach ($diskStats as $disk) {
            // Breakdown the data
            $disk = explode(' ', $disk);

            $data[$disk[2]] = $disk;
        }

        return $data;
    }

    /**
     * Returns an array of all the available storage devices on the system containing
     * the current read and write usage in Megabytes.
     * There is also a ['total'] key that contains the total amount of read and write usage.
     *
     * @return array<string, array<string, mixed>>
     * @throws Exception
     */
    public static function getIOUsage(int $duration = 1): array
    {
        $diskStat = self::getDiskStats();
        sleep($duration);
        $diskStat2 = self::getDiskStats();

        $diskStat = array_filter($diskStat, function (array $disk): bool {
            foreach (self::INVALID_DISKS as $filter) {
                if (!isset($disk[2]) || !\is_string($disk[2])) {
                    return false;
                }
                if (str_contains($disk[2], $filter)) {
                    return false;
                }
            }

            return true;
        });

        $diskStat2 = array_filter($diskStat2, function (array $disk): bool {
            foreach (self::INVALID_DISKS as $filter) {
                if (!isset($disk[2]) || !\is_string($disk[2])) {
                    return false;
                }

                if (str_contains($disk[2], $filter)) {
                    return false;
                }
            }

            return true;
        });

        $stats = [];

        // Compute Delta
        foreach ($diskStat as $key => $disk) {
            $read2 = $diskStat2[$key][5];
            $read1 = $disk[5];

            $write2 = $diskStat2[$key][9];
            $write1 = $disk[9];

            $stats[$key]['read'] = (((\intval($read2) - \intval($read1)) * 512) / 1048576);

            $stats[$key]['write'] = (((\intval($write2) - \intval($write1)) * 512) / 1048576);
        }

        $stats['total']['read'] = array_sum(array_column($stats, 'read'));
        $stats['total']['write'] = array_sum(array_column($stats, 'write'));

        return $stats;
    }

    /**
     * Returns an array of all the available network interfaces on the system
     * containing the current download and upload usage in Megabytes.
     * There is also a ['total'] key that contains the total amount of download
     * and upload
     *
     * @param  int  $duration The buffer duration to fetch the data points
     * @return array<int|string, array<string, float|int>>
     *
     * @throws Exception
     */
    public static function getNetworkUsage(int $duration = 1): array
    {
        // Create a list of interfaces
        $interfaces = @scandir('/sys/class/net', SCANDIR_SORT_NONE);

        if (!$interfaces) {
            throw new Exception('Unable to read /sys/class/net');
        }

        // Remove all unwanted interfaces
        $interfaces = array_filter($interfaces, function ($interface): bool {
            foreach (self::INVALIDNETINTERFACES as $filter) {
                if (str_contains($interface, $filter)) {
                    return false;
                }
            }

            return true;
        });

        // Get the total IO Usage
        $IOUsage = [];

        foreach ($interfaces as $interface) {
            $tx1 = \intval(file_get_contents('/sys/class/net/' . $interface . '/statistics/tx_bytes'));
            $rx1 = \intval(file_get_contents('/sys/class/net/' . $interface . '/statistics/rx_bytes'));
            sleep($duration);
            $tx2 = \intval(file_get_contents('/sys/class/net/' . $interface . '/statistics/tx_bytes'));
            $rx2 = \intval(file_get_contents('/sys/class/net/' . $interface . '/statistics/rx_bytes'));

            $IOUsage[$interface]['download'] = round(($rx2 - $rx1) / 1048576, 2);
            $IOUsage[$interface]['upload'] = round(($tx2 - $tx1) / 1048576, 2);
        }

        $IOUsage['total']['download'] = array_sum(array_column($IOUsage, 'download'));
        $IOUsage['total']['upload'] = array_sum(array_column($IOUsage, 'upload'));

        return $IOUsage;
    }

    /**
     * @template TDefault of string|null
     * @param TDefault $default
     * @return ($default is null ? string|null : string)
     */
    public static function getEnv(string $name, ?string $default = null): ?string
    {
        return getenv($name) ?: $default;
    }

    /**
     * Checks if the system is running on an ARM64 architecture.
     */
    public static function isArm64(): bool
    {
        return (bool) preg_match(self::RegexARM64, self::getArch());
    }

    /**
     * Checks if the system is running on an ARMV7 architecture.
     */
    public static function isArmV7(): bool
    {
        return (bool) preg_match(self::RegexARMV7, self::getArch());
    }

    /**
     * Checks if the system is running on an ARM64 architecture.
     */
    public static function isArmV8(): bool
    {
        return (bool) preg_match(self::RegexARMV8, self::getArch());
    }

    /**
     * Checks if the system is running on an X86 architecture.
     */
    public static function isX86(): bool
    {
        return (bool) preg_match(self::RegExX86, self::getArch());
    }

    /**
     * Checks if the system is running on an PowerPC architecture.
     */
    public static function isPPC(): bool
    {
        return (bool) preg_match(self::RegExPPC, self::getArch());
    }

    /**
     * Checks if the system is the passed architecture.
     * You should pass `System::X86`, `System::PPC`, `System::ARM` or an equivalent string.
     *
     *
     * @throws Exception
     */
    public static function isArch(string $arch): bool
    {
        return match ($arch) {
            self::X86 => self::isX86(),
            self::PPC => self::isPPC(),
            self::ARM64 => self::isArm64(),
            self::ARMV7 => self::isArmV7(),
            self::ARMV8 => self::isArmV8(),
            default => throw new Exception("'{$arch}' not found."),
        };
    }
}
