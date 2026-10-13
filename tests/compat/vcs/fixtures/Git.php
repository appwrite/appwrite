<?php

namespace Tests\Compat\Vcs\Fixtures;

/**
 * Bare repositories on disk, written with git plumbing at a fixed date so
 * the same calls make the same commits, whichever runtime made them.
 */
final class Git
{
    /** Every commit and tag the emulator writes is dated 2026-01-01T00:00:00Z. */
    public const string DATE = '1767225600 +0000';

    /**
     * Runs git and returns its exit code, stdout and stderr.
     *
     * @param array<string> $args
     * @param array<string, string> $env
     * @return array{0: int, 1: string, 2: string}
     */
    public static function run(array $args, string $stdin = '', array $env = []): array
    {
        $command = array_merge(['git'], $args);
        $environment = array_merge(getenv(), [
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_TERMINAL_PROMPT' => '0',
        ], $env);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
        if (!\is_resource($process)) {
            return [127, '', 'cannot start git'];
        }
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    /**
     * Runs git in a repository and returns stdout, or null when it fails.
     *
     * @param array<string> $args
     * @param array<string, string> $env
     */
    public static function out(string $repo, array $args, string $stdin = '', array $env = []): ?string
    {
        [$code, $stdout] = self::run(array_merge(['-C', $repo], $args), $stdin, $env);

        return $code === 0 ? $stdout : null;
    }

    public static function init(string $path, string $branch): void
    {
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0777, true);
        }
        self::run(['init', '--bare', '-q', '-b', $branch, $path]);
        self::out($path, ['config', 'receive.denyNonFastForwards', 'false']);
    }

    /**
     * The commit a ref, branch, tag or hash names, or null.
     */
    public static function resolve(string $repo, string $ref): ?string
    {
        if ($ref === '' || str_starts_with($ref, '-')) {
            return null;
        }
        $out = self::out($repo, ['rev-parse', '--verify', '-q', $ref . '^{commit}']);

        return $out === null ? null : trim($out);
    }

    /**
     * Branch names and their tips, in name order.
     *
     * @return array<string, string>
     */
    public static function branches(string $repo): array
    {
        return self::refs($repo, 'refs/heads/');
    }

    /**
     * Tag names and the commits they point at, in name order.
     *
     * @return array<string, string>
     */
    public static function tags(string $repo): array
    {
        $out = self::out($repo, ['for-each-ref', '--sort=refname', '--format=%(refname)%00%(*objectname)%00%(objectname)', 'refs/tags/']) ?? '';
        $tags = [];
        foreach (array_filter(explode("\n", $out)) as $line) {
            [$name, $peeled, $object] = explode("\0", $line) + ['', '', ''];
            $tags[substr($name, \strlen('refs/tags/'))] = $peeled !== '' ? $peeled : $object;
        }

        return $tags;
    }

    /**
     * @return array<string, string>
     */
    private static function refs(string $repo, string $prefix): array
    {
        $out = self::out($repo, ['for-each-ref', '--sort=refname', '--format=%(refname)%00%(objectname)', $prefix]) ?? '';
        $refs = [];
        foreach (array_filter(explode("\n", $out)) as $line) {
            [$name, $sha] = explode("\0", $line) + ['', ''];
            $refs[substr($name, \strlen($prefix))] = $sha;
        }

        return $refs;
    }

    public static function setRef(string $repo, string $ref, string $sha): void
    {
        self::out($repo, ['update-ref', $ref, $sha]);
    }

    public static function deleteRef(string $repo, string $ref): void
    {
        self::out($repo, ['update-ref', '-d', $ref]);
    }

    /**
     * Writes files onto a branch (null deletes one) as a new commit, from
     * the branch's tip, or from $base when the branch does not exist yet.
     *
     * @param array<string, string|null> $files
     * @param array{name: string, email: string} $author
     */
    public static function commit(string $repo, string $branch, array $files, string $message, array $author, ?string $base = null): ?string
    {
        $parent = self::resolve($repo, 'refs/heads/' . $branch) ?? ($base !== null ? self::resolve($repo, $base) : null);
        $index = tempnam(sys_get_temp_dir(), 'vcs-index-');
        if ($index === false) {
            return null;
        }
        unlink($index);
        $env = ['GIT_INDEX_FILE' => $index];
        if ($parent !== null) {
            self::out($repo, ['read-tree', $parent], '', $env);
        }
        foreach ($files as $path => $content) {
            if ($content === null) {
                self::out($repo, ['update-index', '--force-remove', '--', $path], '', $env);
                continue;
            }
            $blob = trim((string) self::out($repo, ['hash-object', '-w', '--stdin'], $content));
            self::out($repo, ['update-index', '--add', '--cacheinfo', '100644,' . $blob . ',' . $path], '', $env);
        }
        $tree = trim((string) self::out($repo, ['write-tree'], '', $env));
        @unlink($index);
        $args = ['commit-tree', $tree, '-m', $message];
        if ($parent !== null) {
            $args = ['commit-tree', $tree, '-p', $parent, '-m', $message];
        }
        $sha = self::out($repo, $args, '', self::identity($author));
        if ($sha === null) {
            return null;
        }
        $sha = trim($sha);
        self::setRef($repo, 'refs/heads/' . $branch, $sha);

        return $sha;
    }

    /**
     * An annotated tag object on $target, returning the tag object's hash.
     *
     * @param array{name: string, email: string} $tagger
     */
    public static function annotatedTag(string $repo, string $name, string $target, string $message, array $tagger): ?string
    {
        $tag = "object {$target}\ntype commit\ntag {$name}\ntagger {$tagger['name']} <{$tagger['email']}> " . self::DATE . "\n\n{$message}\n";
        $sha = self::out($repo, ['mktag'], $tag);
        if ($sha === null) {
            return null;
        }
        $sha = trim($sha);
        self::setRef($repo, 'refs/tags/' . $name, $sha);

        return $sha;
    }

    /**
     * @param array{name: string, email: string} $person
     * @return array<string, string>
     */
    public static function identity(array $person): array
    {
        return [
            'GIT_AUTHOR_NAME' => $person['name'],
            'GIT_AUTHOR_EMAIL' => $person['email'],
            'GIT_AUTHOR_DATE' => self::DATE,
            'GIT_COMMITTER_NAME' => $person['name'],
            'GIT_COMMITTER_EMAIL' => $person['email'],
            'GIT_COMMITTER_DATE' => self::DATE,
        ];
    }

    /**
     * Entries of a tree (one directory, or every entry below it), each
     * [mode, type, sha, size, path].
     *
     * @return array<array{mode: string, type: string, sha: string, size: int, path: string}>|null
     */
    public static function tree(string $repo, string $commit, string $path = '', bool $recursive = false): ?array
    {
        $treeish = $path === '' ? $commit . '^{tree}' : $commit . ':' . $path;
        $type = trim((string) self::out($repo, ['cat-file', '-t', $treeish]));
        if ($type !== 'tree') {
            return null;
        }
        $args = ['ls-tree', '-l', '-z'];
        if ($recursive) {
            $args[] = '-r';
            $args[] = '-t';
        }
        $args[] = $treeish;
        $out = self::out($repo, $args) ?? '';
        $entries = [];
        foreach (array_filter(explode("\0", $out)) as $line) {
            [$meta, $name] = explode("\t", $line, 2);
            [$mode, $kind, $sha, $size] = preg_split('/\s+/', trim($meta)) ?: ['', '', '', ''];
            $entries[] = [
                'mode' => $mode,
                'type' => $kind,
                'sha' => $sha,
                'size' => $size === '-' ? 0 : (int) $size,
                'path' => $name,
            ];
        }

        return $entries;
    }

    /**
     * A file at a commit: its blob hash and content, or null.
     *
     * @return array{sha: string, content: string}|null
     */
    public static function blob(string $repo, string $commit, string $path): ?array
    {
        $object = $commit . ':' . $path;
        if (trim((string) self::out($repo, ['cat-file', '-t', $object])) !== 'blob') {
            return null;
        }
        $sha = trim((string) self::out($repo, ['rev-parse', $object]));

        return ['sha' => $sha, 'content' => (string) self::out($repo, ['cat-file', 'blob', $sha])];
    }

    /**
     * A commit's fields.
     *
     * @return array{sha: string, tree: string, parents: array<string>, author: string, email: string, message: string}|null
     */
    public static function info(string $repo, string $sha): ?array
    {
        $out = self::out($repo, ['show', '-s', '--format=%H%x00%T%x00%P%x00%an%x00%ae%x00%B', $sha . '^{commit}']);
        if ($out === null) {
            return null;
        }
        [$hash, $tree, $parents, $author, $email, $message] = explode("\0", $out, 6) + ['', '', '', '', '', ''];

        return [
            'sha' => $hash,
            'tree' => $tree,
            'parents' => array_values(array_filter(explode(' ', $parents))),
            'author' => $author,
            'email' => $email,
            'message' => rtrim($message, "\n"),
        ];
    }

    /**
     * Commit hashes reachable from $ref, newest first.
     *
     * @return array<string>
     */
    public static function log(string $repo, string $ref, int $limit = 100): array
    {
        $out = self::out($repo, ['rev-list', '--max-count=' . $limit, $ref]) ?? '';

        return array_values(array_filter(explode("\n", $out)));
    }

    /**
     * Files changed from the merge base of $base and $head to $head.
     *
     * @return array<array{status: string, path: string, old: string}>
     */
    public static function changes(string $repo, string $base, string $head): array
    {
        $out = self::out($repo, ['diff', '--name-status', '-z', $base . '...' . $head]) ?? '';
        $parts = explode("\0", rtrim($out, "\0"));
        $changes = [];
        for ($i = 0; $i + 1 < \count($parts); $i += 2) {
            $changes[] = ['status' => $parts[$i], 'path' => $parts[$i + 1], 'old' => $parts[$i + 1]];
        }

        return $changes;
    }

    /**
     * Files a commit touched against its first parent.
     *
     * @return array{added: array<string>, removed: array<string>, modified: array<string>}
     */
    public static function touched(string $repo, string $sha): array
    {
        $out = self::out($repo, ['diff-tree', '--no-commit-id', '--root', '-r', '--name-status', '-z', $sha]) ?? '';
        $parts = explode("\0", rtrim($out, "\0"));
        $files = ['added' => [], 'removed' => [], 'modified' => []];
        for ($i = 0; $i + 1 < \count($parts); $i += 2) {
            $key = match ($parts[$i][0] ?? '') {
                'A' => 'added',
                'D' => 'removed',
                default => 'modified',
            };
            $files[$key][] = $parts[$i + 1];
        }

        return $files;
    }
}
