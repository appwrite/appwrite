(function (window) {
    "use strict";

    window.ls.container.set('date', function () {
        function format (format, timestamp) {
            //  discuss at: http://locutus.io/php/date/
            // original by: Carlos R. L. Rodrigues (http://www.jsfromhell.com)
            // original by: gettimeofday
            //    parts by: Peter-Paul Koch (http://www.quirksmode.org/js/beat.html)
            // improved by: Kevin van Zonneveld (http://kvz.io)
            // improved by: MeEtc (http://yass.meetcweb.com)
            // improved by: Brad Touesnard
            // improved by: Tim Wiel
            // improved by: Bryan Elliott
            // improved by: David Randall
            // improved by: Theriault (https://github.com/Theriault)
            // improved by: Theriault (https://github.com/Theriault)
            // improved by: Brett Zamir (http://brett-zamir.me)
            // improved by: Theriault (https://github.com/Theriault)
            // improved by: Thomas Beaucourt (http://www.webapp.fr)
            // improved by: JT
            // improved by: Theriault (https://github.com/Theriault)
            // improved by: Rafał Kukawski (http://blog.kukawski.pl)
            // improved by: Theriault (https://github.com/Theriault)
            //    input by: Brett Zamir (http://brett-zamir.me)
            //    input by: majak
            //    input by: Alex
            //    input by: Martin
            //    input by: Alex Wilson
            //    input by: Haravikk
            // bugfixed by: Kevin van Zonneveld (http://kvz.io)
            // bugfixed by: majak
            // bugfixed by: Kevin van Zonneveld (http://kvz.io)
            // bugfixed by: Brett Zamir (http://brett-zamir.me)
            // bugfixed by: omid (http://locutus.io/php/380:380#comment_137122)
            // bugfixed by: Chris (http://www.devotis.nl/)
            //      note 1: Uses global: locutus to store the default timezone
            //      note 1: Although the function potentially allows timezone info
            //      note 1: (see notes), it currently does not set
            //      note 1: per a timezone specified by date_default_timezone_set(). Implementers might use
            //      note 1: $locutus.currentTimezoneOffset and
            //      note 1: $locutus.currentTimezoneDST set by that function
            //      note 1: in order to adjust the dates in this function
            //      note 1: (or our other date functions!) accordingly
            //   example 1: date('H:m:s \\m \\i\\s \\m\\o\\n\\t\\h', 1062402400)
            //   returns 1: '07:09:40 m is month'
            //   example 2: date('F j, Y, g:i a', 1062462400)
            //   returns 2: 'September 2, 2003, 12:26 am'
            //   example 3: date('Y W o', 1062462400)
            //   returns 3: '2003 36 2003'
            //   example 4: var $x = date('Y m d', (new Date()).getTime() / 1000)
            //   example 4: $x = $x + ''
            //   example 4: var $result = $x.length // 2009 01 09
            //   returns 4: 10
            //   example 5: date('W', 1104534000)
            //   returns 5: '52'
            //   example 6: date('B t', 1104534000)
            //   returns 6: '999 31'
            //   example 7: date('W U', 1293750000.82); // 2010-12-31
            //   returns 7: '52 1293750000'
            //   example 8: date('W', 1293836400); // 2011-01-01
            //   returns 8: '52'
            //   example 9: date('W Y-m-d', 1293974054); // 2011-01-02
            //   returns 9: '52 2011-01-02'
            //        test: skip-1 skip-2 skip-5

            var jsdate, f
            // Keep this here (works, but for code commented-out below for file size reasons)
            // var tal= [];
            var txtWords = [
                'Sun', 'Mon', 'Tues', 'Wednes', 'Thurs', 'Fri', 'Satur',
                'January', 'February', 'March', 'April', 'May', 'June',
                'July', 'August', 'September', 'October', 'November', 'December'
            ]
            // trailing backslash -> (dropped)
            // a backslash followed by any character (including backslash) -> the character
            // empty string -> empty string
            var formatChr = /\\?(.?)/gi
            var formatChrCb = function (t, s) {
                return f[t] ? f[t]() : s
            }
            var _pad = function (n, c) {
                n = String(n)
                while (n.length < c) {
                    n = '0' + n
                }
                return n
            }
            f = {
                // Day
                d: function () {
                    // Day of month w/leading 0; 01..31
                    return _pad(f.j(), 2)
                },
                D: function () {
                    // Shorthand day name; Mon...Sun
                    return f.l()
                        .slice(0, 3)
                },
                j: function () {
                    // Day of month; 1..31
                    return jsdate.getDate()
                },
                l: function () {
                    // Full day name; Monday...Sunday
                    return txtWords[f.w()] + 'day'
                },
                N: function () {
                    // ISO-8601 day of week; 1[Mon]..7[Sun]
                    return f.w() || 7
                },
                S: function () {
                    // Ordinal suffix for day of month; st, nd, rd, th
                    var j = f.j()
                    var i = j % 10
                    if (i <= 3 && parseInt((j % 100) / 10, 10) === 1) {
                        i = 0
                    }
                    return ['st', 'nd', 'rd'][i - 1] || 'th'
                },
                w: function () {
                    // Day of week; 0[Sun]..6[Sat]
                    return jsdate.getDay()
                },
                z: function () {
                    // Day of year; 0..365
                    var a = new Date(f.Y(), f.n() - 1, f.j())
                    var b = new Date(f.Y(), 0, 1)
                    return Math.round((a - b) / 864e5)
                },

                // Week
                W: function () {
                    // ISO-8601 week number
                    var a = new Date(f.Y(), f.n() - 1, f.j() - f.N() + 3)
                    var b = new Date(a.getFullYear(), 0, 4)
                    return _pad(1 + Math.round((a - b) / 864e5 / 7), 2)
                },

                // Month
                F: function () {
                    // Full month name; January...December
                    return txtWords[6 + f.n()]
                },
                m: function () {
                    // Month w/leading 0; 01...12
                    return _pad(f.n(), 2)
                },
                M: function () {
                    // Shorthand month name; Jan...Dec
                    return f.F()
                        .slice(0, 3)
                },
                n: function () {
                    // Month; 1...12
                    return jsdate.getMonth() + 1
                },
                t: function () {
                    // Days in month; 28...31
                    return (new Date(f.Y(), f.n(), 0))
                        .getDate()
                },

                // Year
                L: function () {
                    // Is leap year?; 0 or 1
                    var j = f.Y()
                    return j % 4 === 0 & j % 100 !== 0 | j % 400 === 0
                },
                o: function () {
                    // ISO-8601 year
                    var n = f.n()
                    var W = f.W()
                    var Y = f.Y()
                    return Y + (n === 12 && W < 9 ? 1 : n === 1 && W > 9 ? -1 : 0)
                },
                Y: function () {
                    // Full year; e.g. 1980...2010
                    return jsdate.getFullYear()
                },
                y: function () {
                    // Last two digits of year; 00...99
                    return f.Y()
                        .toString()
                        .slice(-2)
                },

                // Time
                a: function () {
                    // am or pm
                    return jsdate.getHours() > 11 ? 'pm' : 'am'
                },
                A: function () {
                    // AM or PM
                    return f.a()
                        .toUpperCase()
                },
                B: function () {
                    // Swatch Internet time; 000..999
                    var H = jsdate.getUTCHours() * 36e2
                    // Hours
                    var i = jsdate.getUTCMinutes() * 60
                    // Minutes
                    // Seconds
                    var s = jsdate.getUTCSeconds()
                    return _pad(Math.floor((H + i + s + 36e2) / 86.4) % 1e3, 3)
                },
                g: function () {
                    // 12-Hours; 1..12
                    return f.G() % 12 || 12
                },
                G: function () {
                    // 24-Hours; 0..23
                    return jsdate.getHours()
                },
                h: function () {
                    // 12-Hours w/leading 0; 01..12
                    return _pad(f.g(), 2)
                },
                H: function () {
                    // 24-Hours w/leading 0; 00..23
                    return _pad(f.G(), 2)
                },
                i: function () {
                    // Minutes w/leading 0; 00..59
                    return _pad(jsdate.getMinutes(), 2)
                },
                s: function () {
                    // Seconds w/leading 0; 00..59
                    return _pad(jsdate.getSeconds(), 2)
                },
                u: function () {
                    // Microseconds; 000000-999000
                    return _pad(jsdate.getMilliseconds() * 1000, 6)
                },

                // Timezone
                e: function () {
                    // Timezone identifier; e.g. Atlantic/Azores, ...
                    // The following works, but requires inclusion of the very large
                    // timezone_abbreviations_list() function.
                    /*              return that.date_default_timezone_get();
                     */
                    var msg = 'Not supported (see source code of date() for timezone on how to add support)'
                    throw new Error(msg)
                },
                I: function () {
                    // DST observed?; 0 or 1
                    // Compares Jan 1 minus Jan 1 UTC to Jul 1 minus Jul 1 UTC.
                    // If they are not equal, then DST is observed.
                    var a = new Date(f.Y(), 0)
                    // Jan 1
                    var c = Date.UTC(f.Y(), 0)
                    // Jan 1 UTC
                    var b = new Date(f.Y(), 6)
                    // Jul 1
                    // Jul 1 UTC
                    var d = Date.UTC(f.Y(), 6)
                    return ((a - c) !== (b - d)) ? 1 : 0
                },
                O: function () {
                    // Difference to GMT in hour format; e.g. +0200
                    var tzo = jsdate.getTimezoneOffset()
                    var a = Math.abs(tzo)
                    return (tzo > 0 ? '-' : '+') + _pad(Math.floor(a / 60) * 100 + a % 60, 4)
                },
                P: function () {
                    // Difference to GMT w/colon; e.g. +02:00
                    var O = f.O()
                    return (O.substr(0, 3) + ':' + O.substr(3, 2))
                },
                T: function () {
                    // The following works, but requires inclusion of the very
                    // large timezone_abbreviations_list() function.
                    /*              var abbr, i, os, _default;
                     if (!tal.length) {
                     tal = that.timezone_abbreviations_list();
                     }
                     if ($locutus && $locutus.default_timezone) {
                     _default = $locutus.default_timezone;
                     for (abbr in tal) {
                     for (i = 0; i < tal[abbr].length; i++) {
                     if (tal[abbr][i].timezone_id === _default) {
                     return abbr.toUpperCase();
                     }
                     }
                     }
                     }
                     for (abbr in tal) {
                     for (i = 0; i < tal[abbr].length; i++) {
                     os = -jsdate.getTimezoneOffset() * 60;
                     if (tal[abbr][i].offset === os) {
                     return abbr.toUpperCase();
                     }
                     }
                     }
                     */
                    return 'UTC'
                },
                Z: function () {
                    // Timezone offset in seconds (-43200...50400)
                    return -jsdate.getTimezoneOffset() * 60
                },

                // Full Date/Time
                c: function () {
                    // ISO-8601 date.
                    return 'Y-m-d\\TH:i:sP'.replace(formatChr, formatChrCb)
                },
                r: function () {
                    // RFC 2822
                    return 'D, d M Y H:i:s O'.replace(formatChr, formatChrCb)
                },
                U: function () {
                    // Seconds since UNIX epoch
                    return jsdate / 1000 | 0
                }
            }

            var _date = function (format, timestamp) {
                jsdate = (timestamp === undefined ? new Date() // Not provided
                        : (timestamp instanceof Date) ? new Date(timestamp) // JS Date()
                            : new Date(timestamp * 1000) // Unix timestamp (auto-convert to int)
                )
                return format.replace(formatChr, formatChrCb)
            }

            return _date(format, timestamp)
        }

        function strtotime (text, now) {
            //  discuss at: http://locutus.io/php/strtotime/
            // original by: Caio Ariede (http://caioariede.com)
            // improved by: Kevin van Zonneveld (http://kvz.io)
            // improved by: Caio Ariede (http://caioariede.com)
            // improved by: A. Matías Quezada (http://amatiasq.com)
            // improved by: preuter
            // improved by: Brett Zamir (http://brett-zamir.me)
            // improved by: Mirko Faber
            //    input by: David
            // bugfixed by: Wagner B. Soares
            // bugfixed by: Artur Tchernychev
            // bugfixed by: Stephan Bösch-Plepelits (http://github.com/plepe)
            //      note 1: Examples all have a fixed timestamp to prevent
            //      note 1: tests to fail because of variable time(zones)
            //   example 1: strtotime('+1 day', 1129633200)
            //   returns 1: 1129719600
            //   example 2: strtotime('+1 week 2 days 4 hours 2 seconds', 1129633200)
            //   returns 2: 1130425202
            //   example 3: strtotime('last month', 1129633200)
            //   returns 3: 1127041200
            //   example 4: strtotime('2009-05-04 08:30:00 GMT')
            //   returns 4: 1241425800
            //   example 5: strtotime('2009-05-04 08:30:00+00')
            //   returns 5: 1241425800
            //   example 6: strtotime('2009-05-04 08:30:00+02:00')
            //   returns 6: 1241418600
            //   example 7: strtotime('2009-05-04T08:30:00Z')
            //   returns 7: 1241425800
            var parsed
            var match
            var today
            var year
            var date
            var days
            var ranges
            var len
            var times
            var regex
            var i
            var fail = false
            if (!text) {
                return fail
            }
            // Unecessary spaces
            text = text.replace(/^\s+|\s+$/g, '')
                .replace(/\s{2,}/g, ' ')
                .replace(/[\t\r\n]/g, '')
                .toLowerCase()
            // in contrast to php, js Date.parse function interprets:
            // dates given as yyyy-mm-dd as in timezone: UTC,
            // dates with "." or "-" as MDY instead of DMY
            // dates with two-digit years differently
            // etc...etc...
            // ...therefore we manually parse lots of common date formats
            var pattern = new RegExp([
                '^(\\d{1,4})',
                '([\\-\\.\\/:])',
                '(\\d{1,2})',
                '([\\-\\.\\/:])',
                '(\\d{1,4})',
                '(?:\\s(\\d{1,2}):(\\d{2})?:?(\\d{2})?)?',
                '(?:\\s([A-Z]+)?)?$'
            ].join(''))
            match = text.match(pattern)
            if (match && match[2] === match[4]) {
                if (match[1] > 1901) {
                    switch (match[2]) {
                        case '-':
                            // YYYY-M-D
                            if (match[3] > 12 || match[5] > 31) {
                                return fail
                            }
                            return new Date(match[1], parseInt(match[3], 10) - 1, match[5],
                                match[6] || 0, match[7] || 0, match[8] || 0, match[9] || 0) / 1000
                        case '.':
                            // YYYY.M.D is not parsed by strtotime()
                            return fail
                        case '/':
                            // YYYY/M/D
                            if (match[3] > 12 || match[5] > 31) {
                                return fail
                            }
                            return new Date(match[1], parseInt(match[3], 10) - 1, match[5],
                                match[6] || 0, match[7] || 0, match[8] || 0, match[9] || 0) / 1000
                    }
                } else if (match[5] > 1901) {
                    switch (match[2]) {
                        case '-':
                            // D-M-YYYY
                            if (match[3] > 12 || match[1] > 31) {
                                return fail
                            }
                            return new Date(match[5], parseInt(match[3], 10) - 1, match[1],
                                match[6] || 0, match[7] || 0, match[8] || 0, match[9] || 0) / 1000
                        case '.':
                            // D.M.YYYY
                            if (match[3] > 12 || match[1] > 31) {
                                return fail
                            }
                            return new Date(match[5], parseInt(match[3], 10) - 1, match[1],
                                match[6] || 0, match[7] || 0, match[8] || 0, match[9] || 0) / 1000
                        case '/':
                            // M/D/YYYY
                            if (match[1] > 12 || match[3] > 31) {
                                return fail
                            }
                            return new Date(match[5], parseInt(match[1], 10) - 1, match[3],
                                match[6] || 0, match[7] || 0, match[8] || 0, match[9] || 0) / 1000
                    }
                } else {
                    switch (match[2]) {
                        case '-':
                            // YY-M-D
                            if (match[3] > 12 || match[5] > 31 || (match[1] < 70 && match[1] > 38)) {
                                return fail
                            }
                            year = match[1] >= 0 && match[1] <= 38 ? +match[1] + 2000 : match[1]
                            return new Date(year, parseInt(match[3], 10) - 1, match[5],
                                match[6] || 0, match[7] || 0, match[8] || 0, match[9] || 0) / 1000
                        case '.':
                            // D.M.YY or H.MM.SS
                            if (match[5] >= 70) {
                                // D.M.YY
                                if (match[3] > 12 || match[1] > 31) {
                                    return fail
                                }
                                return new Date(match[5], parseInt(match[3], 10) - 1, match[1],
                                    match[6] || 0, match[7] || 0, match[8] || 0, match[9] || 0) / 1000
                            }
                            if (match[5] < 60 && !match[6]) {
                                // H.MM.SS
                                if (match[1] > 23 || match[3] > 59) {
                                    return fail
                                }
                                today = new Date()
                                return new Date(today.getFullYear(), today.getMonth(), today.getDate(),
                                    match[1] || 0, match[3] || 0, match[5] || 0, match[9] || 0) / 1000
                            }
                            // invalid format, cannot be parsed
                            return fail
                        case '/':
                            // M/D/YY
                            if (match[1] > 12 || match[3] > 31 || (match[5] < 70 && match[5] > 38)) {
                                return fail
                            }
                            year = match[5] >= 0 && match[5] <= 38 ? +match[5] + 2000 : match[5]
                            return new Date(year, parseInt(match[1], 10) - 1, match[3],
                                match[6] || 0, match[7] || 0, match[8] || 0, match[9] || 0) / 1000
                        case ':':
                            // HH:MM:SS
                            if (match[1] > 23 || match[3] > 59 || match[5] > 59) {
                                return fail
                            }
                            today = new Date()
                            return new Date(today.getFullYear(), today.getMonth(), today.getDate(),
                                match[1] || 0, match[3] || 0, match[5] || 0) / 1000
                    }
                }
            }
            // other formats and "now" should be parsed by Date.parse()
            if (text === 'now') {
                return now === null || isNaN(now)
                    ? new Date().getTime() / 1000 | 0
                    : now | 0
            }
            if (!isNaN(parsed = Date.parse(text))) {
                return parsed / 1000 | 0
            }
            // Browsers !== Chrome have problems parsing ISO 8601 date strings, as they do
            // not accept lower case characters, space, or shortened time zones.
            // Therefore, fix these problems and try again.
            // Examples:
            //   2015-04-15 20:33:59+02
            //   2015-04-15 20:33:59z
            //   2015-04-15t20:33:59+02:00
            pattern = new RegExp([
                '^([0-9]{4}-[0-9]{2}-[0-9]{2})',
                '[ t]',
                '([0-9]{2}:[0-9]{2}:[0-9]{2}(\\.[0-9]+)?)',
                '([\\+-][0-9]{2}(:[0-9]{2})?|z)'
            ].join(''))
            match = text.match(pattern)
            if (match) {
                // @todo: time zone information
                if (match[4] === 'z') {
                    match[4] = 'Z'
                } else if (match[4].match(/^([+-][0-9]{2})$/)) {
                    match[4] = match[4] + ':00'
                }
                if (!isNaN(parsed = Date.parse(match[1] + 'T' + match[2] + match[4]))) {
                    return parsed / 1000 | 0
                }
            }
            date = now ? new Date(now * 1000) : new Date()
            days = {
                'sun': 0,
                'mon': 1,
                'tue': 2,
                'wed': 3,
                'thu': 4,
                'fri': 5,
                'sat': 6
            }
            ranges = {
                'yea': 'FullYear',
                'mon': 'Month',
                'day': 'Date',
                'hou': 'Hours',
                'min': 'Minutes',
                'sec': 'Seconds'
            }
            function lastNext (type, range, modifier) {
                var diff
                var day = days[range]
                if (typeof day !== 'undefined') {
                    diff = day - date.getDay()
                    if (diff === 0) {
                        diff = 7 * modifier
                    } else if (diff > 0 && type === 'last') {
                        diff -= 7
                    } else if (diff < 0 && type === 'next') {
                        diff += 7
                    }
                    date.setDate(date.getDate() + diff)
                }
            }
            function process (val) {
                // @todo: Reconcile this with regex using \s, taking into account
                // browser issues with split and regexes
                var splt = val.split(' ')
                var type = splt[0]
                var range = splt[1].substring(0, 3)
                var typeIsNumber = /\d+/.test(type)
                var ago = splt[2] === 'ago'
                var num = (type === 'last' ? -1 : 1) * (ago ? -1 : 1)
                if (typeIsNumber) {
                    num *= parseInt(type, 10)
                }
                if (ranges.hasOwnProperty(range) && !splt[1].match(/^mon(day|\.)?$/i)) {
                    return date['set' + ranges[range]](date['get' + ranges[range]]() + num)
                }
                if (range === 'wee') {
                    return date.setDate(date.getDate() + (num * 7))
                }
                if (type === 'next' || type === 'last') {
                    lastNext(type, range, num)
                } else if (!typeIsNumber) {
                    return false
                }
                return true
            }
            times = '(years?|months?|weeks?|days?|hours?|minutes?|min|seconds?|sec' +
                '|sunday|sun\\.?|monday|mon\\.?|tuesday|tue\\.?|wednesday|wed\\.?' +
                '|thursday|thu\\.?|friday|fri\\.?|saturday|sat\\.?)'
            regex = '([+-]?\\d+\\s' + times + '|' + '(last|next)\\s' + times + ')(\\sago)?'
            match = text.match(new RegExp(regex, 'gi'))
            if (!match) {
                return fail
            }
            for (i = 0, len = match.length; i < len; i++) {
                if (!process(match[i])) {
                    return fail
                }
            }
            return (date.getTime() / 1000)
        }

        return {
            format: format,
            strtotime: strtotime
        }
    }(), true);

})(window);