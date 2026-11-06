# Changelog

## 0.2.0

- Expanded operating-system coverage: iPadOS, tvOS, watchOS, HarmonyOS,
  OpenHarmony, Fire OS, webOS, Sailfish, BlackBerry, PlayStation, Nintendo,
  and popular Linux distributions (Debian, Fedora, Arch Linux, Mint, and
  more).
- Expanded browser coverage: Opera Mobile, Brave, Vivaldi, Yandex Browser,
  UC Browser, DuckDuckGo, QQ Browser, Coc Coc, Whale, Huawei Browser, Amazon
  Silk, and Firefox Focus, all resolved ahead of generic Chrome and WebView
  rules.
- Expanded HTTP-library coverage: Python urllib, aiohttp, Go-http-client,
  Node Fetch, Axios, HTTPie, Apache HTTP Client, Java, and more.
- Expanded device-brand coverage: Realme, Asus, Tecno, Infinix, Honor, HTC,
  Lenovo, ZTE, TCL, Meizu, Nothing, Fairphone, Alcatel, and LG/Sony smart
  TVs.
- Expanded crawler coverage: PerplexityBot, Google Extended, GoogleOther,
  Meta External Agent, CCBot, YouBot, Yahoo Slurp, SeznamBot, Pinterest,
  and more, with narrowed matching so browsers such as Sogou and the
  Pinterest app are not misclassified as bots.
- Short-name codes stay aligned with Matomo DeviceDetector so the added
  categories match its reference output.

## 0.1.0

- Added lazy, memoized operating-system, client, device, and bot detection.
- Added typed result objects with consistent nullable unknown values and nested
  serialization.
- Added common browser, mobile, library, device, and crawler detection without
  runtime dependencies or data files.
- Added differential tests against Matomo DeviceDetector 6.4.
- Added a side-by-side parser benchmark.
