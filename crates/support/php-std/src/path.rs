//! Path functions.

/// PHP `dirname($path)` (one level, Unix separators), as `zend_dirname`.
pub fn dirname(path: &str) -> String {
    let b = path.as_bytes();
    if b.is_empty() {
        return String::new();
    }
    let mut end = b.len() as isize - 1;
    while end >= 0 && b[end as usize] == b'/' {
        end -= 1;
    }
    if end < 0 {
        return "/".into();
    }
    while end >= 0 && b[end as usize] != b'/' {
        end -= 1;
    }
    if end < 0 {
        return ".".into();
    }
    while end >= 0 && b[end as usize] == b'/' {
        end -= 1;
    }
    if end < 0 {
        return "/".into();
    }
    String::from_utf8_lossy(&b[..=end as usize]).into_owned()
}

/// PHP `basename($path, $suffix)` (Unix separators), as `php_basename`: the
/// last component, trailing slashes ignored, with `suffix` removed when the
/// component ends with it and is longer than it.
///
/// ```
/// use php_std::path::basename;
///
/// assert_eq!(basename(b"/tmp/report.pdf/", b""), b"report.pdf");
/// assert_eq!(basename(b"/tmp/report.pdf", b".pdf"), b"report");
/// assert_eq!(basename(b"/", b""), b"");
/// ```
pub fn basename<'a>(path: &'a [u8], suffix: &[u8]) -> &'a [u8] {
    let (mut start, mut end) = (0, 0);
    let mut inside = false;
    for (i, &b) in path.iter().enumerate() {
        if b == b'/' {
            if inside {
                inside = false;
                end = i;
            }
        } else if !inside {
            start = i;
            inside = true;
        }
    }
    if inside {
        end = path.len();
    }
    let name = &path[start..end];
    if !suffix.is_empty() && suffix.len() < name.len() && name.ends_with(suffix) {
        return &name[..name.len() - suffix.len()];
    }
    name
}

/// `MAXPATHLEN` on Linux, where PHP runs.
const MAXPATHLEN: usize = 4096;

/// `expand_filepath()`: the absolute path PHP opens for `path`. A relative
/// path is resolved against the working directory, then `.` and `..`
/// components and repeated slashes are removed lexically (a trailing slash
/// stays),
/// without resolving symbolic links. `None` for an empty path or one longer
/// than `MAXPATHLEN`.
///
/// ```
/// use php_std::path::expand_filepath;
///
/// assert_eq!(expand_filepath(b"/tmp/a/../b//c/."), Some(b"/tmp/b/c".to_vec()));
/// assert_eq!(expand_filepath(b"/.."), Some(b"/".to_vec()));
/// ```
pub fn expand_filepath(path: &[u8]) -> Option<Vec<u8>> {
    use std::os::unix::ffi::OsStrExt;
    if path.is_empty() || path.len() >= MAXPATHLEN - 1 {
        return None;
    }
    let mut full = Vec::with_capacity(path.len());
    if path[0] != b'/'
        && let Ok(cwd) = std::env::current_dir()
    {
        full.extend_from_slice(cwd.as_os_str().as_bytes());
        full.push(b'/');
    }
    full.extend_from_slice(path);
    let mut parts: Vec<&[u8]> = Vec::new();
    for part in full.split(|b| *b == b'/') {
        match part {
            b"" | b"." => {}
            b".." => {
                parts.pop();
            }
            part => parts.push(part),
        }
    }
    let mut out = Vec::with_capacity(full.len());
    for part in parts {
        out.push(b'/');
        out.extend_from_slice(part);
    }
    if out.is_empty() {
        out.push(b'/');
    }
    // `virtual_file_ex()` keeps a trailing slash (`CWD_EXPAND`), so a file
    // named with one does not open.
    if path.ends_with(b"/") && !out.ends_with(b"/") {
        out.push(b'/');
    }
    (out.len() < MAXPATHLEN).then_some(out)
}

/// `file_get_contents($path)` for a plain file path: the bytes of the file
/// at [`expand_filepath`]`(path)`, or `None` where PHP returns `false` (no
/// such file, a directory, no permission). Paths with a NUL byte are
/// `None`; PHP functions raise a `\ValueError` for them before opening.
pub fn file_get_contents(path: &[u8]) -> Option<Vec<u8>> {
    use std::os::unix::ffi::OsStrExt;
    if path.contains(&0) {
        return None;
    }
    let path = expand_filepath(path)?;
    std::fs::read(std::ffi::OsStr::from_bytes(&path)).ok()
}

#[cfg(test)]
mod expand_tests {
    use super::expand_filepath;

    #[test]
    fn lexical() {
        assert_eq!(expand_filepath(b"/a/b/../../.."), Some(b"/".to_vec()));
        assert_eq!(expand_filepath(b"/a/./b/"), Some(b"/a/b/".to_vec()));
        assert_eq!(expand_filepath(b""), None);
        assert_eq!(expand_filepath(&[b'a'; 5000]), None);
        assert!(expand_filepath(b"x").unwrap().ends_with(b"/x"));
    }
}

#[cfg(test)]
mod tests {
    use super::dirname;

    #[test]
    fn like_php() {
        for (input, expected) in [
            ("/tmp/x.lock", "/tmp"),
            ("x.lock", "."),
            ("/x", "/"),
            ("/", "/"),
            ("a/b/", "a"),
            ("a//b", "a"),
            ("", ""),
            (".", "."),
            ("/a/b/../c", "/a/b/.."),
        ] {
            assert_eq!(dirname(input), expected, "{input}");
        }
    }
}
