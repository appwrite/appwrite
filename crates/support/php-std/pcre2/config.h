/* PCRE2 build configuration for php-std.

These are the settings of PHP 8.5's ext/pcre/pcre2lib/config.h, where the
bundled PCRE2 10.44 these sources are copied from is built: Unicode support,
the 8-bit library only, the JIT, and PCRE2's default limits. PHP's file also
includes php_config.h, which only matters to PHP's own build. */

#define SUPPORT_UNICODE 1
#define SUPPORT_PCRE2_8 1
#define SUPPORT_JIT 1

#if defined(__GNUC__) && __GNUC__ >= 4
# define PCRE2_EXP_DECL extern __attribute__ ((visibility("default")))
# define PCRE2_EXP_DEFN __attribute__ ((visibility("default")))
#endif

#define HEAP_LIMIT 20000000
#define PARENS_NEST_LIMIT 250
#define MATCH_LIMIT 10000000
#define MATCH_LIMIT_DEPTH MATCH_LIMIT
#define MAX_NAME_COUNT 10000
#define MAX_NAME_SIZE 128
#define NEWLINE_DEFAULT 2
#define LINK_SIZE 2
#define MAX_VARLOOKBEHIND 255

#define PCRE2_EXPORT
