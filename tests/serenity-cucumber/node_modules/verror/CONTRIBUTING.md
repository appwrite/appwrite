# Contributing

This repository uses GitHub pull requests for code review.

See the [Joyent Engineering
Guidelines](https://github.com/joyent/eng/blob/master/docs/index.md) for general
best practices expected in this repository.

Contributions should be "make prepush" clean.  The "prepush" target runs the
"check" target, which requires these separate tools:

* https://github.com/joyent/jsstyle
* https://github.com/joyent/javascriptlint

If you're changing something non-trivial or user-facing, you may want to submit
an issue first.
