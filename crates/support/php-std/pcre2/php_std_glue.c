/* php-std's one addition to the PCRE2 sources: whether a code point is in
\pL or \pN by PCRE2's own Unicode tables (Unicode 15.0.0), so that
FILTER_VALIDATE_EMAIL's hand-written check classifies characters exactly
like the regular expression PHP runs. */

#include "config.h"
#include "pcre2_internal.h"

int php_std_pcre2_is_letter_or_number(uint32_t c)
{
  unsigned int type;
  if (c > MAX_UTF_CODE_POINT) return 0;
  type = PRIV(ucp_gentype)[UCD_CHARTYPE(c)];
  return type == ucp_L || type == ucp_N;
}
