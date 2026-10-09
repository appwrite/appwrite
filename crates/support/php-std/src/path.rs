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
