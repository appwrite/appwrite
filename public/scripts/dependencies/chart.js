/*!
 * Chart.js v3.5.1
 * https://www.chartjs.org
 * (c) 2021 Chart.js Contributors
 * Released under the MIT License
 */
(function (global, factory) {
    typeof exports === 'object' && typeof module !== 'undefined' ? module.exports = factory() :
    typeof define === 'function' && define.amd ? define(factory) :
    (global = typeof globalThis !== 'undefined' ? globalThis : global || self, global.Chart = factory());
    }(this, (function () { 'use strict';
    
    function fontString(pixelSize, fontStyle, fontFamily) {
      return fontStyle + ' ' + pixelSize + 'px ' + fontFamily;
    }
    const requestAnimFrame = (function() {
      if (typeof window === 'undefined') {
        return function(callback) {
          return callback();
        };
      }
      return window.requestAnimationFrame;
    }());
    function throttled(fn, thisArg, updateFn) {
      const updateArgs = updateFn || ((args) => Array.prototype.slice.call(args));
      let ticking = false;
      let args = [];
      return function(...rest) {
        args = updateArgs(rest);
        if (!ticking) {
          ticking = true;
          requestAnimFrame.call(window, () => {
            ticking = false;
            fn.apply(thisArg, args);
          });
        }
      };
    }
    function debounce(fn, delay) {
      let timeout;
      return function() {
        if (delay) {
          clearTimeout(timeout);
          timeout = setTimeout(fn, delay);
        } else {
          fn();
        }
        return delay;
      };
    }
    const _toLeftRightCenter = (align) => align === 'start' ? 'left' : align === 'end' ? 'right' : 'center';
    const _alignStartEnd = (align, start, end) => align === 'start' ? start : align === 'end' ? end : (start + end) / 2;
    const _textX = (align, left, right, rtl) => {
      const check = rtl ? 'left' : 'right';
      return align === check ? right : align === 'center' ? (left + right) / 2 : left;
    };
    
    class Animator {
      constructor() {
        this._request = null;
        this._charts = new Map();
        this._running = false;
        this._lastDate = undefined;
      }
      _notify(chart, anims, date, type) {
        const callbacks = anims.listeners[type];
        const numSteps = anims.duration;
        callbacks.forEach(fn => fn({
          chart,
          initial: anims.initial,
          numSteps,
          currentStep: Math.min(date - anims.start, numSteps)
        }));
      }
      _refresh() {
        const me = this;
        if (me._request) {
          return;
        }
        me._running = true;
        me._request = requestAnimFrame.call(window, () => {
          me._update();
          me._request = null;
          if (me._running) {
            me._refresh();
          }
        });
      }
      _update(date = Date.now()) {
        const me = this;
        let remaining = 0;
        me._charts.forEach((anims, chart) => {
          if (!anims.running || !anims.items.length) {
            return;
          }
          const items = anims.items;
          let i = items.length - 1;
          let draw = false;
          let item;
          for (; i >= 0; --i) {
            item = items[i];
            if (item._active) {
              if (item._total > anims.duration) {
                anims.duration = item._total;
              }
              item.tick(date);
              draw = true;
            } else {
              items[i] = items[items.length - 1];
              items.pop();
            }
          }
          if (draw) {
            chart.draw();
            me._notify(chart, anims, date, 'progress');
          }
          if (!items.length) {
            anims.running = false;
            me._notify(chart, anims, date, 'complete');
            anims.initial = false;
          }
          remaining += items.length;
        });
        me._lastDate = date;
        if (remaining === 0) {
          me._running = false;
        }
      }
      _getAnims(chart) {
        const charts = this._charts;
        let anims = charts.get(chart);
        if (!anims) {
          anims = {
            running: false,
            initial: true,
            items: [],
            listeners: {
              complete: [],
              progress: []
            }
          };
          charts.set(chart, anims);
        }
        return anims;
      }
      listen(chart, event, cb) {
        this._getAnims(chart).listeners[event].push(cb);
      }
      add(chart, items) {
        if (!items || !items.length) {
          return;
        }
        this._getAnims(chart).items.push(...items);
      }
      has(chart) {
        return this._getAnims(chart).items.length > 0;
      }
      start(chart) {
        const anims = this._charts.get(chart);
        if (!anims) {
          return;
        }
        anims.running = true;
        anims.start = Date.now();
        anims.duration = anims.items.reduce((acc, cur) => Math.max(acc, cur._duration), 0);
        this._refresh();
      }
      running(chart) {
        if (!this._running) {
          return false;
        }
        const anims = this._charts.get(chart);
        if (!anims || !anims.running || !anims.items.length) {
          return false;
        }
        return true;
      }
      stop(chart) {
        const anims = this._charts.get(chart);
        if (!anims || !anims.items.length) {
          return;
        }
        const items = anims.items;
        let i = items.length - 1;
        for (; i >= 0; --i) {
          items[i].cancel();
        }
        anims.items = [];
        this._notify(chart, anims, Date.now(), 'complete');
      }
      remove(chart) {
        return this._charts.delete(chart);
      }
    }
    var animator = new Animator();
    
    /*!
     * @kurkle/color v0.1.9
     * https://github.com/kurkle/color#readme
     * (c) 2020 Jukka Kurkela
     * Released under the MIT License
     */
    const map$1 = {0: 0, 1: 1, 2: 2, 3: 3, 4: 4, 5: 5, 6: 6, 7: 7, 8: 8, 9: 9, A: 10, B: 11, C: 12, D: 13, E: 14, F: 15, a: 10, b: 11, c: 12, d: 13, e: 14, f: 15};
    const hex = '0123456789ABCDEF';
    const h1 = (b) => hex[b & 0xF];
    const h2 = (b) => hex[(b & 0xF0) >> 4] + hex[b & 0xF];
    const eq = (b) => (((b & 0xF0) >> 4) === (b & 0xF));
    function isShort(v) {
        return eq(v.r) && eq(v.g) && eq(v.b) && eq(v.a);
    }
    function hexParse(str) {
        var len = str.length;
        var ret;
        if (str[0] === '#') {
            if (len === 4 || len === 5) {
                ret = {
                    r: 255 & map$1[str[1]] * 17,
                    g: 255 & map$1[str[2]] * 17,
                    b: 255 & map$1[str[3]] * 17,
                    a: len === 5 ? map$1[str[4]] * 17 : 255
                };
            } else if (len === 7 || len === 9) {
                ret = {
                    r: map$1[str[1]] << 4 | map$1[str[2]],
                    g: map$1[str[3]] << 4 | map$1[str[4]],
                    b: map$1[str[5]] << 4 | map$1[str[6]],
                    a: len === 9 ? (map$1[str[7]] << 4 | map$1[str[8]]) : 255
                };
            }
        }
        return ret;
    }
    function hexString(v) {
        var f = isShort(v) ? h1 : h2;
        return v
            ? '#' + f(v.r) + f(v.g) + f(v.b) + (v.a < 255 ? f(v.a) : '')
            : v;
    }
    function round(v) {
        return v + 0.5 | 0;
    }
    const lim = (v, l, h) => Math.max(Math.min(v, h), l);
    function p2b(v) {
        return lim(round(v * 2.55), 0, 255);
    }
    function n2b(v) {
        return lim(round(v * 255), 0, 255);
    }
    function b2n(v) {
        return lim(round(v / 2.55) / 100, 0, 1);
    }
    function n2p(v) {
        return lim(round(v * 100), 0, 100);
    }
    const RGB_RE = /^rgba?\(\s*([-+.\d]+)(%)?[\s,]+([-+.e\d]+)(%)?[\s,]+([-+.e\d]+)(%)?(?:[\s,/]+([-+.e\d]+)(%)?)?\s*\)$/;
    function rgbParse(str) {
        const m = RGB_RE.exec(str);
        let a = 255;
        let r, g, b;
        if (!m) {
            return;
        }
        if (m[7] !== r) {
            const v = +m[7];
            a = 255 & (m[8] ? p2b(v) : v * 255);
        }
        r = +m[1];
        g = +m[3];
        b = +m[5];
        r = 255 & (m[2] ? p2b(r) : r);
        g = 255 & (m[4] ? p2b(g) : g);
        b = 255 & (m[6] ? p2b(b) : b);
        return {
            r: r,
            g: g,
            b: b,
            a: a
        };
    }
    function rgbString(v) {
        return v && (
            v.a < 255
                ? `rgba(${v.r}, ${v.g}, ${v.b}, ${b2n(v.a)})`
                : `rgb(${v.r}, ${v.g}, ${v.b})`
        );
    }
    const HUE_RE = /^(hsla?|hwb|hsv)\(\s*([-+.e\d]+)(?:deg)?[\s,]+([-+.e\d]+)%[\s,]+([-+.e\d]+)%(?:[\s,]+([-+.e\d]+)(%)?)?\s*\)$/;
    function hsl2rgbn(h, s, l) {
        const a = s * Math.min(l, 1 - l);
        const f = (n, k = (n + h / 30) % 12) => l - a * Math.max(Math.min(k - 3, 9 - k, 1), -1);
        return [f(0), f(8), f(4)];
    }
    function hsv2rgbn(h, s, v) {
        const f = (n, k = (n + h / 60) % 6) => v - v * s * Math.max(Math.min(k, 4 - k, 1), 0);
        return [f(5), f(3), f(1)];
    }
    function hwb2rgbn(h, w, b) {
        const rgb = hsl2rgbn(h, 1, 0.5);
        let i;
        if (w + b > 1) {
            i = 1 / (w + b);
            w *= i;
            b *= i;
        }
        for (i = 0; i < 3; i++) {
            rgb[i] *= 1 - w - b;
            rgb[i] += w;
        }
        return rgb;
    }
    function rgb2hsl(v) {
        const range = 255;
        const r = v.r / range;
        const g = v.g / range;
        const b = v.b / range;
        const max = Math.max(r, g, b);
        const min = Math.min(r, g, b);
        const l = (max + min) / 2;
        let h, s, d;
        if (max !== min) {
            d = max - min;
            s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
            h = max === r
                ? ((g - b) / d) + (g < b ? 6 : 0)
                : max === g
                    ? (b - r) / d + 2
                    : (r - g) / d + 4;
            h = h * 60 + 0.5;
        }
        return [h | 0, s || 0, l];
    }
    function calln(f, a, b, c) {
        return (
            Array.isArray(a)
                ? f(a[0], a[1], a[2])
                : f(a, b, c)
        ).map(n2b);
    }
    function hsl2rgb(h, s, l) {
        return calln(hsl2rgbn, h, s, l);
    }
    function hwb2rgb(h, w, b) {
        return calln(hwb2rgbn, h, w, b);
    }
    function hsv2rgb(h, s, v) {
        return calln(hsv2rgbn, h, s, v);
    }
    function hue(h) {
        return (h % 360 + 360) % 360;
    }
    function hueParse(str) {
        const m = HUE_RE.exec(str);
        let a = 255;
        let v;
        if (!m) {
            return;
        }
        if (m[5] !== v) {
            a = m[6] ? p2b(+m[5]) : n2b(+m[5]);
        }
        const h = hue(+m[2]);
        const p1 = +m[3] / 100;
        const p2 = +m[4] / 100;
        if (m[1] === 'hwb') {
            v = hwb2rgb(h, p1, p2);
        } else if (m[1] === 'hsv') {
            v = hsv2rgb(h, p1, p2);
        } else {
            v = hsl2rgb(h, p1, p2);
        }
        return {
            r: v[0],
            g: v[1],
            b: v[2],
            a: a
        };
    }
    function rotate(v, deg) {
        var h = rgb2hsl(v);
        h[0] = hue(h[0] + deg);
        h = hsl2rgb(h);
        v.r = h[0];
        v.g = h[1];
        v.b = h[2];
    }
    function hslString(v) {
        if (!v) {
            return;
        }
        const a = rgb2hsl(v);
        const h = a[0];
        const s = n2p(a[1]);
        const l = n2p(a[2]);
        return v.a < 255
            ? `hsla(${h}, ${s}%, ${l}%, ${b2n(v.a)})`
            : `hsl(${h}, ${s}%, ${l}%)`;
    }
    const map$1$1 = {
        x: 'dark',
        Z: 'light',
        Y: 're',
        X: 'blu',
        W: 'gr',
        V: 'medium',
        U: 'slate',
        A: 'ee',
        T: 'ol',
        S: 'or',
        B: 'ra',
        C: 'lateg',
        D: 'ights',
        R: 'in',
        Q: 'turquois',
        E: 'hi',
        P: 'ro',
        O: 'al',
        N: 'le',
        M: 'de',
        L: 'yello',
        F: 'en',
        K: 'ch',
        G: 'arks',
        H: 'ea',
        I: 'ightg',
        J: 'wh'
    };
    const names = {
        OiceXe: 'f0f8ff',
        antiquewEte: 'faebd7',
        aqua: 'ffff',
        aquamarRe: '7fffd4',
        azuY: 'f0ffff',
        beige: 'f5f5dc',
        bisque: 'ffe4c4',
        black: '0',
        blanKedOmond: 'ffebcd',
        Xe: 'ff',
        XeviTet: '8a2be2',
        bPwn: 'a52a2a',
        burlywood: 'deb887',
        caMtXe: '5f9ea0',
        KartYuse: '7fff00',
        KocTate: 'd2691e',
        cSO: 'ff7f50',
        cSnflowerXe: '6495ed',
        cSnsilk: 'fff8dc',
        crimson: 'dc143c',
        cyan: 'ffff',
        xXe: '8b',
        xcyan: '8b8b',
        xgTMnPd: 'b8860b',
        xWay: 'a9a9a9',
        xgYF: '6400',
        xgYy: 'a9a9a9',
        xkhaki: 'bdb76b',
        xmagFta: '8b008b',
        xTivegYF: '556b2f',
        xSange: 'ff8c00',
        xScEd: '9932cc',
        xYd: '8b0000',
        xsOmon: 'e9967a',
        xsHgYF: '8fbc8f',
        xUXe: '483d8b',
        xUWay: '2f4f4f',
        xUgYy: '2f4f4f',
        xQe: 'ced1',
        xviTet: '9400d3',
        dAppRk: 'ff1493',
        dApskyXe: 'bfff',
        dimWay: '696969',
        dimgYy: '696969',
        dodgerXe: '1e90ff',
        fiYbrick: 'b22222',
        flSOwEte: 'fffaf0',
        foYstWAn: '228b22',
        fuKsia: 'ff00ff',
        gaRsbSo: 'dcdcdc',
        ghostwEte: 'f8f8ff',
        gTd: 'ffd700',
        gTMnPd: 'daa520',
        Way: '808080',
        gYF: '8000',
        gYFLw: 'adff2f',
        gYy: '808080',
        honeyMw: 'f0fff0',
        hotpRk: 'ff69b4',
        RdianYd: 'cd5c5c',
        Rdigo: '4b0082',
        ivSy: 'fffff0',
        khaki: 'f0e68c',
        lavFMr: 'e6e6fa',
        lavFMrXsh: 'fff0f5',
        lawngYF: '7cfc00',
        NmoncEffon: 'fffacd',
        ZXe: 'add8e6',
        ZcSO: 'f08080',
        Zcyan: 'e0ffff',
        ZgTMnPdLw: 'fafad2',
        ZWay: 'd3d3d3',
        ZgYF: '90ee90',
        ZgYy: 'd3d3d3',
        ZpRk: 'ffb6c1',
        ZsOmon: 'ffa07a',
        ZsHgYF: '20b2aa',
        ZskyXe: '87cefa',
        ZUWay: '778899',
        ZUgYy: '778899',
        ZstAlXe: 'b0c4de',
        ZLw: 'ffffe0',
        lime: 'ff00',
        limegYF: '32cd32',
        lRF: 'faf0e6',
        magFta: 'ff00ff',
        maPon: '800000',
        VaquamarRe: '66cdaa',
        VXe: 'cd',
        VScEd: 'ba55d3',
        VpurpN: '9370db',
        VsHgYF: '3cb371',
        VUXe: '7b68ee',
        VsprRggYF: 'fa9a',
        VQe: '48d1cc',
        VviTetYd: 'c71585',
        midnightXe: '191970',
        mRtcYam: 'f5fffa',
        mistyPse: 'ffe4e1',
        moccasR: 'ffe4b5',
        navajowEte: 'ffdead',
        navy: '80',
        Tdlace: 'fdf5e6',
        Tive: '808000',
        TivedBb: '6b8e23',
        Sange: 'ffa500',
        SangeYd: 'ff4500',
        ScEd: 'da70d6',
        pOegTMnPd: 'eee8aa',
        pOegYF: '98fb98',
        pOeQe: 'afeeee',
        pOeviTetYd: 'db7093',
        papayawEp: 'ffefd5',
        pHKpuff: 'ffdab9',
        peru: 'cd853f',
        pRk: 'ffc0cb',
        plum: 'dda0dd',
        powMrXe: 'b0e0e6',
        purpN: '800080',
        YbeccapurpN: '663399',
        Yd: 'ff0000',
        Psybrown: 'bc8f8f',
        PyOXe: '4169e1',
        saddNbPwn: '8b4513',
        sOmon: 'fa8072',
        sandybPwn: 'f4a460',
        sHgYF: '2e8b57',
        sHshell: 'fff5ee',
        siFna: 'a0522d',
        silver: 'c0c0c0',
        skyXe: '87ceeb',
        UXe: '6a5acd',
        UWay: '708090',
        UgYy: '708090',
        snow: 'fffafa',
        sprRggYF: 'ff7f',
        stAlXe: '4682b4',
        tan: 'd2b48c',
        teO: '8080',
        tEstN: 'd8bfd8',
        tomato: 'ff6347',
        Qe: '40e0d0',
        viTet: 'ee82ee',
        JHt: 'f5deb3',
        wEte: 'ffffff',
        wEtesmoke: 'f5f5f5',
        Lw: 'ffff00',
        LwgYF: '9acd32'
    };
    function unpack() {
        const unpacked = {};
        const keys = Object.keys(names);
        const tkeys = Object.keys(map$1$1);
        let i, j, k, ok, nk;
        for (i = 0; i < keys.length; i++) {
            ok = nk = keys[i];
            for (j = 0; j < tkeys.length; j++) {
                k = tkeys[j];
                nk = nk.replace(k, map$1$1[k]);
            }
            k = parseInt(names[ok], 16);
            unpacked[nk] = [k >> 16 & 0xFF, k >> 8 & 0xFF, k & 0xFF];
        }
        return unpacked;
    }
    let names$1;
    function nameParse(str) {
        if (!names$1) {
            names$1 = unpack();
            names$1.transparent = [0, 0, 0, 0];
        }
        const a = names$1[str.toLowerCase()];
        return a && {
            r: a[0],
            g: a[1],
            b: a[2],
            a: a.length === 4 ? a[3] : 255
        };
    }
    function modHSL(v, i, ratio) {
        if (v) {
            let tmp = rgb2hsl(v);
            tmp[i] = Math.max(0, Math.min(tmp[i] + tmp[i] * ratio, i === 0 ? 360 : 1));
            tmp = hsl2rgb(tmp);
            v.r = tmp[0];
            v.g = tmp[1];
            v.b = tmp[2];
        }
    }
    function clone$1(v, proto) {
        return v ? Object.assign(proto || {}, v) : v;
    }
    function fromObject(input) {
        var v = {r: 0, g: 0, b: 0, a: 255};
        if (Array.isArray(input)) {
            if (input.length >= 3) {
                v = {r: input[0], g: input[1], b: input[2], a: 255};
                if (input.length > 3) {
                    v.a = n2b(input[3]);
                }
            }
        } else {
            v = clone$1(input, {r: 0, g: 0, b: 0, a: 1});
            v.a = n2b(v.a);
        }
        return v;
    }
    function functionParse(str) {
        if (str.charAt(0) === 'r') {
            return rgbParse(str);
        }
        return hueParse(str);
    }
    class Color {
        constructor(input) {
            if (input instanceof Color) {
                return input;
            }
            const type = typeof input;
            let v;
            if (type === 'object') {
                v = fromObject(input);
            } else if (type === 'string') {
                v = hexParse(input) || nameParse(input) || functionParse(input);
            }
            this._rgb = v;
            this._valid = !!v;
        }
        get valid() {
            return this._valid;
        }
        get rgb() {
            var v = clone$1(this._rgb);
            if (v) {
                v.a = b2n(v.a);
            }
            return v;
        }
        set rgb(obj) {
            this._rgb = fromObject(obj);
        }
        rgbString() {
            return this._valid ? rgbString(this._rgb) : this._rgb;
        }
        hexString() {
            return this._valid ? hexString(this._rgb) : this._rgb;
        }
        hslString() {
            return this._valid ? hslString(this._rgb) : this._rgb;
        }
        mix(color, weight) {
            const me = this;
            if (color) {
                const c1 = me.rgb;
                const c2 = color.rgb;
                let w2;
                const p = weight === w2 ? 0.5 : weight;
                const w = 2 * p - 1;
                const a = c1.a - c2.a;
                const w1 = ((w * a === -1 ? w : (w + a) / (1 + w * a)) + 1) / 2.0;
                w2 = 1 - w1;
                c1.r = 0xFF & w1 * c1.r + w2 * c2.r + 0.5;
                c1.g = 0xFF & w1 * c1.g + w2 * c2.g + 0.5;
                c1.b = 0xFF & w1 * c1.b + w2 * c2.b + 0.5;
                c1.a = p * c1.a + (1 - p) * c2.a;
                me.rgb = c1;
            }
            return me;
        }
        clone() {
            return new Color(this.rgb);
        }
        alpha(a) {
            this._rgb.a = n2b(a);
            return this;
        }
        clearer(ratio) {
            const rgb = this._rgb;
            rgb.a *= 1 - ratio;
            return this;
        }
        greyscale() {
            const rgb = this._rgb;
            const val = round(rgb.r * 0.3 + rgb.g * 0.59 + rgb.b * 0.11);
            rgb.r = rgb.g = rgb.b = val;
            return this;
        }
        opaquer(ratio) {
            const rgb = this._rgb;
            rgb.a *= 1 + ratio;
            return this;
        }
        negate() {
            const v = this._rgb;
            v.r = 255 - v.r;
            v.g = 255 - v.g;
            v.b = 255 - v.b;
            return this;
        }
        lighten(ratio) {
            modHSL(this._rgb, 2, ratio);
            return this;
        }
        darken(ratio) {
            modHSL(this._rgb, 2, -ratio);
            return this;
        }
        saturate(ratio) {
            modHSL(this._rgb, 1, ratio);
            return this;
        }
        desaturate(ratio) {
            modHSL(this._rgb, 1, -ratio);
            return this;
        }
        rotate(deg) {
            rotate(this._rgb, deg);
            return this;
        }
    }
    function index_esm(input) {
        return new Color(input);
    }
    
    const isPatternOrGradient = (value) => value instanceof CanvasGradient || value instanceof CanvasPattern;
    function color(value) {
      return isPatternOrGradient(value) ? value : index_esm(value);
    }
    function getHoverColor(value) {
      return isPatternOrGradient(value)
        ? value
        : index_esm(value).saturate(0.5).darken(0.1).hexString();
    }
    
    function noop() {}
    const uid = (function() {
      let id = 0;
      return function() {
        return id++;
      };
    }());
    function isNullOrUndef(value) {
      return value === null || typeof value === 'undefined';
    }
    function isArray(value) {
      if (Array.isArray && Array.isArray(value)) {
        return true;
      }
      const type = Object.prototype.toString.call(value);
      if (type.substr(0, 7) === '[object' && type.substr(-6) === 'Array]') {
        return true;
      }
      return false;
    }
    function isObject(value) {
      return value !== null && Object.prototype.toString.call(value) === '[object Object]';
    }
    const isNumberFinite = (value) => (typeof value === 'number' || value instanceof Number) && isFinite(+value);
    function finiteOrDefault(value, defaultValue) {
      return isNumberFinite(value) ? value : defaultValue;
    }
    function valueOrDefault(value, defaultValue) {
      return typeof value === 'undefined' ? defaultValue : value;
    }
    const toPercentage = (value, dimension) =>
      typeof value === 'string' && value.endsWith('%') ?
        parseFloat(value) / 100
        : value / dimension;
    const toDimension = (value, dimension) =>
      typeof value === 'string' && value.endsWith('%') ?
        parseFloat(value) / 100 * dimension
        : +value;
    function callback(fn, args, thisArg) {
      if (fn && typeof fn.call === 'function') {
        return fn.apply(thisArg, args);
      }
    }
    function each(loopable, fn, thisArg, reverse) {
      let i, len, keys;
      if (isArray(loopable)) {
        len = loopable.length;
        if (reverse) {
          for (i = len - 1; i >= 0; i--) {
            fn.call(thisArg, loopable[i], i);
          }
        } else {
          for (i = 0; i < len; i++) {
            fn.call(thisArg, loopable[i], i);
          }
        }
      } else if (isObject(loopable)) {
        keys = Object.keys(loopable);
        len = keys.length;
        for (i = 0; i < len; i++) {
          fn.call(thisArg, loopable[keys[i]], keys[i]);
        }
      }
    }
    function _elementsEqual(a0, a1) {
      let i, ilen, v0, v1;
      if (!a0 || !a1 || a0.length !== a1.length) {
        return false;
      }
      for (i = 0, ilen = a0.length; i < ilen; ++i) {
        v0 = a0[i];
        v1 = a1[i];
        if (v0.datasetIndex !== v1.datasetIndex || v0.index !== v1.index) {
          return false;
        }
      }
      return true;
    }
    function clone(source) {
      if (isArray(source)) {
        return source.map(clone);
      }
      if (isObject(source)) {
        const target = Object.create(null);
        const keys = Object.keys(source);
        const klen = keys.length;
        let k = 0;
        for (; k < klen; ++k) {
          target[keys[k]] = clone(source[keys[k]]);
        }
        return target;
      }
      return source;
    }
    function isValidKey(key) {
      return ['__proto__', 'prototype', 'constructor'].indexOf(key) === -1;
    }
    function _merger(key, target, source, options) {
      if (!isValidKey(key)) {
        return;
      }
      const tval = target[key];
      const sval = source[key];
      if (isObject(tval) && isObject(sval)) {
        merge(tval, sval, options);
      } else {
        target[key] = clone(sval);
      }
    }
    function merge(target, source, options) {
      const sources = isArray(source) ? source : [source];
      const ilen = sources.length;
      if (!isObject(target)) {
        return target;
      }
      options = options || {};
      const merger = options.merger || _merger;
      for (let i = 0; i < ilen; ++i) {
        source = sources[i];
        if (!isObject(source)) {
          continue;
        }
        const keys = Object.keys(source);
        for (let k = 0, klen = keys.length; k < klen; ++k) {
          merger(keys[k], target, source, options);
        }
      }
      return target;
    }
    function mergeIf(target, source) {
      return merge(target, source, {merger: _mergerIf});
    }
    function _mergerIf(key, target, source) {
      if (!isValidKey(key)) {
        return;
      }
      const tval = target[key];
      const sval = source[key];
      if (isObject(tval) && isObject(sval)) {
        mergeIf(tval, sval);
      } else if (!Object.prototype.hasOwnProperty.call(target, key)) {
        target[key] = clone(sval);
      }
    }
    function _deprecated(scope, value, previous, current) {
      if (value !== undefined) {
        console.warn(scope + ': "' + previous +
                '" is deprecated. Please use "' + current + '" instead');
      }
    }
    const emptyString = '';
    const dot = '.';
    function indexOfDotOrLength(key, start) {
      const idx = key.indexOf(dot, start);
      return idx === -1 ? key.length : idx;
    }
    function resolveObjectKey(obj, key) {
      if (key === emptyString) {
        return obj;
      }
      let pos = 0;
      let idx = indexOfDotOrLength(key, pos);
      while (obj && idx > pos) {
        obj = obj[key.substr(pos, idx - pos)];
        pos = idx + 1;
        idx = indexOfDotOrLength(key, pos);
      }
      return obj;
    }
    function _capitalize(str) {
      return str.charAt(0).toUpperCase() + str.slice(1);
    }
    const defined = (value) => typeof value !== 'undefined';
    const isFunction = (value) => typeof value === 'function';
    const setsEqual = (a, b) => {
      if (a.size !== b.size) {
        return false;
      }
      for (const item of a) {
        if (!b.has(item)) {
          return false;
        }
      }
      return true;
    };
    
    const overrides = Object.create(null);
    const descriptors = Object.create(null);
    function getScope$1(node, key) {
      if (!key) {
        return node;
      }
      const keys = key.split('.');
      for (let i = 0, n = keys.length; i < n; ++i) {
        const k = keys[i];
        node = node[k] || (node[k] = Object.create(null));
      }
      return node;
    }
    function set(root, scope, values) {
      if (typeof scope === 'string') {
        return merge(getScope$1(root, scope), values);
      }
      return merge(getScope$1(root, ''), scope);
    }
    class Defaults {
      constructor(_descriptors) {
        this.animation = undefined;
        this.backgroundColor = 'rgba(0,0,0,0.1)';
        this.borderColor = 'rgba(0,0,0,0.1)';
        this.color = '#666';
        this.datasets = {};
        this.devicePixelRatio = (context) => context.chart.platform.getDevicePixelRatio();
        this.elements = {};
        this.events = [
          'mousemove',
          'mouseout',
          'click',
          'touchstart',
          'touchmove'
        ];
        this.font = {
          family: "'Helvetica Neue', 'Helvetica', 'Arial', sans-serif",
          size: 12,
          style: 'normal',
          lineHeight: 1.2,
          weight: null
        };
        this.hover = {};
        this.hoverBackgroundColor = (ctx, options) => getHoverColor(options.backgroundColor);
        this.hoverBorderColor = (ctx, options) => getHoverColor(options.borderColor);
        this.hoverColor = (ctx, options) => getHoverColor(options.color);
        this.indexAxis = 'x';
        this.interaction = {
          mode: 'nearest',
          intersect: true
        };
        this.maintainAspectRatio = true;
        this.onHover = null;
        this.onClick = null;
        this.parsing = true;
        this.plugins = {};
        this.responsive = true;
        this.scale = undefined;
        this.scales = {};
        this.showLine = true;
        this.describe(_descriptors);
      }
      set(scope, values) {
        return set(this, scope, values);
      }
      get(scope) {
        return getScope$1(this, scope);
      }
      describe(scope, values) {
        return set(descriptors, scope, values);
      }
      override(scope, values) {
        return set(overrides, scope, values);
      }
      route(scope, name, targetScope, targetName) {
        const scopeObject = getScope$1(this, scope);
        const targetScopeObject = getScope$1(this, targetScope);
        const privateName = '_' + name;
        Object.defineProperties(scopeObject, {
          [privateName]: {
            value: scopeObject[name],
            writable: true
          },
          [name]: {
            enumerable: true,
            get() {
              const local = this[privateName];
              const target = targetScopeObject[targetName];
              if (isObject(local)) {
                return Object.assign({}, target, local);
              }
              return valueOrDefault(local, target);
            },
            set(value) {
              this[privateName] = value;
            }
          }
        });
      }
    }
    var defaults = new Defaults({
      _scriptable: (name) => !name.startsWith('on'),
      _indexable: (name) => name !== 'events',
      hover: {
        _fallback: 'interaction'
      },
      interaction: {
        _scriptable: false,
        _indexable: false,
      }
    });
    
    const PI = Math.PI;
    const TAU = 2 * PI;
    const PITAU = TAU + PI;
    const INFINITY = Number.POSITIVE_INFINITY;
    const RAD_PER_DEG = PI / 180;
    const HALF_PI = PI / 2;
    const QUARTER_PI = PI / 4;
    const TWO_THIRDS_PI = PI * 2 / 3;
    const log10 = Math.log10;
    const sign = Math.sign;
    function niceNum(range) {
      const roundedRange = Math.round(range);
      range = almostEquals(range, roundedRange, range / 1000) ? roundedRange : range;
      const niceRange = Math.pow(10, Math.floor(log10(range)));
      const fraction = range / niceRange;
      const niceFraction = fraction <= 1 ? 1 : fraction <= 2 ? 2 : fraction <= 5 ? 5 : 10;
      return niceFraction * niceRange;
    }
    function _factorize(value) {
      const result = [];
      const sqrt = Math.sqrt(value);
      let i;
      for (i = 1; i < sqrt; i++) {
        if (value % i === 0) {
          result.push(i);
          result.push(value / i);
        }
      }
      if (sqrt === (sqrt | 0)) {
        result.push(sqrt);
      }
      result.sort((a, b) => a - b).pop();
      return result;
    }
    function isNumber(n) {
      return !isNaN(parseFloat(n)) && isFinite(n);
    }
    function almostEquals(x, y, epsilon) {
      return Math.abs(x - y) < epsilon;
    }
    function almostWhole(x, epsilon) {
      const rounded = Math.round(x);
      return ((rounded - epsilon) <= x) && ((rounded + epsilon) >= x);
    }
    function _setMinAndMaxByKey(array, target, property) {
      let i, ilen, value;
      for (i = 0, ilen = array.length; i < ilen; i++) {
        value = array[i][property];
        if (!isNaN(value)) {
          target.min = Math.min(target.min, value);
          target.max = Math.max(target.max, value);
        }
      }
    }
    function toRadians(degrees) {
      return degrees * (PI / 180);
    }
    function toDegrees(radians) {
      return radians * (180 / PI);
    }
    function _decimalPlaces(x) {
      if (!isNumberFinite(x)) {
        return;
      }
      let e = 1;
      let p = 0;
      while (Math.round(x * e) / e !== x) {
        e *= 10;
        p++;
      }
      return p;
    }
    function getAngleFromPoint(centrePoint, anglePoint) {
      const distanceFromXCenter = anglePoint.x - centrePoint.x;
      const distanceFromYCenter = anglePoint.y - centrePoint.y;
      const radialDistanceFromCenter = Math.sqrt(distanceFromXCenter * distanceFromXCenter + distanceFromYCenter * distanceFromYCenter);
      let angle = Math.atan2(distanceFromYCenter, distanceFromXCenter);
      if (angle < (-0.5 * PI)) {
        angle += TAU;
      }
      return {
        angle,
        distance: radialDistanceFromCenter
      };
    }
    function distanceBetweenPoints(pt1, pt2) {
      return Math.sqrt(Math.pow(pt2.x - pt1.x, 2) + Math.pow(pt2.y - pt1.y, 2));
    }
    function _angleDiff(a, b) {
      return (a - b + PITAU) % TAU - PI;
    }
    function _normalizeAngle(a) {
      return (a % TAU + TAU) % TAU;
    }
    function _angleBetween(angle, start, end, sameAngleIsFullCircle) {
      const a = _normalizeAngle(angle);
      const s = _normalizeAngle(start);
      const e = _normalizeAngle(end);
      const angleToStart = _normalizeAngle(s - a);
      const angleToEnd = _normalizeAngle(e - a);
      const startToAngle = _normalizeAngle(a - s);
      const endToAngle = _normalizeAngle(a - e);
      return a === s || a === e || (sameAngleIsFullCircle && s === e)
        || (angleToStart > angleToEnd && startToAngle < endToAngle);
    }
    function _limitValue(value, min, max) {
      return Math.max(min, Math.min(max, value));
    }
    function _int16Range(value) {
      return _limitValue(value, -32768, 32767);
    }
    
    function toFontString(font) {
      if (!font || isNullOrUndef(font.size) || isNullOrUndef(font.family)) {
        return null;
      }
      return (font.style ? font.style + ' ' : '')
            + (font.weight ? font.weight + ' ' : '')
            + font.size + 'px '
            + font.family;
    }
    function _measureText(ctx, data, gc, longest, string) {
      let textWidth = data[string];
      if (!textWidth) {
        textWidth = data[string] = ctx.measureText(string).width;
        gc.push(string);
      }
      if (textWidth > longest) {
        longest = textWidth;
      }
      return longest;
    }
    function _longestText(ctx, font, arrayOfThings, cache) {
      cache = cache || {};
      let data = cache.data = cache.data || {};
      let gc = cache.garbageCollect = cache.garbageCollect || [];
      if (cache.font !== font) {
        data = cache.data = {};
        gc = cache.garbageCollect = [];
        cache.font = font;
      }
      ctx.save();
      ctx.font = font;
      let longest = 0;
      const ilen = arrayOfThings.length;
      let i, j, jlen, thing, nestedThing;
      for (i = 0; i < ilen; i++) {
        thing = arrayOfThings[i];
        if (thing !== undefined && thing !== null && isArray(thing) !== true) {
          longest = _measureText(ctx, data, gc, longest, thing);
        } else if (isArray(thing)) {
          for (j = 0, jlen = thing.length; j < jlen; j++) {
            nestedThing = thing[j];
            if (nestedThing !== undefined && nestedThing !== null && !isArray(nestedThing)) {
              longest = _measureText(ctx, data, gc, longest, nestedThing);
            }
          }
        }
      }
      ctx.restore();
      const gcLen = gc.length / 2;
      if (gcLen > arrayOfThings.length) {
        for (i = 0; i < gcLen; i++) {
          delete data[gc[i]];
        }
        gc.splice(0, gcLen);
      }
      return longest;
    }
    function _alignPixel(chart, pixel, width) {
      const devicePixelRatio = chart.currentDevicePixelRatio;
      const halfWidth = width !== 0 ? Math.max(width / 2, 0.5) : 0;
      return Math.round((pixel - halfWidth) * devicePixelRatio) / devicePixelRatio + halfWidth;
    }
    function clearCanvas(canvas, ctx) {
      ctx = ctx || canvas.getContext('2d');
      ctx.save();
      ctx.resetTransform();
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      ctx.restore();
    }
    function drawPoint(ctx, options, x, y) {
      let type, xOffset, yOffset, size, cornerRadius;
      const style = options.pointStyle;
      const rotation = options.rotation;
      const radius = options.radius;
      let rad = (rotation || 0) * RAD_PER_DEG;
      if (style && typeof style === 'object') {
        type = style.toString();
        if (type === '[object HTMLImageElement]' || type === '[object HTMLCanvasElement]') {
          ctx.save();
          ctx.translate(x, y);
          ctx.rotate(rad);
          ctx.drawImage(style, -style.width / 2, -style.height / 2, style.width, style.height);
          ctx.restore();
          return;
        }
      }
      if (isNaN(radius) || radius <= 0) {
        return;
      }
      ctx.beginPath();
      switch (style) {
      default:
        ctx.arc(x, y, radius, 0, TAU);
        ctx.closePath();
        break;
      case 'triangle':
        ctx.moveTo(x + Math.sin(rad) * radius, y - Math.cos(rad) * radius);
        rad += TWO_THIRDS_PI;
        ctx.lineTo(x + Math.sin(rad) * radius, y - Math.cos(rad) * radius);
        rad += TWO_THIRDS_PI;
        ctx.lineTo(x + Math.sin(rad) * radius, y - Math.cos(rad) * radius);
        ctx.closePath();
        break;
      case 'rectRounded':
        cornerRadius = radius * 0.516;
        size = radius - cornerRadius;
        xOffset = Math.cos(rad + QUARTER_PI) * size;
        yOffset = Math.sin(rad + QUARTER_PI) * size;
        ctx.arc(x - xOffset, y - yOffset, cornerRadius, rad - PI, rad - HALF_PI);
        ctx.arc(x + yOffset, y - xOffset, cornerRadius, rad - HALF_PI, rad);
        ctx.arc(x + xOffset, y + yOffset, cornerRadius, rad, rad + HALF_PI);
        ctx.arc(x - yOffset, y + xOffset, cornerRadius, rad + HALF_PI, rad + PI);
        ctx.closePath();
        break;
      case 'rect':
        if (!rotation) {
          size = Math.SQRT1_2 * radius;
          ctx.rect(x - size, y - size, 2 * size, 2 * size);
          break;
        }
        rad += QUARTER_PI;
      case 'rectRot':
        xOffset = Math.cos(rad) * radius;
        yOffset = Math.sin(rad) * radius;
        ctx.moveTo(x - xOffset, y - yOffset);
        ctx.lineTo(x + yOffset, y - xOffset);
        ctx.lineTo(x + xOffset, y + yOffset);
        ctx.lineTo(x - yOffset, y + xOffset);
        ctx.closePath();
        break;
      case 'crossRot':
        rad += QUARTER_PI;
      case 'cross':
        xOffset = Math.cos(rad) * radius;
        yOffset = Math.sin(rad) * radius;
        ctx.moveTo(x - xOffset, y - yOffset);
        ctx.lineTo(x + xOffset, y + yOffset);
        ctx.moveTo(x + yOffset, y - xOffset);
        ctx.lineTo(x - yOffset, y + xOffset);
        break;
      case 'star':
        xOffset = Math.cos(rad) * radius;
        yOffset = Math.sin(rad) * radius;
        ctx.moveTo(x - xOffset, y - yOffset);
        ctx.lineTo(x + xOffset, y + yOffset);
        ctx.moveTo(x + yOffset, y - xOffset);
        ctx.lineTo(x - yOffset, y + xOffset);
        rad += QUARTER_PI;
        xOffset = Math.cos(rad) * radius;
        yOffset = Math.sin(rad) * radius;
        ctx.moveTo(x - xOffset, y - yOffset);
        ctx.lineTo(x + xOffset, y + yOffset);
        ctx.moveTo(x + yOffset, y - xOffset);
        ctx.lineTo(x - yOffset, y + xOffset);
        break;
      case 'line':
        xOffset = Math.cos(rad) * radius;
        yOffset = Math.sin(rad) * radius;
        ctx.moveTo(x - xOffset, y - yOffset);
        ctx.lineTo(x + xOffset, y + yOffset);
        break;
      case 'dash':
        ctx.moveTo(x, y);
        ctx.lineTo(x + Math.cos(rad) * radius, y + Math.sin(rad) * radius);
        break;
      }
      ctx.fill();
      if (options.borderWidth > 0) {
        ctx.stroke();
      }
    }
    function _isPointInArea(point, area, margin) {
      margin = margin || 0.5;
      return !area || (point && point.x > area.left - margin && point.x < area.right + margin &&
            point.y > area.top - margin && point.y < area.bottom + margin);
    }
    function clipArea(ctx, area) {
      ctx.save();
      ctx.beginPath();
      ctx.rect(area.left, area.top, area.right - area.left, area.bottom - area.top);
      ctx.clip();
    }
    function unclipArea(ctx) {
      ctx.restore();
    }
    function _steppedLineTo(ctx, previous, target, flip, mode) {
      if (!previous) {
        return ctx.lineTo(target.x, target.y);
      }
      if (mode === 'middle') {
        const midpoint = (previous.x + target.x) / 2.0;
        ctx.lineTo(midpoint, previous.y);
        ctx.lineTo(midpoint, target.y);
      } else if (mode === 'after' !== !!flip) {
        ctx.lineTo(previous.x, target.y);
      } else {
        ctx.lineTo(target.x, previous.y);
      }
      ctx.lineTo(target.x, target.y);
    }
    function _bezierCurveTo(ctx, previous, target, flip) {
      if (!previous) {
        return ctx.lineTo(target.x, target.y);
      }
      ctx.bezierCurveTo(
        flip ? previous.cp1x : previous.cp2x,
        flip ? previous.cp1y : previous.cp2y,
        flip ? target.cp2x : target.cp1x,
        flip ? target.cp2y : target.cp1y,
        target.x,
        target.y);
    }
    function renderText(ctx, text, x, y, font, opts = {}) {
      const lines = isArray(text) ? text : [text];
      const stroke = opts.strokeWidth > 0 && opts.strokeColor !== '';
      let i, line;
      ctx.save();
      ctx.font = font.string;
      setRenderOpts(ctx, opts);
      for (i = 0; i < lines.length; ++i) {
        line = lines[i];
        if (stroke) {
          if (opts.strokeColor) {
            ctx.strokeStyle = opts.strokeColor;
          }
          if (!isNullOrUndef(opts.strokeWidth)) {
            ctx.lineWidth = opts.strokeWidth;
          }
          ctx.strokeText(line, x, y, opts.maxWidth);
        }
        ctx.fillText(line, x, y, opts.maxWidth);
        decorateText(ctx, x, y, line, opts);
        y += font.lineHeight;
      }
      ctx.restore();
    }
    function setRenderOpts(ctx, opts) {
      if (opts.translation) {
        ctx.translate(opts.translation[0], opts.translation[1]);
      }
      if (!isNullOrUndef(opts.rotation)) {
        ctx.rotate(opts.rotation);
      }
      if (opts.color) {
        ctx.fillStyle = opts.color;
      }
      if (opts.textAlign) {
        ctx.textAlign = opts.textAlign;
      }
      if (opts.textBaseline) {
        ctx.textBaseline = opts.textBaseline;
      }
    }
    function decorateText(ctx, x, y, line, opts) {
      if (opts.strikethrough || opts.underline) {
        const metrics = ctx.measureText(line);
        const left = x - metrics.actualBoundingBoxLeft;
        const right = x + metrics.actualBoundingBoxRight;
        const top = y - metrics.actualBoundingBoxAscent;
        const bottom = y + metrics.actualBoundingBoxDescent;
        const yDecoration = opts.strikethrough ? (top + bottom) / 2 : bottom;
        ctx.strokeStyle = ctx.fillStyle;
        ctx.beginPath();
        ctx.lineWidth = opts.decorationWidth || 2;
        ctx.moveTo(left, yDecoration);
        ctx.lineTo(right, yDecoration);
        ctx.stroke();
      }
    }
    function addRoundedRectPath(ctx, rect) {
      const {x, y, w, h, radius} = rect;
      ctx.arc(x + radius.topLeft, y + radius.topLeft, radius.topLeft, -HALF_PI, PI, true);
      ctx.lineTo(x, y + h - radius.bottomLeft);
      ctx.arc(x + radius.bottomLeft, y + h - radius.bottomLeft, radius.bottomLeft, PI, HALF_PI, true);
      ctx.lineTo(x + w - radius.bottomRight, y + h);
      ctx.arc(x + w - radius.bottomRight, y + h - radius.bottomRight, radius.bottomRight, HALF_PI, 0, true);
      ctx.lineTo(x + w, y + radius.topRight);
      ctx.arc(x + w - radius.topRight, y + radius.topRight, radius.topRight, 0, -HALF_PI, true);
      ctx.lineTo(x + radius.topLeft, y);
    }
    
    function _lookup(table, value, cmp) {
      cmp = cmp || ((index) => table[index] < value);
      let hi = table.length - 1;
      let lo = 0;
      let mid;
      while (hi - lo > 1) {
        mid = (lo + hi) >> 1;
        if (cmp(mid)) {
          lo = mid;
        } else {
          hi = mid;
        }
      }
      return {lo, hi};
    }
    const _lookupByKey = (table, key, value) =>
      _lookup(table, value, index => table[index][key] < value);
    const _rlookupByKey = (table, key, value) =>
      _lookup(table, value, index => table[index][key] >= value);
    function _filterBetween(values, min, max) {
      let start = 0;
      let end = values.length;
      while (start < end && values[start] < min) {
        start++;
      }
      while (end > start && values[end - 1] > max) {
        end--;
      }
      return start > 0 || end < values.length
        ? values.slice(start, end)
        : values;
    }
    const arrayEvents = ['push', 'pop', 'shift', 'splice', 'unshift'];
    function listenArrayEvents(array, listener) {
      if (array._chartjs) {
        array._chartjs.listeners.push(listener);
        return;
      }
      Object.defineProperty(array, '_chartjs', {
        configurable: true,
        enumerable: false,
        value: {
          listeners: [listener]
        }
      });
      arrayEvents.forEach((key) => {
        const method = '_onData' + _capitalize(key);
        const base = array[key];
        Object.defineProperty(array, key, {
          configurable: true,
          enumerable: false,
          value(...args) {
            const res = base.apply(this, args);
            array._chartjs.listeners.forEach((object) => {
              if (typeof object[method] === 'function') {
                object[method](...args);
              }
            });
            return res;
          }
        });
      });
    }
    function unlistenArrayEvents(array, listener) {
      const stub = array._chartjs;
      if (!stub) {
        return;
      }
      const listeners = stub.listeners;
      const index = listeners.indexOf(listener);
      if (index !== -1) {
        listeners.splice(index, 1);
      }
      if (listeners.length > 0) {
        return;
      }
      arrayEvents.forEach((key) => {
        delete array[key];
      });
      delete array._chartjs;
    }
    function _arrayUnique(items) {
      const set = new Set();
      let i, ilen;
      for (i = 0, ilen = items.length; i < ilen; ++i) {
        set.add(items[i]);
      }
      if (set.size === ilen) {
        return items;
      }
      return Array.from(set);
    }
    
    function _isDomSupported() {
      return typeof window !== 'undefined' && typeof document !== 'undefined';
    }
    function _getParentNode(domNode) {
      let parent = domNode.parentNode;
      if (parent && parent.toString() === '[object ShadowRoot]') {
        parent = parent.host;
      }
      return parent;
    }
    function parseMaxStyle(styleValue, node, parentProperty) {
      let valueInPixels;
      if (typeof styleValue === 'string') {
        valueInPixels = parseInt(styleValue, 10);
        if (styleValue.indexOf('%') !== -1) {
          valueInPixels = valueInPixels / 100 * node.parentNode[parentProperty];
        }
      } else {
        valueInPixels = styleValue;
      }
      return valueInPixels;
    }
    const getComputedStyle = (element) => window.getComputedStyle(element, null);
    function getStyle(el, property) {
      return getComputedStyle(el).getPropertyValue(property);
    }
    const positions = ['top', 'right', 'bottom', 'left'];
    function getPositionedStyle(styles, style, suffix) {
      const result = {};
      suffix = suffix ? '-' + suffix : '';
      for (let i = 0; i < 4; i++) {
        const pos = positions[i];
        result[pos] = parseFloat(styles[style + '-' + pos + suffix]) || 0;
      }
      result.width = result.left + result.right;
      result.height = result.top + result.bottom;
      return result;
    }
    const useOffsetPos = (x, y, target) => (x > 0 || y > 0) && (!target || !target.shadowRoot);
    function getCanvasPosition(evt, canvas) {
      const e = evt.native || evt;
      const touches = e.touches;
      const source = touches && touches.length ? touches[0] : e;
      const {offsetX, offsetY} = source;
      let box = false;
      let x, y;
      if (useOffsetPos(offsetX, offsetY, e.target)) {
        x = offsetX;
        y = offsetY;
      } else {
        const rect = canvas.getBoundingClientRect();
        x = source.clientX - rect.left;
        y = source.clientY - rect.top;
        box = true;
      }
      return {x, y, box};
    }
    function getRelativePosition$1(evt, chart) {
      const {canvas, currentDevicePixelRatio} = chart;
      const style = getComputedStyle(canvas);
      const borderBox = style.boxSizing === 'border-box';
      const paddings = getPositionedStyle(style, 'padding');
      const borders = getPositionedStyle(style, 'border', 'width');
      const {x, y, box} = getCanvasPosition(evt, canvas);
      const xOffset = paddings.left + (box && borders.left);
      const yOffset = paddings.top + (box && borders.top);
      let {width, height} = chart;
      if (borderBox) {
        width -= paddings.width + borders.width;
        height -= paddings.height + borders.height;
      }
      return {
        x: Math.round((x - xOffset) / width * canvas.width / currentDevicePixelRatio),
        y: Math.round((y - yOffset) / height * canvas.height / currentDevicePixelRatio)
      };
    }
    function getContainerSize(canvas, width, height) {
      let maxWidth, maxHeight;
      if (width === undefined || height === undefined) {
        const container = _getParentNode(canvas);
        if (!container) {
          width = canvas.clientWidth;
          height = canvas.clientHeight;
        } else {
          const rect = container.getBoundingClientRect();
          const containerStyle = getComputedStyle(container);
          const containerBorder = getPositionedStyle(containerStyle, 'border', 'width');
          const containerPadding = getPositionedStyle(containerStyle, 'padding');
          width = rect.width - containerPadding.width - containerBorder.width;
          height = rect.height - containerPadding.height - containerBorder.height;
          maxWidth = parseMaxStyle(containerStyle.maxWidth, container, 'clientWidth');
          maxHeight = parseMaxStyle(containerStyle.maxHeight, container, 'clientHeight');
        }
      }
      return {
        width,
        height,
        maxWidth: maxWidth || INFINITY,
        maxHeight: maxHeight || INFINITY
      };
    }
    const round1 = v => Math.round(v * 10) / 10;
    function getMaximumSize(canvas, bbWidth, bbHeight, aspectRatio) {
      const style = getComputedStyle(canvas);
      const margins = getPositionedStyle(style, 'margin');
      const maxWidth = parseMaxStyle(style.maxWidth, canvas, 'clientWidth') || INFINITY;
      const maxHeight = parseMaxStyle(style.maxHeight, canvas, 'clientHeight') || INFINITY;
      const containerSize = getContainerSize(canvas, bbWidth, bbHeight);
      let {width, height} = containerSize;
      if (style.boxSizing === 'content-box') {
        const borders = getPositionedStyle(style, 'border', 'width');
        const paddings = getPositionedStyle(style, 'padding');
        width -= paddings.width + borders.width;
        height -= paddings.height + borders.height;
      }
      width = Math.max(0, width - margins.width);
      height = Math.max(0, aspectRatio ? Math.floor(width / aspectRatio) : height - margins.height);
      width = round1(Math.min(width, maxWidth, containerSize.maxWidth));
      height = round1(Math.min(height, maxHeight, containerSize.maxHeight));
      if (width && !height) {
        height = round1(width / 2);
      }
      return {
        width,
        height
      };
    }
    function retinaScale(chart, forceRatio, forceStyle) {
      const pixelRatio = forceRatio || 1;
      const deviceHeight = Math.floor(chart.height * pixelRatio);
      const deviceWidth = Math.floor(chart.width * pixelRatio);
      chart.height = deviceHeight / pixelRatio;
      chart.width = deviceWidth / pixelRatio;
      const canvas = chart.canvas;
      if (canvas.style && (forceStyle || (!canvas.style.height && !canvas.style.width))) {
        canvas.style.height = `${chart.height}px`;
        canvas.style.width = `${chart.width}px`;
      }
      if (chart.currentDevicePixelRatio !== pixelRatio
          || canvas.height !== deviceHeight
          || canvas.width !== deviceWidth) {
        chart.currentDevicePixelRatio = pixelRatio;
        canvas.height = deviceHeight;
        canvas.width = deviceWidth;
        chart.ctx.setTransform(pixelRatio, 0, 0, pixelRatio, 0, 0);
        return true;
      }
      return false;
    }
    const supportsEventListenerOptions = (function() {
      let passiveSupported = false;
      try {
        const options = {
          get passive() {
            passiveSupported = true;
            return false;
          }
        };
        window.addEventListener('test', null, options);
        window.removeEventListener('test', null, options);
      } catch (e) {
      }
      return passiveSupported;
    }());
    function readUsedSize(element, property) {
      const value = getStyle(element, property);
      const matches = value && value.match(/^(\d+)(\.\d+)?px$/);
      return matches ? +matches[1] : undefined;
    }
    
    function getRelativePosition(e, chart) {
      if ('native' in e) {
        return {
          x: e.x,
          y: e.y
        };
      }
      return getRelativePosition$1(e, chart);
    }
    function evaluateAllVisibleItems(chart, handler) {
      const metasets = chart.getSortedVisibleDatasetMetas();
      let index, data, element;
      for (let i = 0, ilen = metasets.length; i < ilen; ++i) {
        ({index, data} = metasets[i]);
        for (let j = 0, jlen = data.length; j < jlen; ++j) {
          element = data[j];
          if (!element.skip) {
            handler(element, index, j);
          }
        }
      }
    }
    function binarySearch(metaset, axis, value, intersect) {
      const {controller, data, _sorted} = metaset;
      const iScale = controller._cachedMeta.iScale;
      if (iScale && axis === iScale.axis && _sorted && data.length) {
        const lookupMethod = iScale._reversePixels ? _rlookupByKey : _lookupByKey;
        if (!intersect) {
          return lookupMethod(data, axis, value);
        } else if (controller._sharedOptions) {
          const el = data[0];
          const range = typeof el.getRange === 'function' && el.getRange(axis);
          if (range) {
            const start = lookupMethod(data, axis, value - range);
            const end = lookupMethod(data, axis, value + range);
            return {lo: start.lo, hi: end.hi};
          }
        }
      }
      return {lo: 0, hi: data.length - 1};
    }
    function optimizedEvaluateItems(chart, axis, position, handler, intersect) {
      const metasets = chart.getSortedVisibleDatasetMetas();
      const value = position[axis];
      for (let i = 0, ilen = metasets.length; i < ilen; ++i) {
        const {index, data} = metasets[i];
        const {lo, hi} = binarySearch(metasets[i], axis, value, intersect);
        for (let j = lo; j <= hi; ++j) {
          const element = data[j];
          if (!element.skip) {
            handler(element, index, j);
          }
        }
      }
    }
    function getDistanceMetricForAxis(axis) {
      const useX = axis.indexOf('x') !== -1;
      const useY = axis.indexOf('y') !== -1;
      return function(pt1, pt2) {
        const deltaX = useX ? Math.abs(pt1.x - pt2.x) : 0;
        const deltaY = useY ? Math.abs(pt1.y - pt2.y) : 0;
        return Math.sqrt(Math.pow(deltaX, 2) + Math.pow(deltaY, 2));
      };
    }
    function getIntersectItems(chart, position, axis, useFinalPosition) {
      const items = [];
      if (!_isPointInArea(position, chart.chartArea, chart._minPadding)) {
        return items;
      }
      const evaluationFunc = function(element, datasetIndex, index) {
        if (element.inRange(position.x, position.y, useFinalPosition)) {
          items.push({element, datasetIndex, index});
        }
      };
      optimizedEvaluateItems(chart, axis, position, evaluationFunc, true);
      return items;
    }
    function getNearestItems(chart, position, axis, intersect, useFinalPosition) {
      const distanceMetric = getDistanceMetricForAxis(axis);
      let minDistance = Number.POSITIVE_INFINITY;
      let items = [];
      if (!_isPointInArea(position, chart.chartArea, chart._minPadding)) {
        return items;
      }
      const evaluationFunc = function(element, datasetIndex, index) {
        if (intersect && !element.inRange(position.x, position.y, useFinalPosition)) {
          return;
        }
        const center = element.getCenterPoint(useFinalPosition);
        if (!_isPointInArea(center, chart.chartArea, chart._minPadding) && !element.inRange(position.x, position.y, useFinalPosition)) {
          return;
        }
        const distance = distanceMetric(position, center);
        if (distance < minDistance) {
          items = [{element, datasetIndex, index}];
          minDistance = distance;
        } else if (distance === minDistance) {
          items.push({element, datasetIndex, index});
        }
      };
      optimizedEvaluateItems(chart, axis, position, evaluationFunc);
      return items;
    }
    function getAxisItems(chart, e, options, useFinalPosition) {
      const position = getRelativePosition(e, chart);
      const items = [];
      const axis = options.axis;
      const rangeMethod = axis === 'x' ? 'inXRange' : 'inYRange';
      let intersectsItem = false;
      evaluateAllVisibleItems(chart, (element, datasetIndex, index) => {
        if (element[rangeMethod](position[axis], useFinalPosition)) {
          items.push({element, datasetIndex, index});
        }
        if (element.inRange(position.x, position.y, useFinalPosition)) {
          intersectsItem = true;
        }
      });
      if (options.intersect && !intersectsItem) {
        return [];
      }
      return items;
    }
    var Interaction = {
      modes: {
        index(chart, e, options, useFinalPosition) {
          const position = getRelativePosition(e, chart);
          const axis = options.axis || 'x';
          const items = options.intersect
            ? getIntersectItems(chart, position, axis, useFinalPosition)
            : getNearestItems(chart, position, axis, false, useFinalPosition);
          const elements = [];
          if (!items.length) {
            return [];
          }
          chart.getSortedVisibleDatasetMetas().forEach((meta) => {
            const index = items[0].index;
            const element = meta.data[index];
            if (element && !element.skip) {
              elements.push({element, datasetIndex: meta.index, index});
            }
          });
          return elements;
        },
        dataset(chart, e, options, useFinalPosition) {
          const position = getRelativePosition(e, chart);
          const axis = options.axis || 'xy';
          let items = options.intersect
            ? getIntersectItems(chart, position, axis, useFinalPosition) :
            getNearestItems(chart, position, axis, false, useFinalPosition);
          if (items.length > 0) {
            const datasetIndex = items[0].datasetIndex;
            const data = chart.getDatasetMeta(datasetIndex).data;
            items = [];
            for (let i = 0; i < data.length; ++i) {
              items.push({element: data[i], datasetIndex, index: i});
            }
          }
          return items;
        },
        point(chart, e, options, useFinalPosition) {
          const position = getRelativePosition(e, chart);
          const axis = options.axis || 'xy';
          return getIntersectItems(chart, position, axis, useFinalPosition);
        },
        nearest(chart, e, options, useFinalPosition) {
          const position = getRelativePosition(e, chart);
          const axis = options.axis || 'xy';
          return getNearestItems(chart, position, axis, options.intersect, useFinalPosition);
        },
        x(chart, e, options, useFinalPosition) {
          options.axis = 'x';
          return getAxisItems(chart, e, options, useFinalPosition);
        },
        y(chart, e, options, useFinalPosition) {
          options.axis = 'y';
          return getAxisItems(chart, e, options, useFinalPosition);
        }
      }
    };
    
    const LINE_HEIGHT = new RegExp(/^(normal|(\d+(?:\.\d+)?)(px|em|%)?)$/);
    const FONT_STYLE = new RegExp(/^(normal|italic|initial|inherit|unset|(oblique( -?[0-9]?[0-9]deg)?))$/);
    function toLineHeight(value, size) {
      const matches = ('' + value).match(LINE_HEIGHT);
      if (!matches || matches[1] === 'normal') {
        return size * 1.2;
      }
      value = +matches[2];
      switch (matches[3]) {
      case 'px':
        return value;
      case '%':
        value /= 100;
        break;
      }
      return size * value;
    }
    const numberOrZero$1 = v => +v || 0;
    function _readValueToProps(value, props) {
      const ret = {};
      const objProps = isObject(props);
      const keys = objProps ? Object.keys(props) : props;
      const read = isObject(value)
        ? objProps
          ? prop => valueOrDefault(value[prop], value[props[prop]])
          : prop => value[prop]
        : () => value;
      for (const prop of keys) {
        ret[prop] = numberOrZero$1(read(prop));
      }
      return ret;
    }
    function toTRBL(value) {
      return _readValueToProps(value, {top: 'y', right: 'x', bottom: 'y', left: 'x'});
    }
    function toTRBLCorners(value) {
      return _readValueToProps(value, ['topLeft', 'topRight', 'bottomLeft', 'bottomRight']);
    }
    function toPadding(value) {
      const obj = toTRBL(value);
      obj.width = obj.left + obj.right;
      obj.height = obj.top + obj.bottom;
      return obj;
    }
    function toFont(options, fallback) {
      options = options || {};
      fallback = fallback || defaults.font;
      let size = valueOrDefault(options.size, fallback.size);
      if (typeof size === 'string') {
        size = parseInt(size, 10);
      }
      let style = valueOrDefault(options.style, fallback.style);
      if (style && !('' + style).match(FONT_STYLE)) {
        console.warn('Invalid font style specified: "' + style + '"');
        style = '';
      }
      const font = {
        family: valueOrDefault(options.family, fallback.family),
        lineHeight: toLineHeight(valueOrDefault(options.lineHeight, fallback.lineHeight), size),
        size,
        style,
        weight: valueOrDefault(options.weight, fallback.weight),
        string: ''
      };
      font.string = toFontString(font);
      return font;
    }
    function resolve(inputs, context, index, info) {
      let cacheable = true;
      let i, ilen, value;
      for (i = 0, ilen = inputs.length; i < ilen; ++i) {
        value = inputs[i];
        if (value === undefined) {
          continue;
        }
        if (context !== undefined && typeof value === 'function') {
          value = value(context);
          cacheable = false;
        }
        if (index !== undefined && isArray(value)) {
          value = value[index % value.length];
          cacheable = false;
        }
        if (value !== undefined) {
          if (info && !cacheable) {
            info.cacheable = false;
          }
          return value;
        }
      }
    }
    function _addGrace(minmax, grace) {
      const {min, max} = minmax;
      return {
        min: min - Math.abs(toDimension(grace, min)),
        max: max + toDimension(grace, max)
      };
    }
    
    const STATIC_POSITIONS = ['left', 'top', 'right', 'bottom'];
    function filterByPosition(array, position) {
      return array.filter(v => v.pos === position);
    }
    function filterDynamicPositionByAxis(array, axis) {
      return array.filter(v => STATIC_POSITIONS.indexOf(v.pos) === -1 && v.box.axis === axis);
    }
    function sortByWeight(array, reverse) {
      return array.sort((a, b) => {
        const v0 = reverse ? b : a;
        const v1 = reverse ? a : b;
        return v0.weight === v1.weight ?
          v0.index - v1.index :
          v0.weight - v1.weight;
      });
    }
    function wrapBoxes(boxes) {
      const layoutBoxes = [];
      let i, ilen, box, pos, stack, stackWeight;
      for (i = 0, ilen = (boxes || []).length; i < ilen; ++i) {
        box = boxes[i];
        ({position: pos, options: {stack, stackWeight = 1}} = box);
        layoutBoxes.push({
          index: i,
          box,
          pos,
          horizontal: box.isHorizontal(),
          weight: box.weight,
          stack: stack && (pos + stack),
          stackWeight
        });
      }
      return layoutBoxes;
    }
    function buildStacks(layouts) {
      const stacks = {};
      for (const wrap of layouts) {
        const {stack, pos, stackWeight} = wrap;
        if (!stack || !STATIC_POSITIONS.includes(pos)) {
          continue;
        }
        const _stack = stacks[stack] || (stacks[stack] = {count: 0, placed: 0, weight: 0, size: 0});
        _stack.count++;
        _stack.weight += stackWeight;
      }
      return stacks;
    }
    function setLayoutDims(layouts, params) {
      const stacks = buildStacks(layouts);
      const {vBoxMaxWidth, hBoxMaxHeight} = params;
      let i, ilen, layout;
      for (i = 0, ilen = layouts.length; i < ilen; ++i) {
        layout = layouts[i];
        const {fullSize} = layout.box;
        const stack = stacks[layout.stack];
        const factor = stack && layout.stackWeight / stack.weight;
        if (layout.horizontal) {
          layout.width = factor ? factor * vBoxMaxWidth : fullSize && params.availableWidth;
          layout.height = hBoxMaxHeight;
        } else {
          layout.width = vBoxMaxWidth;
          layout.height = factor ? factor * hBoxMaxHeight : fullSize && params.availableHeight;
        }
      }
      return stacks;
    }
    function buildLayoutBoxes(boxes) {
      const layoutBoxes = wrapBoxes(boxes);
      const fullSize = sortByWeight(layoutBoxes.filter(wrap => wrap.box.fullSize), true);
      const left = sortByWeight(filterByPosition(layoutBoxes, 'left'), true);
      const right = sortByWeight(filterByPosition(layoutBoxes, 'right'));
      const top = sortByWeight(filterByPosition(layoutBoxes, 'top'), true);
      const bottom = sortByWeight(filterByPosition(layoutBoxes, 'bottom'));
      const centerHorizontal = filterDynamicPositionByAxis(layoutBoxes, 'x');
      const centerVertical = filterDynamicPositionByAxis(layoutBoxes, 'y');
      return {
        fullSize,
        leftAndTop: left.concat(top),
        rightAndBottom: right.concat(centerVertical).concat(bottom).concat(centerHorizontal),
        chartArea: filterByPosition(layoutBoxes, 'chartArea'),
        vertical: left.concat(right).concat(centerVertical),
        horizontal: top.concat(bottom).concat(centerHorizontal)
      };
    }
    function getCombinedMax(maxPadding, chartArea, a, b) {
      return Math.max(maxPadding[a], chartArea[a]) + Math.max(maxPadding[b], chartArea[b]);
    }
    function updateMaxPadding(maxPadding, boxPadding) {
      maxPadding.top = Math.max(maxPadding.top, boxPadding.top);
      maxPadding.left = Math.max(maxPadding.left, boxPadding.left);
      maxPadding.bottom = Math.max(maxPadding.bottom, boxPadding.bottom);
      maxPadding.right = Math.max(maxPadding.right, boxPadding.right);
    }
    function updateDims(chartArea, params, layout, stacks) {
      const {pos, box} = layout;
      const maxPadding = chartArea.maxPadding;
      if (!isObject(pos)) {
        if (layout.size) {
          chartArea[pos] -= layout.size;
        }
        const stack = stacks[layout.stack] || {size: 0, count: 1};
        stack.size = Math.max(stack.size, layout.horizontal ? box.height : box.width);
        layout.size = stack.size / stack.count;
        chartArea[pos] += layout.size;
      }
      if (box.getPadding) {
        updateMaxPadding(maxPadding, box.getPadding());
      }
      const newWidth = Math.max(0, params.outerWidth - getCombinedMax(maxPadding, chartArea, 'left', 'right'));
      const newHeight = Math.max(0, params.outerHeight - getCombinedMax(maxPadding, chartArea, 'top', 'bottom'));
      const widthChanged = newWidth !== chartArea.w;
      const heightChanged = newHeight !== chartArea.h;
      chartArea.w = newWidth;
      chartArea.h = newHeight;
      return layout.horizontal
        ? {same: widthChanged, other: heightChanged}
        : {same: heightChanged, other: widthChanged};
    }
    function handleMaxPadding(chartArea) {
      const maxPadding = chartArea.maxPadding;
      function updatePos(pos) {
        const change = Math.max(maxPadding[pos] - chartArea[pos], 0);
        chartArea[pos] += change;
        return change;
      }
      chartArea.y += updatePos('top');
      chartArea.x += updatePos('left');
      updatePos('right');
      updatePos('bottom');
    }
    function getMargins(horizontal, chartArea) {
      const maxPadding = chartArea.maxPadding;
      function marginForPositions(positions) {
        const margin = {left: 0, top: 0, right: 0, bottom: 0};
        positions.forEach((pos) => {
          margin[pos] = Math.max(chartArea[pos], maxPadding[pos]);
        });
        return margin;
      }
      return horizontal
        ? marginForPositions(['left', 'right'])
        : marginForPositions(['top', 'bottom']);
    }
    function fitBoxes(boxes, chartArea, params, stacks) {
      const refitBoxes = [];
      let i, ilen, layout, box, refit, changed;
      for (i = 0, ilen = boxes.length, refit = 0; i < ilen; ++i) {
        layout = boxes[i];
        box = layout.box;
        box.update(
          layout.width || chartArea.w,
          layout.height || chartArea.h,
          getMargins(layout.horizontal, chartArea)
        );
        const {same, other} = updateDims(chartArea, params, layout, stacks);
        refit |= same && refitBoxes.length;
        changed = changed || other;
        if (!box.fullSize) {
          refitBoxes.push(layout);
        }
      }
      return refit && fitBoxes(refitBoxes, chartArea, params, stacks) || changed;
    }
    function setBoxDims(box, left, top, width, height) {
      box.top = top;
      box.left = left;
      box.right = left + width;
      box.bottom = top + height;
      box.width = width;
      box.height = height;
    }
    function placeBoxes(boxes, chartArea, params, stacks) {
      const userPadding = params.padding;
      let {x, y} = chartArea;
      for (const layout of boxes) {
        const box = layout.box;
        const stack = stacks[layout.stack] || {count: 1, placed: 0, weight: 1};
        const weight = (layout.stackWeight / stack.weight) || 1;
        if (layout.horizontal) {
          const width = chartArea.w * weight;
          const height = stack.size || box.height;
          if (defined(stack.start)) {
            y = stack.start;
          }
          if (box.fullSize) {
            setBoxDims(box, userPadding.left, y, params.outerWidth - userPadding.right - userPadding.left, height);
          } else {
            setBoxDims(box, chartArea.left + stack.placed, y, width, height);
          }
          stack.start = y;
          stack.placed += width;
          y = box.bottom;
        } else {
          const height = chartArea.h * weight;
          const width = stack.size || box.width;
          if (defined(stack.start)) {
            x = stack.start;
          }
          if (box.fullSize) {
            setBoxDims(box, x, userPadding.top, width, params.outerHeight - userPadding.bottom - userPadding.top);
          } else {
            setBoxDims(box, x, chartArea.top + stack.placed, width, height);
          }
          stack.start = x;
          stack.placed += height;
          x = box.right;
        }
      }
      chartArea.x = x;
      chartArea.y = y;
    }
    defaults.set('layout', {
      padding: {
        top: 0,
        right: 0,
        bottom: 0,
        left: 0
      }
    });
    var layouts = {
      addBox(chart, item) {
        if (!chart.boxes) {
          chart.boxes = [];
        }
        item.fullSize = item.fullSize || false;
        item.position = item.position || 'top';
        item.weight = item.weight || 0;
        item._layers = item._layers || function() {
          return [{
            z: 0,
            draw(chartArea) {
              item.draw(chartArea);
            }
          }];
        };
        chart.boxes.push(item);
      },
      removeBox(chart, layoutItem) {
        const index = chart.boxes ? chart.boxes.indexOf(layoutItem) : -1;
        if (index !== -1) {
          chart.boxes.splice(index, 1);
        }
      },
      configure(chart, item, options) {
        item.fullSize = options.fullSize;
        item.position = options.position;
        item.weight = options.weight;
      },
      update(chart, width, height, minPadding) {
        if (!chart) {
          return;
        }
        const padding = toPadding(chart.options.layout.padding);
        const availableWidth = Math.max(width - padding.width, 0);
        const availableHeight = Math.max(height - padding.height, 0);
        const boxes = buildLayoutBoxes(chart.boxes);
        const verticalBoxes = boxes.vertical;
        const horizontalBoxes = boxes.horizontal;
        each(chart.boxes, box => {
          if (typeof box.beforeLayout === 'function') {
            box.beforeLayout();
          }
        });
        const visibleVerticalBoxCount = verticalBoxes.reduce((total, wrap) =>
          wrap.box.options && wrap.box.options.display === false ? total : total + 1, 0) || 1;
        const params = Object.freeze({
          outerWidth: width,
          outerHeight: height,
          padding,
          availableWidth,
          availableHeight,
          vBoxMaxWidth: availableWidth / 2 / visibleVerticalBoxCount,
          hBoxMaxHeight: availableHeight / 2
        });
        const maxPadding = Object.assign({}, padding);
        updateMaxPadding(maxPadding, toPadding(minPadding));
        const chartArea = Object.assign({
          maxPadding,
          w: availableWidth,
          h: availableHeight,
          x: padding.left,
          y: padding.top
        }, padding);
        const stacks = setLayoutDims(verticalBoxes.concat(horizontalBoxes), params);
        fitBoxes(boxes.fullSize, chartArea, params, stacks);
        fitBoxes(verticalBoxes, chartArea, params, stacks);
        if (fitBoxes(horizontalBoxes, chartArea, params, stacks)) {
          fitBoxes(verticalBoxes, chartArea, params, stacks);
        }
        handleMaxPadding(chartArea);
        placeBoxes(boxes.leftAndTop, chartArea, params, stacks);
        chartArea.x += chartArea.w;
        chartArea.y += chartArea.h;
        placeBoxes(boxes.rightAndBottom, chartArea, params, stacks);
        chart.chartArea = {
          left: chartArea.left,
          top: chartArea.top,
          right: chartArea.left + chartArea.w,
          bottom: chartArea.top + chartArea.h,
          height: chartArea.h,
          width: chartArea.w,
        };
        each(boxes.chartArea, (layout) => {
          const box = layout.box;
          Object.assign(box, chart.chartArea);
          box.update(chartArea.w, chartArea.h);
        });
      }
    };
    
    function _createResolver(scopes, prefixes = [''], rootScopes = scopes, fallback, getTarget = () => scopes[0]) {
      if (!defined(fallback)) {
        fallback = _resolve('_fallback', scopes);
      }
      const cache = {
        [Symbol.toStringTag]: 'Object',
        _cacheable: true,
        _scopes: scopes,
        _rootScopes: rootScopes,
        _fallback: fallback,
        _getTarget: getTarget,
        override: (scope) => _createResolver([scope, ...scopes], prefixes, rootScopes, fallback),
      };
      return new Proxy(cache, {
        deleteProperty(target, prop) {
          delete target[prop];
          delete target._keys;
          delete scopes[0][prop];
          return true;
        },
        get(target, prop) {
          return _cached(target, prop,
            () => _resolveWithPrefixes(prop, prefixes, scopes, target));
        },
        getOwnPropertyDescriptor(target, prop) {
          return Reflect.getOwnPropertyDescriptor(target._scopes[0], prop);
        },
        getPrototypeOf() {
          return Reflect.getPrototypeOf(scopes[0]);
        },
        has(target, prop) {
          return getKeysFromAllScopes(target).includes(prop);
        },
        ownKeys(target) {
          return getKeysFromAllScopes(target);
        },
        set(target, prop, value) {
          const storage = target._storage || (target._storage = getTarget());
          storage[prop] = value;
          delete target[prop];
          delete target._keys;
          return true;
        }
      });
    }
    function _attachContext(proxy, context, subProxy, descriptorDefaults) {
      const cache = {
        _cacheable: false,
        _proxy: proxy,
        _context: context,
        _subProxy: subProxy,
        _stack: new Set(),
        _descriptors: _descriptors(proxy, descriptorDefaults),
        setContext: (ctx) => _attachContext(proxy, ctx, subProxy, descriptorDefaults),
        override: (scope) => _attachContext(proxy.override(scope), context, subProxy, descriptorDefaults)
      };
      return new Proxy(cache, {
        deleteProperty(target, prop) {
          delete target[prop];
          delete proxy[prop];
          return true;
        },
        get(target, prop, receiver) {
          return _cached(target, prop,
            () => _resolveWithContext(target, prop, receiver));
        },
        getOwnPropertyDescriptor(target, prop) {
          return target._descriptors.allKeys
            ? Reflect.has(proxy, prop) ? {enumerable: true, configurable: true} : undefined
            : Reflect.getOwnPropertyDescriptor(proxy, prop);
        },
        getPrototypeOf() {
          return Reflect.getPrototypeOf(proxy);
        },
        has(target, prop) {
          return Reflect.has(proxy, prop);
        },
        ownKeys() {
          return Reflect.ownKeys(proxy);
        },
        set(target, prop, value) {
          proxy[prop] = value;
          delete target[prop];
          return true;
        }
      });
    }
    function _descriptors(proxy, defaults = {scriptable: true, indexable: true}) {
      const {_scriptable = defaults.scriptable, _indexable = defaults.indexable, _allKeys = defaults.allKeys} = proxy;
      return {
        allKeys: _allKeys,
        scriptable: _scriptable,
        indexable: _indexable,
        isScriptable: isFunction(_scriptable) ? _scriptable : () => _scriptable,
        isIndexable: isFunction(_indexable) ? _indexable : () => _indexable
      };
    }
    const readKey = (prefix, name) => prefix ? prefix + _capitalize(name) : name;
    const needsSubResolver = (prop, value) => isObject(value) && prop !== 'adapters';
    function _cached(target, prop, resolve) {
      let value = target[prop];
      if (defined(value)) {
        return value;
      }
      value = resolve();
      if (defined(value)) {
        target[prop] = value;
      }
      return value;
    }
    function _resolveWithContext(target, prop, receiver) {
      const {_proxy, _context, _subProxy, _descriptors: descriptors} = target;
      let value = _proxy[prop];
      if (isFunction(value) && descriptors.isScriptable(prop)) {
        value = _resolveScriptable(prop, value, target, receiver);
      }
      if (isArray(value) && value.length) {
        value = _resolveArray(prop, value, target, descriptors.isIndexable);
      }
      if (needsSubResolver(prop, value)) {
        value = _attachContext(value, _context, _subProxy && _subProxy[prop], descriptors);
      }
      return value;
    }
    function _resolveScriptable(prop, value, target, receiver) {
      const {_proxy, _context, _subProxy, _stack} = target;
      if (_stack.has(prop)) {
        throw new Error('Recursion detected: ' + Array.from(_stack).join('->') + '->' + prop);
      }
      _stack.add(prop);
      value = value(_context, _subProxy || receiver);
      _stack.delete(prop);
      if (isObject(value)) {
        value = createSubResolver(_proxy._scopes, _proxy, prop, value);
      }
      return value;
    }
    function _resolveArray(prop, value, target, isIndexable) {
      const {_proxy, _context, _subProxy, _descriptors: descriptors} = target;
      if (defined(_context.index) && isIndexable(prop)) {
        value = value[_context.index % value.length];
      } else if (isObject(value[0])) {
        const arr = value;
        const scopes = _proxy._scopes.filter(s => s !== arr);
        value = [];
        for (const item of arr) {
          const resolver = createSubResolver(scopes, _proxy, prop, item);
          value.push(_attachContext(resolver, _context, _subProxy && _subProxy[prop], descriptors));
        }
      }
      return value;
    }
    function resolveFallback(fallback, prop, value) {
      return isFunction(fallback) ? fallback(prop, value) : fallback;
    }
    const getScope = (key, parent) => key === true ? parent
      : typeof key === 'string' ? resolveObjectKey(parent, key) : undefined;
    function addScopes(set, parentScopes, key, parentFallback) {
      for (const parent of parentScopes) {
        const scope = getScope(key, parent);
        if (scope) {
          set.add(scope);
          const fallback = resolveFallback(scope._fallback, key, scope);
          if (defined(fallback) && fallback !== key && fallback !== parentFallback) {
            return fallback;
          }
        } else if (scope === false && defined(parentFallback) && key !== parentFallback) {
          return null;
        }
      }
      return false;
    }
    function createSubResolver(parentScopes, resolver, prop, value) {
      const rootScopes = resolver._rootScopes;
      const fallback = resolveFallback(resolver._fallback, prop, value);
      const allScopes = [...parentScopes, ...rootScopes];
      const set = new Set();
      set.add(value);
      let key = addScopesFromKey(set, allScopes, prop, fallback || prop);
      if (key === null) {
        return false;
      }
      if (defined(fallback) && fallback !== prop) {
        key = addScopesFromKey(set, allScopes, fallback, key);
        if (key === null) {
          return false;
        }
      }
      return _createResolver(Array.from(set), [''], rootScopes, fallback,
        () => subGetTarget(resolver, prop, value));
    }
    function addScopesFromKey(set, allScopes, key, fallback) {
      while (key) {
        key = addScopes(set, allScopes, key, fallback);
      }
      return key;
    }
    function subGetTarget(resolver, prop, value) {
      const parent = resolver._getTarget();
      if (!(prop in parent)) {
        parent[prop] = {};
      }
      const target = parent[prop];
      if (isArray(target) && isObject(value)) {
        return value;
      }
      return target;
    }
    function _resolveWithPrefixes(prop, prefixes, scopes, proxy) {
      let value;
      for (const prefix of prefixes) {
        value = _resolve(readKey(prefix, prop), scopes);
        if (defined(value)) {
          return needsSubResolver(prop, value)
            ? createSubResolver(scopes, proxy, prop, value)
            : value;
        }
      }
    }
    function _resolve(key, scopes) {
      for (const scope of scopes) {
        if (!scope) {
          continue;
        }
        const value = scope[key];
        if (defined(value)) {
          return value;
        }
      }
    }
    function getKeysFromAllScopes(target) {
      let keys = target._keys;
      if (!keys) {
        keys = target._keys = resolveKeysFromAllScopes(target._scopes);
      }
      return keys;
    }
    function resolveKeysFromAllScopes(scopes) {
      const set = new Set();
      for (const scope of scopes) {
        for (const key of Object.keys(scope).filter(k => !k.startsWith('_'))) {
          set.add(key);
        }
      }
      return Array.from(set);
    }
    
    const EPSILON = Number.EPSILON || 1e-14;
    const getPoint = (points, i) => i < points.length && !points[i].skip && points[i];
    const getValueAxis = (indexAxis) => indexAxis === 'x' ? 'y' : 'x';
    function splineCurve(firstPoint, middlePoint, afterPoint, t) {
      const previous = firstPoint.skip ? middlePoint : firstPoint;
      const current = middlePoint;
      const next = afterPoint.skip ? middlePoint : afterPoint;
      const d01 = distanceBetweenPoints(current, previous);
      const d12 = distanceBetweenPoints(next, current);
      let s01 = d01 / (d01 + d12);
      let s12 = d12 / (d01 + d12);
      s01 = isNaN(s01) ? 0 : s01;
      s12 = isNaN(s12) ? 0 : s12;
      const fa = t * s01;
      const fb = t * s12;
      return {
        previous: {
          x: current.x - fa * (next.x - previous.x),
          y: current.y - fa * (next.y - previous.y)
        },
        next: {
          x: current.x + fb * (next.x - previous.x),
          y: current.y + fb * (next.y - previous.y)
        }
      };
    }
    function monotoneAdjust(points, deltaK, mK) {
      const pointsLen = points.length;
      let alphaK, betaK, tauK, squaredMagnitude, pointCurrent;
      let pointAfter = getPoint(points, 0);
      for (let i = 0; i < pointsLen - 1; ++i) {
        pointCurrent = pointAfter;
        pointAfter = getPoint(points, i + 1);
        if (!pointCurrent || !pointAfter) {
          continue;
        }
        if (almostEquals(deltaK[i], 0, EPSILON)) {
          mK[i] = mK[i + 1] = 0;
          continue;
        }
        alphaK = mK[i] / deltaK[i];
        betaK = mK[i + 1] / deltaK[i];
        squaredMagnitude = Math.pow(alphaK, 2) + Math.pow(betaK, 2);
        if (squaredMagnitude <= 9) {
          continue;
        }
        tauK = 3 / Math.sqrt(squaredMagnitude);
        mK[i] = alphaK * tauK * deltaK[i];
        mK[i + 1] = betaK * tauK * deltaK[i];
      }
    }
    function monotoneCompute(points, mK, indexAxis = 'x') {
      const valueAxis = getValueAxis(indexAxis);
      const pointsLen = points.length;
      let delta, pointBefore, pointCurrent;
      let pointAfter = getPoint(points, 0);
      for (let i = 0; i < pointsLen; ++i) {
        pointBefore = pointCurrent;
        pointCurrent = pointAfter;
        pointAfter = getPoint(points, i + 1);
        if (!pointCurrent) {
          continue;
        }
        const iPixel = pointCurrent[indexAxis];
        const vPixel = pointCurrent[valueAxis];
        if (pointBefore) {
          delta = (iPixel - pointBefore[indexAxis]) / 3;
          pointCurrent[`cp1${indexAxis}`] = iPixel - delta;
          pointCurrent[`cp1${valueAxis}`] = vPixel - delta * mK[i];
        }
        if (pointAfter) {
          delta = (pointAfter[indexAxis] - iPixel) / 3;
          pointCurrent[`cp2${indexAxis}`] = iPixel + delta;
          pointCurrent[`cp2${valueAxis}`] = vPixel + delta * mK[i];
        }
      }
    }
    function splineCurveMonotone(points, indexAxis = 'x') {
      const valueAxis = getValueAxis(indexAxis);
      const pointsLen = points.length;
      const deltaK = Array(pointsLen).fill(0);
      const mK = Array(pointsLen);
      let i, pointBefore, pointCurrent;
      let pointAfter = getPoint(points, 0);
      for (i = 0; i < pointsLen; ++i) {
        pointBefore = pointCurrent;
        pointCurrent = pointAfter;
        pointAfter = getPoint(points, i + 1);
        if (!pointCurrent) {
          continue;
        }
        if (pointAfter) {
          const slopeDelta = pointAfter[indexAxis] - pointCurrent[indexAxis];
          deltaK[i] = slopeDelta !== 0 ? (pointAfter[valueAxis] - pointCurrent[valueAxis]) / slopeDelta : 0;
        }
        mK[i] = !pointBefore ? deltaK[i]
          : !pointAfter ? deltaK[i - 1]
          : (sign(deltaK[i - 1]) !== sign(deltaK[i])) ? 0
          : (deltaK[i - 1] + deltaK[i]) / 2;
      }
      monotoneAdjust(points, deltaK, mK);
      monotoneCompute(points, mK, indexAxis);
    }
    function capControlPoint(pt, min, max) {
      return Math.max(Math.min(pt, max), min);
    }
    function capBezierPoints(points, area) {
      let i, ilen, point, inArea, inAreaPrev;
      let inAreaNext = _isPointInArea(points[0], area);
      for (i = 0, ilen = points.length; i < ilen; ++i) {
        inAreaPrev = inArea;
        inArea = inAreaNext;
        inAreaNext = i < ilen - 1 && _isPointInArea(points[i + 1], area);
        if (!inArea) {
          continue;
        }
        point = points[i];
        if (inAreaPrev) {
          point.cp1x = capControlPoint(point.cp1x, area.left, area.right);
          point.cp1y = capControlPoint(point.cp1y, area.top, area.bottom);
        }
        if (inAreaNext) {
          point.cp2x = capControlPoint(point.cp2x, area.left, area.right);
          point.cp2y = capControlPoint(point.cp2y, area.top, area.bottom);
        }
      }
    }
    function _updateBezierControlPoints(points, options, area, loop, indexAxis) {
      let i, ilen, point, controlPoints;
      if (options.spanGaps) {
        points = points.filter((pt) => !pt.skip);
      }
      if (options.cubicInterpolationMode === 'monotone') {
        splineCurveMonotone(points, indexAxis);
      } else {
        let prev = loop ? points[points.length - 1] : points[0];
        for (i = 0, ilen = points.length; i < ilen; ++i) {
          point = points[i];
          controlPoints = splineCurve(
            prev,
            point,
            points[Math.min(i + 1, ilen - (loop ? 0 : 1)) % ilen],
            options.tension
          );
          point.cp1x = controlPoints.previous.x;
          point.cp1y = controlPoints.previous.y;
          point.cp2x = controlPoints.next.x;
          point.cp2y = controlPoints.next.y;
          prev = point;
        }
      }
      if (options.capBezierPoints) {
        capBezierPoints(points, area);
      }
    }
    
    const atEdge = (t) => t === 0 || t === 1;
    const elasticIn = (t, s, p) => -(Math.pow(2, 10 * (t -= 1)) * Math.sin((t - s) * TAU / p));
    const elasticOut = (t, s, p) => Math.pow(2, -10 * t) * Math.sin((t - s) * TAU / p) + 1;
    const effects = {
      linear: t => t,
      easeInQuad: t => t * t,
      easeOutQuad: t => -t * (t - 2),
      easeInOutQuad: t => ((t /= 0.5) < 1)
        ? 0.5 * t * t
        : -0.5 * ((--t) * (t - 2) - 1),
      easeInCubic: t => t * t * t,
      easeOutCubic: t => (t -= 1) * t * t + 1,
      easeInOutCubic: t => ((t /= 0.5) < 1)
        ? 0.5 * t * t * t
        : 0.5 * ((t -= 2) * t * t + 2),
      easeInQuart: t => t * t * t * t,
      easeOutQuart: t => -((t -= 1) * t * t * t - 1),
      easeInOutQuart: t => ((t /= 0.5) < 1)
        ? 0.5 * t * t * t * t
        : -0.5 * ((t -= 2) * t * t * t - 2),
      easeInQuint: t => t * t * t * t * t,
      easeOutQuint: t => (t -= 1) * t * t * t * t + 1,
      easeInOutQuint: t => ((t /= 0.5) < 1)
        ? 0.5 * t * t * t * t * t
        : 0.5 * ((t -= 2) * t * t * t * t + 2),
      easeInSine: t => -Math.cos(t * HALF_PI) + 1,
      easeOutSine: t => Math.sin(t * HALF_PI),
      easeInOutSine: t => -0.5 * (Math.cos(PI * t) - 1),
      easeInExpo: t => (t === 0) ? 0 : Math.pow(2, 10 * (t - 1)),
      easeOutExpo: t => (t === 1) ? 1 : -Math.pow(2, -10 * t) + 1,
      easeInOutExpo: t => atEdge(t) ? t : t < 0.5
        ? 0.5 * Math.pow(2, 10 * (t * 2 - 1))
        : 0.5 * (-Math.pow(2, -10 * (t * 2 - 1)) + 2),
      easeInCirc: t => (t >= 1) ? t : -(Math.sqrt(1 - t * t) - 1),
      easeOutCirc: t => Math.sqrt(1 - (t -= 1) * t),
      easeInOutCirc: t => ((t /= 0.5) < 1)
        ? -0.5 * (Math.sqrt(1 - t * t) - 1)
        : 0.5 * (Math.sqrt(1 - (t -= 2) * t) + 1),
      easeInElastic: t => atEdge(t) ? t : elasticIn(t, 0.075, 0.3),
      easeOutElastic: t => atEdge(t) ? t : elasticOut(t, 0.075, 0.3),
      easeInOutElastic(t) {
        const s = 0.1125;
        const p = 0.45;
        return atEdge(t) ? t :
          t < 0.5
            ? 0.5 * elasticIn(t * 2, s, p)
            : 0.5 + 0.5 * elasticOut(t * 2 - 1, s, p);
      },
      easeInBack(t) {
        const s = 1.70158;
        return t * t * ((s + 1) * t - s);
      },
      easeOutBack(t) {
        const s = 1.70158;
        return (t -= 1) * t * ((s + 1) * t + s) + 1;
      },
      easeInOutBack(t) {
        let s = 1.70158;
        if ((t /= 0.5) < 1) {
          return 0.5 * (t * t * (((s *= (1.525)) + 1) * t - s));
        }
        return 0.5 * ((t -= 2) * t * (((s *= (1.525)) + 1) * t + s) + 2);
      },
      easeInBounce: t => 1 - effects.easeOutBounce(1 - t),
      easeOutBounce(t) {
        const m = 7.5625;
        const d = 2.75;
        if (t < (1 / d)) {
          return m * t * t;
        }
        if (t < (2 / d)) {
          return m * (t -= (1.5 / d)) * t + 0.75;
        }
        if (t < (2.5 / d)) {
          return m * (t -= (2.25 / d)) * t + 0.9375;
        }
        return m * (t -= (2.625 / d)) * t + 0.984375;
      },
      easeInOutBounce: t => (t < 0.5)
        ? effects.easeInBounce(t * 2) * 0.5
        : effects.easeOutBounce(t * 2 - 1) * 0.5 + 0.5,
    };
    
    function _pointInLine(p1, p2, t, mode) {
      return {
        x: p1.x + t * (p2.x - p1.x),
        y: p1.y + t * (p2.y - p1.y)
      };
    }
    function _steppedInterpolation(p1, p2, t, mode) {
      return {
        x: p1.x + t * (p2.x - p1.x),
        y: mode === 'middle' ? t < 0.5 ? p1.y : p2.y
        : mode === 'after' ? t < 1 ? p1.y : p2.y
        : t > 0 ? p2.y : p1.y
      };
    }
    function _bezierInterpolation(p1, p2, t, mode) {
      const cp1 = {x: p1.cp2x, y: p1.cp2y};
      const cp2 = {x: p2.cp1x, y: p2.cp1y};
      const a = _pointInLine(p1, cp1, t);
      const b = _pointInLine(cp1, cp2, t);
      const c = _pointInLine(cp2, p2, t);
      const d = _pointInLine(a, b, t);
      const e = _pointInLine(b, c, t);
      return _pointInLine(d, e, t);
    }
    
    const intlCache = new Map();
    function getNumberFormat(locale, options) {
      options = options || {};
      const cacheKey = locale + JSON.stringify(options);
      let formatter = intlCache.get(cacheKey);
      if (!formatter) {
        formatter = new Intl.NumberFormat(locale, options);
        intlCache.set(cacheKey, formatter);
      }
      return formatter;
    }
    function formatNumber(num, locale, options) {
      return getNumberFormat(locale, options).format(num);
    }
    
    const getRightToLeftAdapter = function(rectX, width) {
      return {
        x(x) {
          return rectX + rectX + width - x;
        },
        setWidth(w) {
          width = w;
        },
        textAlign(align) {
          if (align === 'center') {
            return align;
          }
          return align === 'right' ? 'left' : 'right';
        },
        xPlus(x, value) {
          return x - value;
        },
        leftForLtr(x, itemWidth) {
          return x - itemWidth;
        },
      };
    };
    const getLeftToRightAdapter = function() {
      return {
        x(x) {
          return x;
        },
        setWidth(w) {
        },
        textAlign(align) {
          return align;
        },
        xPlus(x, value) {
          return x + value;
        },
        leftForLtr(x, _itemWidth) {
          return x;
        },
      };
    };
    function getRtlAdapter(rtl, rectX, width) {
      return rtl ? getRightToLeftAdapter(rectX, width) : getLeftToRightAdapter();
    }
    function overrideTextDirection(ctx, direction) {
      let style, original;
      if (direction === 'ltr' || direction === 'rtl') {
        style = ctx.canvas.style;
        original = [
          style.getPropertyValue('direction'),
          style.getPropertyPriority('direction'),
        ];
        style.setProperty('direction', direction, 'important');
        ctx.prevTextDirection = original;
      }
    }
    function restoreTextDirection(ctx, original) {
      if (original !== undefined) {
        delete ctx.prevTextDirection;
        ctx.canvas.style.setProperty('direction', original[0], original[1]);
      }
    }
    
    function propertyFn(property) {
      if (property === 'angle') {
        return {
          between: _angleBetween,
          compare: _angleDiff,
          normalize: _normalizeAngle,
        };
      }
      return {
        between: (n, s, e) => n >= Math.min(s, e) && n <= Math.max(e, s),
        compare: (a, b) => a - b,
        normalize: x => x
      };
    }
    function normalizeSegment({start, end, count, loop, style}) {
      return {
        start: start % count,
        end: end % count,
        loop: loop && (end - start + 1) % count === 0,
        style
      };
    }
    function getSegment(segment, points, bounds) {
      const {property, start: startBound, end: endBound} = bounds;
      const {between, normalize} = propertyFn(property);
      const count = points.length;
      let {start, end, loop} = segment;
      let i, ilen;
      if (loop) {
        start += count;
        end += count;
        for (i = 0, ilen = count; i < ilen; ++i) {
          if (!between(normalize(points[start % count][property]), startBound, endBound)) {
            break;
          }
          start--;
          end--;
        }
        start %= count;
        end %= count;
      }
      if (end < start) {
        end += count;
      }
      return {start, end, loop, style: segment.style};
    }
    function _boundSegment(segment, points, bounds) {
      if (!bounds) {
        return [segment];
      }
      const {property, start: startBound, end: endBound} = bounds;
      const count = points.length;
      const {compare, between, normalize} = propertyFn(property);
      const {start, end, loop, style} = getSegment(segment, points, bounds);
      const result = [];
      let inside = false;
      let subStart = null;
      let value, point, prevValue;
      const startIsBefore = () => between(startBound, prevValue, value) && compare(startBound, prevValue) !== 0;
      const endIsBefore = () => compare(endBound, value) === 0 || between(endBound, prevValue, value);
      const shouldStart = () => inside || startIsBefore();
      const shouldStop = () => !inside || endIsBefore();
      for (let i = start, prev = start; i <= end; ++i) {
        point = points[i % count];
        if (point.skip) {
          continue;
        }
        value = normalize(point[property]);
        if (value === prevValue) {
          continue;
        }
        inside = between(value, startBound, endBound);
        if (subStart === null && shouldStart()) {
          subStart = compare(value, startBound) === 0 ? i : prev;
        }
        if (subStart !== null && shouldStop()) {
          result.push(normalizeSegment({start: subStart, end: i, loop, count, style}));
          subStart = null;
        }
        prev = i;
        prevValue = value;
      }
      if (subStart !== null) {
        result.push(normalizeSegment({start: subStart, end, loop, count, style}));
      }
      return result;
    }
    function _boundSegments(line, bounds) {
      const result = [];
      const segments = line.segments;
      for (let i = 0; i < segments.length; i++) {
        const sub = _boundSegment(segments[i], line.points, bounds);
        if (sub.length) {
          result.push(...sub);
        }
      }
      return result;
    }
    function findStartAndEnd(points, count, loop, spanGaps) {
      let start = 0;
      let end = count - 1;
      if (loop && !spanGaps) {
        while (start < count && !points[start].skip) {
          start++;
        }
      }
      while (start < count && points[start].skip) {
        start++;
      }
      start %= count;
      if (loop) {
        end += start;
      }
      while (end > start && points[end % count].skip) {
        end--;
      }
      end %= count;
      return {start, end};
    }
    function solidSegments(points, start, max, loop) {
      const count = points.length;
      const result = [];
      let last = start;
      let prev = points[start];
      let end;
      for (end = start + 1; end <= max; ++end) {
        const cur = points[end % count];
        if (cur.skip || cur.stop) {
          if (!prev.skip) {
            loop = false;
            result.push({start: start % count, end: (end - 1) % count, loop});
            start = last = cur.stop ? end : null;
          }
        } else {
          last = end;
          if (prev.skip) {
            start = end;
          }
        }
        prev = cur;
      }
      if (last !== null) {
        result.push({start: start % count, end: last % count, loop});
      }
      return result;
    }
    function _computeSegments(line, segmentOptions) {
      const points = line.points;
      const spanGaps = line.options.spanGaps;
      const count = points.length;
      if (!count) {
        return [];
      }
      const loop = !!line._loop;
      const {start, end} = findStartAndEnd(points, count, loop, spanGaps);
      if (spanGaps === true) {
        return splitByStyles(line, [{start, end, loop}], points, segmentOptions);
      }
      const max = end < start ? end + count : end;
      const completeLoop = !!line._fullLoop && start === 0 && end === count - 1;
      return splitByStyles(line, solidSegments(points, start, max, completeLoop), points, segmentOptions);
    }
    function splitByStyles(line, segments, points, segmentOptions) {
      if (!segmentOptions || !segmentOptions.setContext || !points) {
        return segments;
      }
      return doSplitByStyles(line, segments, points, segmentOptions);
    }
    function doSplitByStyles(line, segments, points, segmentOptions) {
      const baseStyle = readStyle(line.options);
      const count = points.length;
      const result = [];
      let start = segments[0].start;
      let i = start;
      for (const segment of segments) {
        let prevStyle = baseStyle;
        let prev = points[start % count];
        let style;
        for (i = start + 1; i <= segment.end; i++) {
          const pt = points[i % count];
          style = readStyle(segmentOptions.setContext({
            type: 'segment',
            p0: prev,
            p1: pt,
            p0DataIndex: (i - 1) % count,
            p1DataIndex: i % count,
            datasetIndex: line._datasetIndex
          }));
          if (styleChanged(style, prevStyle)) {
            result.push({start: start, end: i - 1, loop: segment.loop, style: prevStyle});
            prevStyle = style;
            start = i - 1;
          }
          prev = pt;
          prevStyle = style;
        }
        if (start < i - 1) {
          result.push({start, end: i - 1, loop: segment.loop, style});
          start = i - 1;
        }
      }
      return result;
    }
    function readStyle(options) {
      return {
        backgroundColor: options.backgroundColor,
        borderCapStyle: options.borderCapStyle,
        borderDash: options.borderDash,
        borderDashOffset: options.borderDashOffset,
        borderJoinStyle: options.borderJoinStyle,
        borderWidth: options.borderWidth,
        borderColor: options.borderColor
      };
    }
    function styleChanged(style, prevStyle) {
      return prevStyle && JSON.stringify(style) !== JSON.stringify(prevStyle);
    }
    
    var helpers = /*#__PURE__*/Object.freeze({
    __proto__: null,
    easingEffects: effects,
    color: color,
    getHoverColor: getHoverColor,
    noop: noop,
    uid: uid,
    isNullOrUndef: isNullOrUndef,
    isArray: isArray,
    isObject: isObject,
    isFinite: isNumberFinite,
    finiteOrDefault: finiteOrDefault,
    valueOrDefault: valueOrDefault,
    toPercentage: toPercentage,
    toDimension: toDimension,
    callback: callback,
    each: each,
    _elementsEqual: _elementsEqual,
    clone: clone,
    _merger: _merger,
    merge: merge,
    mergeIf: mergeIf,
    _mergerIf: _mergerIf,
    _deprecated: _deprecated,
    resolveObjectKey: resolveObjectKey,
    _capitalize: _capitalize,
    defined: defined,
    isFunction: isFunction,
    setsEqual: setsEqual,
    toFontString: toFontString,
    _measureText: _measureText,
    _longestText: _longestText,
    _alignPixel: _alignPixel,
    clearCanvas: clearCanvas,
    drawPoint: drawPoint,
    _isPointInArea: _isPointInArea,
    clipArea: clipArea,
    unclipArea: unclipArea,
    _steppedLineTo: _steppedLineTo,
    _bezierCurveTo: _bezierCurveTo,
    renderText: renderText,
    addRoundedRectPath: addRoundedRectPath,
    _lookup: _lookup,
    _lookupByKey: _lookupByKey,
    _rlookupByKey: _rlookupByKey,
    _filterBetween: _filterBetween,
    listenArrayEvents: listenArrayEvents,
    unlistenArrayEvents: unlistenArrayEvents,
    _arrayUnique: _arrayUnique,
    _createResolver: _createResolver,
    _attachContext: _attachContext,
    _descriptors: _descriptors,
    splineCurve: splineCurve,
    splineCurveMonotone: splineCurveMonotone,
    _updateBezierControlPoints: _updateBezierControlPoints,
    _isDomSupported: _isDomSupported,
    _getParentNode: _getParentNode,
    getStyle: getStyle,
    getRelativePosition: getRelativePosition$1,
    getMaximumSize: getMaximumSize,
    retinaScale: retinaScale,
    supportsEventListenerOptions: supportsEventListenerOptions,
    readUsedSize: readUsedSize,
    fontString: fontString,
    requestAnimFrame: requestAnimFrame,
    throttled: throttled,
    debounce: debounce,
    _toLeftRightCenter: _toLeftRightCenter,
    _alignStartEnd: _alignStartEnd,
    _textX: _textX,
    _pointInLine: _pointInLine,
    _steppedInterpolation: _steppedInterpolation,
    _bezierInterpolation: _bezierInterpolation,
    formatNumber: formatNumber,
    toLineHeight: toLineHeight,
    _readValueToProps: _readValueToProps,
    toTRBL: toTRBL,
    toTRBLCorners: toTRBLCorners,
    toPadding: toPadding,
    toFont: toFont,
    resolve: resolve,
    _addGrace: _addGrace,
    PI: PI,
    TAU: TAU,
    PITAU: PITAU,
    INFINITY: INFINITY,
    RAD_PER_DEG: RAD_PER_DEG,
    HALF_PI: HALF_PI,
    QUARTER_PI: QUARTER_PI,
    TWO_THIRDS_PI: TWO_THIRDS_PI,
    log10: log10,
    sign: sign,
    niceNum: niceNum,
    _factorize: _factorize,
    isNumber: isNumber,
    almostEquals: almostEquals,
    almostWhole: almostWhole,
    _setMinAndMaxByKey: _setMinAndMaxByKey,
    toRadians: toRadians,
    toDegrees: toDegrees,
    _decimalPlaces: _decimalPlaces,
    getAngleFromPoint: getAngleFromPoint,
    distanceBetweenPoints: distanceBetweenPoints,
    _angleDiff: _angleDiff,
    _normalizeAngle: _normalizeAngle,
    _angleBetween: _angleBetween,
    _limitValue: _limitValue,
    _int16Range: _int16Range,
    getRtlAdapter: getRtlAdapter,
    overrideTextDirection: overrideTextDirection,
    restoreTextDirection: restoreTextDirection,
    _boundSegment: _boundSegment,
    _boundSegments: _boundSegments,
    _computeSegments: _computeSegments
    });
    
    class BasePlatform {
      acquireContext(canvas, aspectRatio) {}
      releaseContext(context) {
        return false;
      }
      addEventListener(chart, type, listener) {}
      removeEventListener(chart, type, listener) {}
      getDevicePixelRatio() {
        return 1;
      }
      getMaximumSize(element, width, height, aspectRatio) {
        width = Math.max(0, width || element.width);
        height = height || element.height;
        return {
          width,
          height: Math.max(0, aspectRatio ? Math.floor(width / aspectRatio) : height)
        };
      }
      isAttached(canvas) {
        return true;
      }
    }
    
    class BasicPlatform extends BasePlatform {
      acquireContext(item) {
        return item && item.getContext && item.getContext('2d') || null;
      }
    }
    
    const EXPANDO_KEY = '$chartjs';
    const EVENT_TYPES = {
      touchstart: 'mousedown',
      touchmove: 'mousemove',
      touchend: 'mouseup',
      pointerenter: 'mouseenter',
      pointerdown: 'mousedown',
      pointermove: 'mousemove',
      pointerup: 'mouseup',
      pointerleave: 'mouseout',
      pointerout: 'mouseout'
    };
    const isNullOrEmpty = value => value === null || value === '';
    function initCanvas(canvas, aspectRatio) {
      const style = canvas.style;
      const renderHeight = canvas.getAttribute('height');
      const renderWidth = canvas.getAttribute('width');
      canvas[EXPANDO_KEY] = {
        initial: {
          height: renderHeight,
          width: renderWidth,
          style: {
            display: style.display,
            height: style.height,
            width: style.width
          }
        }
      };
      style.display = style.display || 'block';
      style.boxSizing = style.boxSizing || 'border-box';
      if (isNullOrEmpty(renderWidth)) {
        const displayWidth = readUsedSize(canvas, 'width');
        if (displayWidth !== undefined) {
          canvas.width = displayWidth;
        }
      }
      if (isNullOrEmpty(renderHeight)) {
        if (canvas.style.height === '') {
          canvas.height = canvas.width / (aspectRatio || 2);
        } else {
          const displayHeight = readUsedSize(canvas, 'height');
          if (displayHeight !== undefined) {
            canvas.height = displayHeight;
          }
        }
      }
      return canvas;
    }
    const eventListenerOptions = supportsEventListenerOptions ? {passive: true} : false;
    function addListener(node, type, listener) {
      node.addEventListener(type, listener, eventListenerOptions);
    }
    function removeListener(chart, type, listener) {
      chart.canvas.removeEventListener(type, listener, eventListenerOptions);
    }
    function fromNativeEvent(event, chart) {
      const type = EVENT_TYPES[event.type] || event.type;
      const {x, y} = getRelativePosition$1(event, chart);
      return {
        type,
        chart,
        native: event,
        x: x !== undefined ? x : null,
        y: y !== undefined ? y : null,
      };
    }
    function createAttachObserver(chart, type, listener) {
      const canvas = chart.canvas;
      const container = canvas && _getParentNode(canvas);
      const element = container || canvas;
      const observer = new MutationObserver(entries => {
        const parent = _getParentNode(element);
        entries.forEach(entry => {
          for (let i = 0; i < entry.addedNodes.length; i++) {
            const added = entry.addedNodes[i];
            if (added === element || added === parent) {
              listener(entry.target);
            }
          }
        });
      });
      observer.observe(document, {childList: true, subtree: true});
      return observer;
    }
    function createDetachObserver(chart, type, listener) {
      const canvas = chart.canvas;
      const container = canvas && _getParentNode(canvas);
      if (!container) {
        return;
      }
      const observer = new MutationObserver(entries => {
        entries.forEach(entry => {
          for (let i = 0; i < entry.removedNodes.length; i++) {
            if (entry.removedNodes[i] === canvas) {
              listener();
              break;
            }
          }
        });
      });
      observer.observe(container, {childList: true});
      return observer;
    }
    const drpListeningCharts = new Map();
    let oldDevicePixelRatio = 0;
    function onWindowResize() {
      const dpr = window.devicePixelRatio;
      if (dpr === oldDevicePixelRatio) {
        return;
      }
      oldDevicePixelRatio = dpr;
      drpListeningCharts.forEach((resize, chart) => {
        if (chart.currentDevicePixelRatio !== dpr) {
          resize();
        }
      });
    }
    function listenDevicePixelRatioChanges(chart, resize) {
      if (!drpListeningCharts.size) {
        window.addEventListener('resize', onWindowResize);
      }
      drpListeningCharts.set(chart, resize);
    }
    function unlistenDevicePixelRatioChanges(chart) {
      drpListeningCharts.delete(chart);
      if (!drpListeningCharts.size) {
        window.removeEventListener('resize', onWindowResize);
      }
    }
    function createResizeObserver(chart, type, listener) {
      const canvas = chart.canvas;
      const container = canvas && _getParentNode(canvas);
      if (!container) {
        return;
      }
      const resize = throttled((width, height) => {
        const w = container.clientWidth;
        listener(width, height);
        if (w < container.clientWidth) {
          listener();
        }
      }, window);
      const observer = new ResizeObserver(entries => {
        const entry = entries[0];
        const width = entry.contentRect.width;
        const height = entry.contentRect.height;
        if (width === 0 && height === 0) {
          return;
        }
        resize(width, height);
      });
      observer.observe(container);
      listenDevicePixelRatioChanges(chart, resize);
      return observer;
    }
    function releaseObserver(chart, type, observer) {
      if (observer) {
        observer.disconnect();
      }
      if (type === 'resize') {
        unlistenDevicePixelRatioChanges(chart);
      }
    }
    function createProxyAndListen(chart, type, listener) {
      const canvas = chart.canvas;
      const proxy = throttled((event) => {
        if (chart.ctx !== null) {
          listener(fromNativeEvent(event, chart));
        }
      }, chart, (args) => {
        const event = args[0];
        return [event, event.offsetX, event.offsetY];
      });
      addListener(canvas, type, proxy);
      return proxy;
    }
    class DomPlatform extends BasePlatform {
      acquireContext(canvas, aspectRatio) {
        const context = canvas && canvas.getContext && canvas.getContext('2d');
        if (context && context.canvas === canvas) {
          initCanvas(canvas, aspectRatio);
          return context;
        }
        return null;
      }
      releaseContext(context) {
        const canvas = context.canvas;
        if (!canvas[EXPANDO_KEY]) {
          return false;
        }
        const initial = canvas[EXPANDO_KEY].initial;
        ['height', 'width'].forEach((prop) => {
          const value = initial[prop];
          if (isNullOrUndef(value)) {
            canvas.removeAttribute(prop);
          } else {
            canvas.setAttribute(prop, value);
          }
        });
        const style = initial.style || {};
        Object.keys(style).forEach((key) => {
          canvas.style[key] = style[key];
        });
        canvas.width = canvas.width;
        delete canvas[EXPANDO_KEY];
        return true;
      }
      addEventListener(chart, type, listener) {
        this.removeEventListener(chart, type);
        const proxies = chart.$proxies || (chart.$proxies = {});
        const handlers = {
          attach: createAttachObserver,
          detach: createDetachObserver,
          resize: createResizeObserver
        };
        const handler = handlers[type] || createProxyAndListen;
        proxies[type] = handler(chart, type, listener);
      }
      removeEventListener(chart, type) {
        const proxies = chart.$proxies || (chart.$proxies = {});
        const proxy = proxies[type];
        if (!proxy) {
          return;
        }
        const handlers = {
          attach: releaseObserver,
          detach: releaseObserver,
          resize: releaseObserver
        };
        const handler = handlers[type] || removeListener;
        handler(chart, type, proxy);
        proxies[type] = undefined;
      }
      getDevicePixelRatio() {
        return window.devicePixelRatio;
      }
      getMaximumSize(canvas, width, height, aspectRatio) {
        return getMaximumSize(canvas, width, height, aspectRatio);
      }
      isAttached(canvas) {
        const container = _getParentNode(canvas);
        return !!(container && container.isConnected);
      }
    }
    
    function _detectPlatform(canvas) {
      if (!_isDomSupported() || (typeof OffscreenCanvas !== 'undefined' && canvas instanceof OffscreenCanvas)) {
        return BasicPlatform;
      }
      return DomPlatform;
    }
    
    var platforms = /*#__PURE__*/Object.freeze({
    __proto__: null,
    _detectPlatform: _detectPlatform,
    BasePlatform: BasePlatform,
    BasicPlatform: BasicPlatform,
    DomPlatform: DomPlatform
    });
    
    const transparent = 'transparent';
    const interpolators = {
      boolean(from, to, factor) {
        return factor > 0.5 ? to : from;
      },
      color(from, to, factor) {
        const c0 = color(from || transparent);
        const c1 = c0.valid && color(to || transparent);
        return c1 && c1.valid
          ? c1.mix(c0, factor).hexString()
          : to;
      },
      number(from, to, factor) {
        return from + (to - from) * factor;
      }
    };
    class Animation {
      constructor(cfg, target, prop, to) {
        const currentValue = target[prop];
        to = resolve([cfg.to, to, currentValue, cfg.from]);
        const from = resolve([cfg.from, currentValue, to]);
        this._active = true;
        this._fn = cfg.fn || interpolators[cfg.type || typeof from];
        this._easing = effects[cfg.easing] || effects.linear;
        this._start = Math.floor(Date.now() + (cfg.delay || 0));
        this._duration = this._total = Math.floor(cfg.duration);
        this._loop = !!cfg.loop;
        this._target = target;
        this._prop = prop;
        this._from = from;
        this._to = to;
        this._promises = undefined;
      }
      active() {
        return this._active;
      }
      update(cfg, to, date) {
        const me = this;
        if (me._active) {
          me._notify(false);
          const currentValue = me._target[me._prop];
          const elapsed = date - me._start;
          const remain = me._duration - elapsed;
          me._start = date;
          me._duration = Math.floor(Math.max(remain, cfg.duration));
          me._total += elapsed;
          me._loop = !!cfg.loop;
          me._to = resolve([cfg.to, to, currentValue, cfg.from]);
          me._from = resolve([cfg.from, currentValue, to]);
        }
      }
      cancel() {
        const me = this;
        if (me._active) {
          me.tick(Date.now());
          me._active = false;
          me._notify(false);
        }
      }
      tick(date) {
        const me = this;
        const elapsed = date - me._start;
        const duration = me._duration;
        const prop = me._prop;
        const from = me._from;
        const loop = me._loop;
        const to = me._to;
        let factor;
        me._active = from !== to && (loop || (elapsed < duration));
        if (!me._active) {
          me._target[prop] = to;
          me._notify(true);
          return;
        }
        if (elapsed < 0) {
          me._target[prop] = from;
          return;
        }
        factor = (elapsed / duration) % 2;
        factor = loop && factor > 1 ? 2 - factor : factor;
        factor = me._easing(Math.min(1, Math.max(0, factor)));
        me._target[prop] = me._fn(from, to, factor);
      }
      wait() {
        const promises = this._promises || (this._promises = []);
        return new Promise((res, rej) => {
          promises.push({res, rej});
        });
      }
      _notify(resolved) {
        const method = resolved ? 'res' : 'rej';
        const promises = this._promises || [];
        for (let i = 0; i < promises.length; i++) {
          promises[i][method]();
        }
      }
    }
    
    const numbers = ['x', 'y', 'borderWidth', 'radius', 'tension'];
    const colors = ['color', 'borderColor', 'backgroundColor'];
    defaults.set('animation', {
      delay: undefined,
      duration: 1000,
      easing: 'easeOutQuart',
      fn: undefined,
      from: undefined,
      loop: undefined,
      to: undefined,
      type: undefined,
    });
    const animationOptions = Object.keys(defaults.animation);
    defaults.describe('animation', {
      _fallback: false,
      _indexable: false,
      _scriptable: (name) => name !== 'onProgress' && name !== 'onComplete' && name !== 'fn',
    });
    defaults.set('animations', {
      colors: {
        type: 'color',
        properties: colors
      },
      numbers: {
        type: 'number',
        properties: numbers
      },
    });
    defaults.describe('animations', {
      _fallback: 'animation',
    });
    defaults.set('transitions', {
      active: {
        animation: {
          duration: 400
        }
      },
      resize: {
        animation: {
          duration: 0
        }
      },
      show: {
        animations: {
          colors: {
            from: 'transparent'
          },
          visible: {
            type: 'boolean',
            duration: 0
          },
        }
      },
      hide: {
        animations: {
          colors: {
            to: 'transparent'
          },
          visible: {
            type: 'boolean',
            easing: 'linear',
            fn: v => v | 0
          },
        }
      }
    });
    class Animations {
      constructor(chart, config) {
        this._chart = chart;
        this._properties = new Map();
        this.configure(config);
      }
      configure(config) {
        if (!isObject(config)) {
          return;
        }
        const animatedProps = this._properties;
        Object.getOwnPropertyNames(config).forEach(key => {
          const cfg = config[key];
          if (!isObject(cfg)) {
            return;
          }
          const resolved = {};
          for (const option of animationOptions) {
            resolved[option] = cfg[option];
          }
          (isArray(cfg.properties) && cfg.properties || [key]).forEach((prop) => {
            if (prop === key || !animatedProps.has(prop)) {
              animatedProps.set(prop, resolved);
            }
          });
        });
      }
      _animateOptions(target, values) {
        const newOptions = values.options;
        const options = resolveTargetOptions(target, newOptions);
        if (!options) {
          return [];
        }
        const animations = this._createAnimations(options, newOptions);
        if (newOptions.$shared) {
          awaitAll(target.options.$animations, newOptions).then(() => {
            target.options = newOptions;
          }, () => {
          });
        }
        return animations;
      }
      _createAnimations(target, values) {
        const animatedProps = this._properties;
        const animations = [];
        const running = target.$animations || (target.$animations = {});
        const props = Object.keys(values);
        const date = Date.now();
        let i;
        for (i = props.length - 1; i >= 0; --i) {
          const prop = props[i];
          if (prop.charAt(0) === '$') {
            continue;
          }
          if (prop === 'options') {
            animations.push(...this._animateOptions(target, values));
            continue;
          }
          const value = values[prop];
          let animation = running[prop];
          const cfg = animatedProps.get(prop);
          if (animation) {
            if (cfg && animation.active()) {
              animation.update(cfg, value, date);
              continue;
            } else {
              animation.cancel();
            }
          }
          if (!cfg || !cfg.duration) {
            target[prop] = value;
            continue;
          }
          running[prop] = animation = new Animation(cfg, target, prop, value);
          animations.push(animation);
        }
        return animations;
      }
      update(target, values) {
        if (this._properties.size === 0) {
          Object.assign(target, values);
          return;
        }
        const animations = this._createAnimations(target, values);
        if (animations.length) {
          animator.add(this._chart, animations);
          return true;
        }
      }
    }
    function awaitAll(animations, properties) {
      const running = [];
      const keys = Object.keys(properties);
      for (let i = 0; i < keys.length; i++) {
        const anim = animations[keys[i]];
        if (anim && anim.active()) {
          running.push(anim.wait());
        }
      }
      return Promise.all(running);
    }
    function resolveTargetOptions(target, newOptions) {
      if (!newOptions) {
        return;
      }
      let options = target.options;
      if (!options) {
        target.options = newOptions;
        return;
      }
      if (options.$shared) {
        target.options = options = Object.assign({}, options, {$shared: false, $animations: {}});
      }
      return options;
    }
    
    function scaleClip(scale, allowedOverflow) {
      const opts = scale && scale.options || {};
      const reverse = opts.reverse;
      const min = opts.min === undefined ? allowedOverflow : 0;
      const max = opts.max === undefined ? allowedOverflow : 0;
      return {
        start: reverse ? max : min,
        end: reverse ? min : max
      };
    }
    function defaultClip(xScale, yScale, allowedOverflow) {
      if (allowedOverflow === false) {
        return false;
      }
      const x = scaleClip(xScale, allowedOverflow);
      const y = scaleClip(yScale, allowedOverflow);
      return {
        top: y.end,
        right: x.end,
        bottom: y.start,
        left: x.start
      };
    }
    function toClip(value) {
      let t, r, b, l;
      if (isObject(value)) {
        t = value.top;
        r = value.right;
        b = value.bottom;
        l = value.left;
      } else {
        t = r = b = l = value;
      }
      return {
        top: t,
        right: r,
        bottom: b,
        left: l,
        disabled: value === false
      };
    }
    function getSortedDatasetIndices(chart, filterVisible) {
      const keys = [];
      const metasets = chart._getSortedDatasetMetas(filterVisible);
      let i, ilen;
      for (i = 0, ilen = metasets.length; i < ilen; ++i) {
        keys.push(metasets[i].index);
      }
      return keys;
    }
    function applyStack(stack, value, dsIndex, options) {
      const keys = stack.keys;
      const singleMode = options.mode === 'single';
      let i, ilen, datasetIndex, otherValue;
      if (value === null) {
        return;
      }
      for (i = 0, ilen = keys.length; i < ilen; ++i) {
        datasetIndex = +keys[i];
        if (datasetIndex === dsIndex) {
          if (options.all) {
            continue;
          }
          break;
        }
        otherValue = stack.values[datasetIndex];
        if (isNumberFinite(otherValue) && (singleMode || (value === 0 || sign(value) === sign(otherValue)))) {
          value += otherValue;
        }
      }
      return value;
    }
    function convertObjectDataToArray(data) {
      const keys = Object.keys(data);
      const adata = new Array(keys.length);
      let i, ilen, key;
      for (i = 0, ilen = keys.length; i < ilen; ++i) {
        key = keys[i];
        adata[i] = {
          x: key,
          y: data[key]
        };
      }
      return adata;
    }
    function isStacked(scale, meta) {
      const stacked = scale && scale.options.stacked;
      return stacked || (stacked === undefined && meta.stack !== undefined);
    }
    function getStackKey(indexScale, valueScale, meta) {
      return `${indexScale.id}.${valueScale.id}.${meta.stack || meta.type}`;
    }
    function getUserBounds(scale) {
      const {min, max, minDefined, maxDefined} = scale.getUserBounds();
      return {
        min: minDefined ? min : Number.NEGATIVE_INFINITY,
        max: maxDefined ? max : Number.POSITIVE_INFINITY
      };
    }
    function getOrCreateStack(stacks, stackKey, indexValue) {
      const subStack = stacks[stackKey] || (stacks[stackKey] = {});
      return subStack[indexValue] || (subStack[indexValue] = {});
    }
    function getLastIndexInStack(stack, vScale, positive) {
      for (const meta of vScale.getMatchingVisibleMetas('bar').reverse()) {
        const value = stack[meta.index];
        if ((positive && value > 0) || (!positive && value < 0)) {
          return meta.index;
        }
      }
      return null;
    }
    function updateStacks(controller, parsed) {
      const {chart, _cachedMeta: meta} = controller;
      const stacks = chart._stacks || (chart._stacks = {});
      const {iScale, vScale, index: datasetIndex} = meta;
      const iAxis = iScale.axis;
      const vAxis = vScale.axis;
      const key = getStackKey(iScale, vScale, meta);
      const ilen = parsed.length;
      let stack;
      for (let i = 0; i < ilen; ++i) {
        const item = parsed[i];
        const {[iAxis]: index, [vAxis]: value} = item;
        const itemStacks = item._stacks || (item._stacks = {});
        stack = itemStacks[vAxis] = getOrCreateStack(stacks, key, index);
        stack[datasetIndex] = value;
        stack._top = getLastIndexInStack(stack, vScale, true);
        stack._bottom = getLastIndexInStack(stack, vScale, false);
      }
    }
    function getFirstScaleId(chart, axis) {
      const scales = chart.scales;
      return Object.keys(scales).filter(key => scales[key].axis === axis).shift();
    }
    function createDatasetContext(parent, index) {
      return Object.assign(Object.create(parent),
        {
          active: false,
          dataset: undefined,
          datasetIndex: index,
          index,
          mode: 'default',
          type: 'dataset'
        }
      );
    }
    function createDataContext(parent, index, element) {
      return Object.assign(Object.create(parent), {
        active: false,
        dataIndex: index,
        parsed: undefined,
        raw: undefined,
        element,
        index,
        mode: 'default',
        type: 'data'
      });
    }
    function clearStacks(meta, items) {
      const datasetIndex = meta.controller.index;
      const axis = meta.vScale && meta.vScale.axis;
      if (!axis) {
        return;
      }
      items = items || meta._parsed;
      for (const parsed of items) {
        const stacks = parsed._stacks;
        if (!stacks || stacks[axis] === undefined || stacks[axis][datasetIndex] === undefined) {
          return;
        }
        delete stacks[axis][datasetIndex];
      }
    }
    const isDirectUpdateMode = (mode) => mode === 'reset' || mode === 'none';
    const cloneIfNotShared = (cached, shared) => shared ? cached : Object.assign({}, cached);
    class DatasetController {
      constructor(chart, datasetIndex) {
        this.chart = chart;
        this._ctx = chart.ctx;
        this.index = datasetIndex;
        this._cachedDataOpts = {};
        this._cachedMeta = this.getMeta();
        this._type = this._cachedMeta.type;
        this.options = undefined;
        this._parsing = false;
        this._data = undefined;
        this._objectData = undefined;
        this._sharedOptions = undefined;
        this._drawStart = undefined;
        this._drawCount = undefined;
        this.enableOptionSharing = false;
        this.$context = undefined;
        this._syncList = [];
        this.initialize();
      }
      initialize() {
        const me = this;
        const meta = me._cachedMeta;
        me.configure();
        me.linkScales();
        meta._stacked = isStacked(meta.vScale, meta);
        me.addElements();
      }
      updateIndex(datasetIndex) {
        if (this.index !== datasetIndex) {
          clearStacks(this._cachedMeta);
        }
        this.index = datasetIndex;
      }
      linkScales() {
        const me = this;
        const chart = me.chart;
        const meta = me._cachedMeta;
        const dataset = me.getDataset();
        const chooseId = (axis, x, y, r) => axis === 'x' ? x : axis === 'r' ? r : y;
        const xid = meta.xAxisID = valueOrDefault(dataset.xAxisID, getFirstScaleId(chart, 'x'));
        const yid = meta.yAxisID = valueOrDefault(dataset.yAxisID, getFirstScaleId(chart, 'y'));
        const rid = meta.rAxisID = valueOrDefault(dataset.rAxisID, getFirstScaleId(chart, 'r'));
        const indexAxis = meta.indexAxis;
        const iid = meta.iAxisID = chooseId(indexAxis, xid, yid, rid);
        const vid = meta.vAxisID = chooseId(indexAxis, yid, xid, rid);
        meta.xScale = me.getScaleForId(xid);
        meta.yScale = me.getScaleForId(yid);
        meta.rScale = me.getScaleForId(rid);
        meta.iScale = me.getScaleForId(iid);
        meta.vScale = me.getScaleForId(vid);
      }
      getDataset() {
        return this.chart.data.datasets[this.index];
      }
      getMeta() {
        return this.chart.getDatasetMeta(this.index);
      }
      getScaleForId(scaleID) {
        return this.chart.scales[scaleID];
      }
      _getOtherScale(scale) {
        const meta = this._cachedMeta;
        return scale === meta.iScale
          ? meta.vScale
          : meta.iScale;
      }
      reset() {
        this._update('reset');
      }
      _destroy() {
        const meta = this._cachedMeta;
        if (this._data) {
          unlistenArrayEvents(this._data, this);
        }
        if (meta._stacked) {
          clearStacks(meta);
        }
      }
      _dataCheck() {
        const me = this;
        const dataset = me.getDataset();
        const data = dataset.data || (dataset.data = []);
        const _data = me._data;
        if (isObject(data)) {
          me._data = convertObjectDataToArray(data);
        } else if (_data !== data) {
          if (_data) {
            unlistenArrayEvents(_data, me);
            const meta = me._cachedMeta;
            clearStacks(meta);
            meta._parsed = [];
          }
          if (data && Object.isExtensible(data)) {
            listenArrayEvents(data, me);
          }
          me._syncList = [];
          me._data = data;
        }
      }
      addElements() {
        const me = this;
        const meta = me._cachedMeta;
        me._dataCheck();
        if (me.datasetElementType) {
          meta.dataset = new me.datasetElementType();
        }
      }
      buildOrUpdateElements(resetNewElements) {
        const me = this;
        const meta = me._cachedMeta;
        const dataset = me.getDataset();
        let stackChanged = false;
        me._dataCheck();
        const oldStacked = meta._stacked;
        meta._stacked = isStacked(meta.vScale, meta);
        if (meta.stack !== dataset.stack) {
          stackChanged = true;
          clearStacks(meta);
          meta.stack = dataset.stack;
        }
        me._resyncElements(resetNewElements);
        if (stackChanged || oldStacked !== meta._stacked) {
          updateStacks(me, meta._parsed);
        }
      }
      configure() {
        const me = this;
        const config = me.chart.config;
        const scopeKeys = config.datasetScopeKeys(me._type);
        const scopes = config.getOptionScopes(me.getDataset(), scopeKeys, true);
        me.options = config.createResolver(scopes, me.getContext());
        me._parsing = me.options.parsing;
      }
      parse(start, count) {
        const me = this;
        const {_cachedMeta: meta, _data: data} = me;
        const {iScale, _stacked} = meta;
        const iAxis = iScale.axis;
        let sorted = start === 0 && count === data.length ? true : meta._sorted;
        let prev = start > 0 && meta._parsed[start - 1];
        let i, cur, parsed;
        if (me._parsing === false) {
          meta._parsed = data;
          meta._sorted = true;
          parsed = data;
        } else {
          if (isArray(data[start])) {
            parsed = me.parseArrayData(meta, data, start, count);
          } else if (isObject(data[start])) {
            parsed = me.parseObjectData(meta, data, start, count);
          } else {
            parsed = me.parsePrimitiveData(meta, data, start, count);
          }
          const isNotInOrderComparedToPrev = () => cur[iAxis] === null || (prev && cur[iAxis] < prev[iAxis]);
          for (i = 0; i < count; ++i) {
            meta._parsed[i + start] = cur = parsed[i];
            if (sorted) {
              if (isNotInOrderComparedToPrev()) {
                sorted = false;
              }
              prev = cur;
            }
          }
          meta._sorted = sorted;
        }
        if (_stacked) {
          updateStacks(me, parsed);
        }
      }
      parsePrimitiveData(meta, data, start, count) {
        const {iScale, vScale} = meta;
        const iAxis = iScale.axis;
        const vAxis = vScale.axis;
        const labels = iScale.getLabels();
        const singleScale = iScale === vScale;
        const parsed = new Array(count);
        let i, ilen, index;
        for (i = 0, ilen = count; i < ilen; ++i) {
          index = i + start;
          parsed[i] = {
            [iAxis]: singleScale || iScale.parse(labels[index], index),
            [vAxis]: vScale.parse(data[index], index)
          };
        }
        return parsed;
      }
      parseArrayData(meta, data, start, count) {
        const {xScale, yScale} = meta;
        const parsed = new Array(count);
        let i, ilen, index, item;
        for (i = 0, ilen = count; i < ilen; ++i) {
          index = i + start;
          item = data[index];
          parsed[i] = {
            x: xScale.parse(item[0], index),
            y: yScale.parse(item[1], index)
          };
        }
        return parsed;
      }
      parseObjectData(meta, data, start, count) {
        const {xScale, yScale} = meta;
        const {xAxisKey = 'x', yAxisKey = 'y'} = this._parsing;
        const parsed = new Array(count);
        let i, ilen, index, item;
        for (i = 0, ilen = count; i < ilen; ++i) {
          index = i + start;
          item = data[index];
          parsed[i] = {
            x: xScale.parse(resolveObjectKey(item, xAxisKey), index),
            y: yScale.parse(resolveObjectKey(item, yAxisKey), index)
          };
        }
        return parsed;
      }
      getParsed(index) {
        return this._cachedMeta._parsed[index];
      }
      getDataElement(index) {
        return this._cachedMeta.data[index];
      }
      applyStack(scale, parsed, mode) {
        const chart = this.chart;
        const meta = this._cachedMeta;
        const value = parsed[scale.axis];
        const stack = {
          keys: getSortedDatasetIndices(chart, true),
          values: parsed._stacks[scale.axis]
        };
        return applyStack(stack, value, meta.index, {mode});
      }
      updateRangeFromParsed(range, scale, parsed, stack) {
        const parsedValue = parsed[scale.axis];
        let value = parsedValue === null ? NaN : parsedValue;
        const values = stack && parsed._stacks[scale.axis];
        if (stack && values) {
          stack.values = values;
          range.min = Math.min(range.min, value);
          range.max = Math.max(range.max, value);
          value = applyStack(stack, parsedValue, this._cachedMeta.index, {all: true});
        }
        range.min = Math.min(range.min, value);
        range.max = Math.max(range.max, value);
      }
      getMinMax(scale, canStack) {
        const me = this;
        const meta = me._cachedMeta;
        const _parsed = meta._parsed;
        const sorted = meta._sorted && scale === meta.iScale;
        const ilen = _parsed.length;
        const otherScale = me._getOtherScale(scale);
        const stack = canStack && meta._stacked && {keys: getSortedDatasetIndices(me.chart, true), values: null};
        const range = {min: Number.POSITIVE_INFINITY, max: Number.NEGATIVE_INFINITY};
        const {min: otherMin, max: otherMax} = getUserBounds(otherScale);
        let i, value, parsed, otherValue;
        function _skip() {
          parsed = _parsed[i];
          value = parsed[scale.axis];
          otherValue = parsed[otherScale.axis];
          return !isNumberFinite(value) || otherMin > otherValue || otherMax < otherValue;
        }
        for (i = 0; i < ilen; ++i) {
          if (_skip()) {
            continue;
          }
          me.updateRangeFromParsed(range, scale, parsed, stack);
          if (sorted) {
            break;
          }
        }
        if (sorted) {
          for (i = ilen - 1; i >= 0; --i) {
            if (_skip()) {
              continue;
            }
            me.updateRangeFromParsed(range, scale, parsed, stack);
            break;
          }
        }
        return range;
      }
      getAllParsedValues(scale) {
        const parsed = this._cachedMeta._parsed;
        const values = [];
        let i, ilen, value;
        for (i = 0, ilen = parsed.length; i < ilen; ++i) {
          value = parsed[i][scale.axis];
          if (isNumberFinite(value)) {
            values.push(value);
          }
        }
        return values;
      }
      getMaxOverflow() {
        return false;
      }
      getLabelAndValue(index) {
        const me = this;
        const meta = me._cachedMeta;
        const iScale = meta.iScale;
        const vScale = meta.vScale;
        const parsed = me.getParsed(index);
        return {
          label: iScale ? '' + iScale.getLabelForValue(parsed[iScale.axis]) : '',
          value: vScale ? '' + vScale.getLabelForValue(parsed[vScale.axis]) : ''
        };
      }
      _update(mode) {
        const me = this;
        const meta = me._cachedMeta;
        me.configure();
        me._cachedDataOpts = {};
        me.update(mode || 'default');
        meta._clip = toClip(valueOrDefault(me.options.clip, defaultClip(meta.xScale, meta.yScale, me.getMaxOverflow())));
      }
      update(mode) {}
      draw() {
        const me = this;
        const ctx = me._ctx;
        const chart = me.chart;
        const meta = me._cachedMeta;
        const elements = meta.data || [];
        const area = chart.chartArea;
        const active = [];
        const start = me._drawStart || 0;
        const count = me._drawCount || (elements.length - start);
        let i;
        if (meta.dataset) {
          meta.dataset.draw(ctx, area, start, count);
        }
        for (i = start; i < start + count; ++i) {
          const element = elements[i];
          if (element.hidden) {
            continue;
          }
          if (element.active) {
            active.push(element);
          } else {
            element.draw(ctx, area);
          }
        }
        for (i = 0; i < active.length; ++i) {
          active[i].draw(ctx, area);
        }
      }
      getStyle(index, active) {
        const mode = active ? 'active' : 'default';
        return index === undefined && this._cachedMeta.dataset
          ? this.resolveDatasetElementOptions(mode)
          : this.resolveDataElementOptions(index || 0, mode);
      }
      getContext(index, active, mode) {
        const me = this;
        const dataset = me.getDataset();
        let context;
        if (index >= 0 && index < me._cachedMeta.data.length) {
          const element = me._cachedMeta.data[index];
          context = element.$context ||
            (element.$context = createDataContext(me.getContext(), index, element));
          context.parsed = me.getParsed(index);
          context.raw = dataset.data[index];
          context.index = context.dataIndex = index;
        } else {
          context = me.$context ||
            (me.$context = createDatasetContext(me.chart.getContext(), me.index));
          context.dataset = dataset;
          context.index = context.datasetIndex = me.index;
        }
        context.active = !!active;
        context.mode = mode;
        return context;
      }
      resolveDatasetElementOptions(mode) {
        return this._resolveElementOptions(this.datasetElementType.id, mode);
      }
      resolveDataElementOptions(index, mode) {
        return this._resolveElementOptions(this.dataElementType.id, mode, index);
      }
      _resolveElementOptions(elementType, mode = 'default', index) {
        const me = this;
        const active = mode === 'active';
        const cache = me._cachedDataOpts;
        const cacheKey = elementType + '-' + mode;
        const cached = cache[cacheKey];
        const sharing = me.enableOptionSharing && defined(index);
        if (cached) {
          return cloneIfNotShared(cached, sharing);
        }
        const config = me.chart.config;
        const scopeKeys = config.datasetElementScopeKeys(me._type, elementType);
        const prefixes = active ? [`${elementType}Hover`, 'hover', elementType, ''] : [elementType, ''];
        const scopes = config.getOptionScopes(me.getDataset(), scopeKeys);
        const names = Object.keys(defaults.elements[elementType]);
        const context = () => me.getContext(index, active);
        const values = config.resolveNamedOptions(scopes, names, context, prefixes);
        if (values.$shared) {
          values.$shared = sharing;
          cache[cacheKey] = Object.freeze(cloneIfNotShared(values, sharing));
        }
        return values;
      }
      _resolveAnimations(index, transition, active) {
        const me = this;
        const chart = me.chart;
        const cache = me._cachedDataOpts;
        const cacheKey = `animation-${transition}`;
        const cached = cache[cacheKey];
        if (cached) {
          return cached;
        }
        let options;
        if (chart.options.animation !== false) {
          const config = me.chart.config;
          const scopeKeys = config.datasetAnimationScopeKeys(me._type, transition);
          const scopes = config.getOptionScopes(me.getDataset(), scopeKeys);
          options = config.createResolver(scopes, me.getContext(index, active, transition));
        }
        const animations = new Animations(chart, options && options.animations);
        if (options && options._cacheable) {
          cache[cacheKey] = Object.freeze(animations);
        }
        return animations;
      }
      getSharedOptions(options) {
        if (!options.$shared) {
          return;
        }
        return this._sharedOptions || (this._sharedOptions = Object.assign({}, options));
      }
      includeOptions(mode, sharedOptions) {
        return !sharedOptions || isDirectUpdateMode(mode) || this.chart._animationsDisabled;
      }
      updateElement(element, index, properties, mode) {
        if (isDirectUpdateMode(mode)) {
          Object.assign(element, properties);
        } else {
          this._resolveAnimations(index, mode).update(element, properties);
        }
      }
      updateSharedOptions(sharedOptions, mode, newOptions) {
        if (sharedOptions && !isDirectUpdateMode(mode)) {
          this._resolveAnimations(undefined, mode).update(sharedOptions, newOptions);
        }
      }
      _setStyle(element, index, mode, active) {
        element.active = active;
        const options = this.getStyle(index, active);
        this._resolveAnimations(index, mode, active).update(element, {
          options: (!active && this.getSharedOptions(options)) || options
        });
      }
      removeHoverStyle(element, datasetIndex, index) {
        this._setStyle(element, index, 'active', false);
      }
      setHoverStyle(element, datasetIndex, index) {
        this._setStyle(element, index, 'active', true);
      }
      _removeDatasetHoverStyle() {
        const element = this._cachedMeta.dataset;
        if (element) {
          this._setStyle(element, undefined, 'active', false);
        }
      }
      _setDatasetHoverStyle() {
        const element = this._cachedMeta.dataset;
        if (element) {
          this._setStyle(element, undefined, 'active', true);
        }
      }
      _resyncElements(resetNewElements) {
        const me = this;
        const data = me._data;
        const elements = me._cachedMeta.data;
        for (const [method, arg1, arg2] of me._syncList) {
          me[method](arg1, arg2);
        }
        me._syncList = [];
        const numMeta = elements.length;
        const numData = data.length;
        const count = Math.min(numData, numMeta);
        if (count) {
          me.parse(0, count);
        }
        if (numData > numMeta) {
          me._insertElements(numMeta, numData - numMeta, resetNewElements);
        } else if (numData < numMeta) {
          me._removeElements(numData, numMeta - numData);
        }
      }
      _insertElements(start, count, resetNewElements = true) {
        const me = this;
        const meta = me._cachedMeta;
        const data = meta.data;
        const end = start + count;
        let i;
        const move = (arr) => {
          arr.length += count;
          for (i = arr.length - 1; i >= end; i--) {
            arr[i] = arr[i - count];
          }
        };
        move(data);
        for (i = start; i < end; ++i) {
          data[i] = new me.dataElementType();
        }
        if (me._parsing) {
          move(meta._parsed);
        }
        me.parse(start, count);
        if (resetNewElements) {
          me.updateElements(data, start, count, 'reset');
        }
      }
      updateElements(element, start, count, mode) {}
      _removeElements(start, count) {
        const me = this;
        const meta = me._cachedMeta;
        if (me._parsing) {
          const removed = meta._parsed.splice(start, count);
          if (meta._stacked) {
            clearStacks(meta, removed);
          }
        }
        meta.data.splice(start, count);
      }
      _sync(args) {
        if (this._parsing) {
          this._syncList.push(args);
        } else {
          const [method, arg1, arg2] = args;
          this[method](arg1, arg2);
        }
      }
      _onDataPush() {
        const count = arguments.length;
        this._sync(['_insertElements', this.getDataset().data.length - count, count]);
      }
      _onDataPop() {
        this._sync(['_removeElements', this._cachedMeta.data.length - 1, 1]);
      }
      _onDataShift() {
        this._sync(['_removeElements', 0, 1]);
      }
      _onDataSplice(start, count) {
        this._sync(['_removeElements', start, count]);
        this._sync(['_insertElements', start, arguments.length - 2]);
      }
      _onDataUnshift() {
        this._sync(['_insertElements', 0, arguments.length]);
      }
    }
    DatasetController.defaults = {};
    DatasetController.prototype.datasetElementType = null;
    DatasetController.prototype.dataElementType = null;
    
    class Element {
      constructor() {
        this.x = undefined;
        this.y = undefined;
        this.active = false;
        this.options = undefined;
        this.$animations = undefined;
      }
      tooltipPosition(useFinalPosition) {
        const {x, y} = this.getProps(['x', 'y'], useFinalPosition);
        return {x, y};
      }
      hasValue() {
        return isNumber(this.x) && isNumber(this.y);
      }
      getProps(props, final) {
        const me = this;
        const anims = this.$animations;
        if (!final || !anims) {
          return me;
        }
        const ret = {};
        props.forEach(prop => {
          ret[prop] = anims[prop] && anims[prop].active() ? anims[prop]._to : me[prop];
        });
        return ret;
      }
    }
    Element.defaults = {};
    Element.defaultRoutes = undefined;
    
    const formatters = {
      values(value) {
        return isArray(value) ? value : '' + value;
      },
      numeric(tickValue, index, ticks) {
        if (tickValue === 0) {
          return '0';
        }
        const locale = this.chart.options.locale;
        let notation;
        let delta = tickValue;
        if (ticks.length > 1) {
          const maxTick = Math.max(Math.abs(ticks[0].value), Math.abs(ticks[ticks.length - 1].value));
          if (maxTick < 1e-4 || maxTick > 1e+15) {
            notation = 'scientific';
          }
          delta = calculateDelta(tickValue, ticks);
        }
        const logDelta = log10(Math.abs(delta));
        const numDecimal = Math.max(Math.min(-1 * Math.floor(logDelta), 20), 0);
        const options = {notation, minimumFractionDigits: numDecimal, maximumFractionDigits: numDecimal};
        Object.assign(options, this.options.ticks.format);
        return formatNumber(tickValue, locale, options);
      },
      logarithmic(tickValue, index, ticks) {
        if (tickValue === 0) {
          return '0';
        }
        const remain = tickValue / (Math.pow(10, Math.floor(log10(tickValue))));
        if (remain === 1 || remain === 2 || remain === 5) {
          return formatters.numeric.call(this, tickValue, index, ticks);
        }
        return '';
      }
    };
    function calculateDelta(tickValue, ticks) {
      let delta = ticks.length > 3 ? ticks[2].value - ticks[1].value : ticks[1].value - ticks[0].value;
      if (Math.abs(delta) >= 1 && tickValue !== Math.floor(tickValue)) {
        delta = tickValue - Math.floor(tickValue);
      }
      return delta;
    }
    var Ticks = {formatters};
    
    defaults.set('scale', {
      display: true,
      offset: false,
      reverse: false,
      beginAtZero: false,
      bounds: 'ticks',
      grace: 0,
      grid: {
        display: true,
        lineWidth: 1,
        drawBorder: true,
        drawOnChartArea: true,
        drawTicks: true,
        tickLength: 8,
        tickWidth: (_ctx, options) => options.lineWidth,
        tickColor: (_ctx, options) => options.color,
        offset: false,
        borderDash: [],
        borderDashOffset: 0.0,
        borderWidth: 1
      },
      title: {
        display: false,
        text: '',
        padding: {
          top: 4,
          bottom: 4
        }
      },
      ticks: {
        minRotation: 0,
        maxRotation: 50,
        mirror: false,
        textStrokeWidth: 0,
        textStrokeColor: '',
        padding: 3,
        display: true,
        autoSkip: true,
        autoSkipPadding: 3,
        labelOffset: 0,
        callback: Ticks.formatters.values,
        minor: {},
        major: {},
        align: 'center',
        crossAlign: 'near',
        showLabelBackdrop: false,
        backdropColor: 'rgba(255, 255, 255, 0.75)',
        backdropPadding: 2,
      }
    });
    defaults.route('scale.ticks', 'color', '', 'color');
    defaults.route('scale.grid', 'color', '', 'borderColor');
    defaults.route('scale.grid', 'borderColor', '', 'borderColor');
    defaults.route('scale.title', 'color', '', 'color');
    defaults.describe('scale', {
      _fallback: false,
      _scriptable: (name) => !name.startsWith('before') && !name.startsWith('after') && name !== 'callback' && name !== 'parser',
      _indexable: (name) => name !== 'borderDash' && name !== 'tickBorderDash',
    });
    defaults.describe('scales', {
      _fallback: 'scale',
    });
    defaults.describe('scale.ticks', {
      _scriptable: (name) => name !== 'backdropPadding' && name !== 'callback',
      _indexable: (name) => name !== 'backdropPadding',
    });
    
    function autoSkip(scale, ticks) {
      const tickOpts = scale.options.ticks;
      const ticksLimit = tickOpts.maxTicksLimit || determineMaxTicks(scale);
      const majorIndices = tickOpts.major.enabled ? getMajorIndices(ticks) : [];
      const numMajorIndices = majorIndices.length;
      const first = majorIndices[0];
      const last = majorIndices[numMajorIndices - 1];
      const newTicks = [];
      if (numMajorIndices > ticksLimit) {
        skipMajors(ticks, newTicks, majorIndices, numMajorIndices / ticksLimit);
        return newTicks;
      }
      const spacing = calculateSpacing(majorIndices, ticks, ticksLimit);
      if (numMajorIndices > 0) {
        let i, ilen;
        const avgMajorSpacing = numMajorIndices > 1 ? Math.round((last - first) / (numMajorIndices - 1)) : null;
        skip(ticks, newTicks, spacing, isNullOrUndef(avgMajorSpacing) ? 0 : first - avgMajorSpacing, first);
        for (i = 0, ilen = numMajorIndices - 1; i < ilen; i++) {
          skip(ticks, newTicks, spacing, majorIndices[i], majorIndices[i + 1]);
        }
        skip(ticks, newTicks, spacing, last, isNullOrUndef(avgMajorSpacing) ? ticks.length : last + avgMajorSpacing);
        return newTicks;
      }
      skip(ticks, newTicks, spacing);
      return newTicks;
    }
    function determineMaxTicks(scale) {
      const offset = scale.options.offset;
      const tickLength = scale._tickSize();
      const maxScale = scale._length / tickLength + (offset ? 0 : 1);
      const maxChart = scale._maxLength / tickLength;
      return Math.floor(Math.min(maxScale, maxChart));
    }
    function calculateSpacing(majorIndices, ticks, ticksLimit) {
      const evenMajorSpacing = getEvenSpacing(majorIndices);
      const spacing = ticks.length / ticksLimit;
      if (!evenMajorSpacing) {
        return Math.max(spacing, 1);
      }
      const factors = _factorize(evenMajorSpacing);
      for (let i = 0, ilen = factors.length - 1; i < ilen; i++) {
        const factor = factors[i];
        if (factor > spacing) {
          return factor;
        }
      }
      return Math.max(spacing, 1);
    }
    function getMajorIndices(ticks) {
      const result = [];
      let i, ilen;
      for (i = 0, ilen = ticks.length; i < ilen; i++) {
        if (ticks[i].major) {
          result.push(i);
        }
      }
      return result;
    }
    function skipMajors(ticks, newTicks, majorIndices, spacing) {
      let count = 0;
      let next = majorIndices[0];
      let i;
      spacing = Math.ceil(spacing);
      for (i = 0; i < ticks.length; i++) {
        if (i === next) {
          newTicks.push(ticks[i]);
          count++;
          next = majorIndices[count * spacing];
        }
      }
    }
    function skip(ticks, newTicks, spacing, majorStart, majorEnd) {
      const start = valueOrDefault(majorStart, 0);
      const end = Math.min(valueOrDefault(majorEnd, ticks.length), ticks.length);
      let count = 0;
      let length, i, next;
      spacing = Math.ceil(spacing);
      if (majorEnd) {
        length = majorEnd - majorStart;
        spacing = length / Math.floor(length / spacing);
      }
      next = start;
      while (next < 0) {
        count++;
        next = Math.round(start + count * spacing);
      }
      for (i = Math.max(start, 0); i < end; i++) {
        if (i === next) {
          newTicks.push(ticks[i]);
          count++;
          next = Math.round(start + count * spacing);
        }
      }
    }
    function getEvenSpacing(arr) {
      const len = arr.length;
      let i, diff;
      if (len < 2) {
        return false;
      }
      for (diff = arr[0], i = 1; i < len; ++i) {
        if (arr[i] - arr[i - 1] !== diff) {
          return false;
        }
      }
      return diff;
    }
    
    const reverseAlign = (align) => align === 'left' ? 'right' : align === 'right' ? 'left' : align;
    const offsetFromEdge = (scale, edge, offset) => edge === 'top' || edge === 'left' ? scale[edge] + offset : scale[edge] - offset;
    function sample(arr, numItems) {
      const result = [];
      const increment = arr.length / numItems;
      const len = arr.length;
      let i = 0;
      for (; i < len; i += increment) {
        result.push(arr[Math.floor(i)]);
      }
      return result;
    }
    function getPixelForGridLine(scale, index, offsetGridLines) {
      const length = scale.ticks.length;
      const validIndex = Math.min(index, length - 1);
      const start = scale._startPixel;
      const end = scale._endPixel;
      const epsilon = 1e-6;
      let lineValue = scale.getPixelForTick(validIndex);
      let offset;
      if (offsetGridLines) {
        if (length === 1) {
          offset = Math.max(lineValue - start, end - lineValue);
        } else if (index === 0) {
          offset = (scale.getPixelForTick(1) - lineValue) / 2;
        } else {
          offset = (lineValue - scale.getPixelForTick(validIndex - 1)) / 2;
        }
        lineValue += validIndex < index ? offset : -offset;
        if (lineValue < start - epsilon || lineValue > end + epsilon) {
          return;
        }
      }
      return lineValue;
    }
    function garbageCollect(caches, length) {
      each(caches, (cache) => {
        const gc = cache.gc;
        const gcLen = gc.length / 2;
        let i;
        if (gcLen > length) {
          for (i = 0; i < gcLen; ++i) {
            delete cache.data[gc[i]];
          }
          gc.splice(0, gcLen);
        }
      });
    }
    function getTickMarkLength(options) {
      return options.drawTicks ? options.tickLength : 0;
    }
    function getTitleHeight(options, fallback) {
      if (!options.display) {
        return 0;
      }
      const font = toFont(options.font, fallback);
      const padding = toPadding(options.padding);
      const lines = isArray(options.text) ? options.text.length : 1;
      return (lines * font.lineHeight) + padding.height;
    }
    function createScaleContext(parent, scale) {
      return Object.assign(Object.create(parent), {
        scale,
        type: 'scale'
      });
    }
    function createTickContext(parent, index, tick) {
      return Object.assign(Object.create(parent), {
        tick,
        index,
        type: 'tick'
      });
    }
    function titleAlign(align, position, reverse) {
      let ret = _toLeftRightCenter(align);
      if ((reverse && position !== 'right') || (!reverse && position === 'right')) {
        ret = reverseAlign(ret);
      }
      return ret;
    }
    function titleArgs(scale, offset, position, align) {
      const {top, left, bottom, right, chart} = scale;
      const {chartArea, scales} = chart;
      let rotation = 0;
      let maxWidth, titleX, titleY;
      const height = bottom - top;
      const width = right - left;
      if (scale.isHorizontal()) {
        titleX = _alignStartEnd(align, left, right);
        if (isObject(position)) {
          const positionAxisID = Object.keys(position)[0];
          const value = position[positionAxisID];
          titleY = scales[positionAxisID].getPixelForValue(value) + height - offset;
        } else if (position === 'center') {
          titleY = (chartArea.bottom + chartArea.top) / 2 + height - offset;
        } else {
          titleY = offsetFromEdge(scale, position, offset);
        }
        maxWidth = right - left;
      } else {
        if (isObject(position)) {
          const positionAxisID = Object.keys(position)[0];
          const value = position[positionAxisID];
          titleX = scales[positionAxisID].getPixelForValue(value) - width + offset;
        } else if (position === 'center') {
          titleX = (chartArea.left + chartArea.right) / 2 - width + offset;
        } else {
          titleX = offsetFromEdge(scale, position, offset);
        }
        titleY = _alignStartEnd(align, bottom, top);
        rotation = position === 'left' ? -HALF_PI : HALF_PI;
      }
      return {titleX, titleY, maxWidth, rotation};
    }
    class Scale extends Element {
      constructor(cfg) {
        super();
        this.id = cfg.id;
        this.type = cfg.type;
        this.options = undefined;
        this.ctx = cfg.ctx;
        this.chart = cfg.chart;
        this.top = undefined;
        this.bottom = undefined;
        this.left = undefined;
        this.right = undefined;
        this.width = undefined;
        this.height = undefined;
        this._margins = {
          left: 0,
          right: 0,
          top: 0,
          bottom: 0
        };
        this.maxWidth = undefined;
        this.maxHeight = undefined;
        this.paddingTop = undefined;
        this.paddingBottom = undefined;
        this.paddingLeft = undefined;
        this.paddingRight = undefined;
        this.axis = undefined;
        this.labelRotation = undefined;
        this.min = undefined;
        this.max = undefined;
        this._range = undefined;
        this.ticks = [];
        this._gridLineItems = null;
        this._labelItems = null;
        this._labelSizes = null;
        this._length = 0;
        this._maxLength = 0;
        this._longestTextCache = {};
        this._startPixel = undefined;
        this._endPixel = undefined;
        this._reversePixels = false;
        this._userMax = undefined;
        this._userMin = undefined;
        this._suggestedMax = undefined;
        this._suggestedMin = undefined;
        this._ticksLength = 0;
        this._borderValue = 0;
        this._cache = {};
        this._dataLimitsCached = false;
        this.$context = undefined;
      }
      init(options) {
        const me = this;
        me.options = options.setContext(me.getContext());
        me.axis = options.axis;
        me._userMin = me.parse(options.min);
        me._userMax = me.parse(options.max);
        me._suggestedMin = me.parse(options.suggestedMin);
        me._suggestedMax = me.parse(options.suggestedMax);
      }
      parse(raw, index) {
        return raw;
      }
      getUserBounds() {
        let {_userMin, _userMax, _suggestedMin, _suggestedMax} = this;
        _userMin = finiteOrDefault(_userMin, Number.POSITIVE_INFINITY);
        _userMax = finiteOrDefault(_userMax, Number.NEGATIVE_INFINITY);
        _suggestedMin = finiteOrDefault(_suggestedMin, Number.POSITIVE_INFINITY);
        _suggestedMax = finiteOrDefault(_suggestedMax, Number.NEGATIVE_INFINITY);
        return {
          min: finiteOrDefault(_userMin, _suggestedMin),
          max: finiteOrDefault(_userMax, _suggestedMax),
          minDefined: isNumberFinite(_userMin),
          maxDefined: isNumberFinite(_userMax)
        };
      }
      getMinMax(canStack) {
        const me = this;
        let {min, max, minDefined, maxDefined} = me.getUserBounds();
        let range;
        if (minDefined && maxDefined) {
          return {min, max};
        }
        const metas = me.getMatchingVisibleMetas();
        for (let i = 0, ilen = metas.length; i < ilen; ++i) {
          range = metas[i].controller.getMinMax(me, canStack);
          if (!minDefined) {
            min = Math.min(min, range.min);
          }
          if (!maxDefined) {
            max = Math.max(max, range.max);
          }
        }
        return {
          min: finiteOrDefault(min, finiteOrDefault(max, min)),
          max: finiteOrDefault(max, finiteOrDefault(min, max))
        };
      }
      getPadding() {
        const me = this;
        return {
          left: me.paddingLeft || 0,
          top: me.paddingTop || 0,
          right: me.paddingRight || 0,
          bottom: me.paddingBottom || 0
        };
      }
      getTicks() {
        return this.ticks;
      }
      getLabels() {
        const data = this.chart.data;
        return this.options.labels || (this.isHorizontal() ? data.xLabels : data.yLabels) || data.labels || [];
      }
      beforeLayout() {
        this._cache = {};
        this._dataLimitsCached = false;
      }
      beforeUpdate() {
        callback(this.options.beforeUpdate, [this]);
      }
      update(maxWidth, maxHeight, margins) {
        const me = this;
        const tickOpts = me.options.ticks;
        const sampleSize = tickOpts.sampleSize;
        me.beforeUpdate();
        me.maxWidth = maxWidth;
        me.maxHeight = maxHeight;
        me._margins = margins = Object.assign({
          left: 0,
          right: 0,
          top: 0,
          bottom: 0
        }, margins);
        me.ticks = null;
        me._labelSizes = null;
        me._gridLineItems = null;
        me._labelItems = null;
        me.beforeSetDimensions();
        me.setDimensions();
        me.afterSetDimensions();
        me._maxLength = me.isHorizontal()
          ? me.width + margins.left + margins.right
          : me.height + margins.top + margins.bottom;
        if (!me._dataLimitsCached) {
          me.beforeDataLimits();
          me.determineDataLimits();
          me.afterDataLimits();
          me._range = _addGrace(me, me.options.grace);
          me._dataLimitsCached = true;
        }
        me.beforeBuildTicks();
        me.ticks = me.buildTicks() || [];
        me.afterBuildTicks();
        const samplingEnabled = sampleSize < me.ticks.length;
        me._convertTicksToLabels(samplingEnabled ? sample(me.ticks, sampleSize) : me.ticks);
        me.configure();
        me.beforeCalculateLabelRotation();
        me.calculateLabelRotation();
        me.afterCalculateLabelRotation();
        if (tickOpts.display && (tickOpts.autoSkip || tickOpts.source === 'auto')) {
          me.ticks = autoSkip(me, me.ticks);
          me._labelSizes = null;
        }
        if (samplingEnabled) {
          me._convertTicksToLabels(me.ticks);
        }
        me.beforeFit();
        me.fit();
        me.afterFit();
        me.afterUpdate();
      }
      configure() {
        const me = this;
        let reversePixels = me.options.reverse;
        let startPixel, endPixel;
        if (me.isHorizontal()) {
          startPixel = me.left;
          endPixel = me.right;
        } else {
          startPixel = me.top;
          endPixel = me.bottom;
          reversePixels = !reversePixels;
        }
        me._startPixel = startPixel;
        me._endPixel = endPixel;
        me._reversePixels = reversePixels;
        me._length = endPixel - startPixel;
        me._alignToPixels = me.options.alignToPixels;
      }
      afterUpdate() {
        callback(this.options.afterUpdate, [this]);
      }
      beforeSetDimensions() {
        callback(this.options.beforeSetDimensions, [this]);
      }
      setDimensions() {
        const me = this;
        if (me.isHorizontal()) {
          me.width = me.maxWidth;
          me.left = 0;
          me.right = me.width;
        } else {
          me.height = me.maxHeight;
          me.top = 0;
          me.bottom = me.height;
        }
        me.paddingLeft = 0;
        me.paddingTop = 0;
        me.paddingRight = 0;
        me.paddingBottom = 0;
      }
      afterSetDimensions() {
        callback(this.options.afterSetDimensions, [this]);
      }
      _callHooks(name) {
        const me = this;
        me.chart.notifyPlugins(name, me.getContext());
        callback(me.options[name], [me]);
      }
      beforeDataLimits() {
        this._callHooks('beforeDataLimits');
      }
      determineDataLimits() {}
      afterDataLimits() {
        this._callHooks('afterDataLimits');
      }
      beforeBuildTicks() {
        this._callHooks('beforeBuildTicks');
      }
      buildTicks() {
        return [];
      }
      afterBuildTicks() {
        this._callHooks('afterBuildTicks');
      }
      beforeTickToLabelConversion() {
        callback(this.options.beforeTickToLabelConversion, [this]);
      }
      generateTickLabels(ticks) {
        const me = this;
        const tickOpts = me.options.ticks;
        let i, ilen, tick;
        for (i = 0, ilen = ticks.length; i < ilen; i++) {
          tick = ticks[i];
          tick.label = callback(tickOpts.callback, [tick.value, i, ticks], me);
        }
      }
      afterTickToLabelConversion() {
        callback(this.options.afterTickToLabelConversion, [this]);
      }
      beforeCalculateLabelRotation() {
        callback(this.options.beforeCalculateLabelRotation, [this]);
      }
      calculateLabelRotation() {
        const me = this;
        const options = me.options;
        const tickOpts = options.ticks;
        const numTicks = me.ticks.length;
        const minRotation = tickOpts.minRotation || 0;
        const maxRotation = tickOpts.maxRotation;
        let labelRotation = minRotation;
        let tickWidth, maxHeight, maxLabelDiagonal;
        if (!me._isVisible() || !tickOpts.display || minRotation >= maxRotation || numTicks <= 1 || !me.isHorizontal()) {
          me.labelRotation = minRotation;
          return;
        }
        const labelSizes = me._getLabelSizes();
        const maxLabelWidth = labelSizes.widest.width;
        const maxLabelHeight = labelSizes.highest.height;
        const maxWidth = _limitValue(me.chart.width - maxLabelWidth, 0, me.maxWidth);
        tickWidth = options.offset ? me.maxWidth / numTicks : maxWidth / (numTicks - 1);
        if (maxLabelWidth + 6 > tickWidth) {
          tickWidth = maxWidth / (numTicks - (options.offset ? 0.5 : 1));
          maxHeight = me.maxHeight - getTickMarkLength(options.grid)
                    - tickOpts.padding - getTitleHeight(options.title, me.chart.options.font);
          maxLabelDiagonal = Math.sqrt(maxLabelWidth * maxLabelWidth + maxLabelHeight * maxLabelHeight);
          labelRotation = toDegrees(Math.min(
            Math.asin(_limitValue((labelSizes.highest.height + 6) / tickWidth, -1, 1)),
            Math.asin(_limitValue(maxHeight / maxLabelDiagonal, -1, 1)) - Math.asin(_limitValue(maxLabelHeight / maxLabelDiagonal, -1, 1))
          ));
          labelRotation = Math.max(minRotation, Math.min(maxRotation, labelRotation));
        }
        me.labelRotation = labelRotation;
      }
      afterCalculateLabelRotation() {
        callback(this.options.afterCalculateLabelRotation, [this]);
      }
      beforeFit() {
        callback(this.options.beforeFit, [this]);
      }
      fit() {
        const me = this;
        const minSize = {
          width: 0,
          height: 0
        };
        const {chart, options: {ticks: tickOpts, title: titleOpts, grid: gridOpts}} = me;
        const display = me._isVisible();
        const isHorizontal = me.isHorizontal();
        if (display) {
          const titleHeight = getTitleHeight(titleOpts, chart.options.font);
          if (isHorizontal) {
            minSize.width = me.maxWidth;
            minSize.height = getTickMarkLength(gridOpts) + titleHeight;
          } else {
            minSize.height = me.maxHeight;
            minSize.width = getTickMarkLength(gridOpts) + titleHeight;
          }
          if (tickOpts.display && me.ticks.length) {
            const {first, last, widest, highest} = me._getLabelSizes();
            const tickPadding = tickOpts.padding * 2;
            const angleRadians = toRadians(me.labelRotation);
            const cos = Math.cos(angleRadians);
            const sin = Math.sin(angleRadians);
            if (isHorizontal) {
              const labelHeight = tickOpts.mirror ? 0 : sin * widest.width + cos * highest.height;
              minSize.height = Math.min(me.maxHeight, minSize.height + labelHeight + tickPadding);
            } else {
              const labelWidth = tickOpts.mirror ? 0 : cos * widest.width + sin * highest.height;
              minSize.width = Math.min(me.maxWidth, minSize.width + labelWidth + tickPadding);
            }
            me._calculatePadding(first, last, sin, cos);
          }
        }
        me._handleMargins();
        if (isHorizontal) {
          me.width = me._length = chart.width - me._margins.left - me._margins.right;
          me.height = minSize.height;
        } else {
          me.width = minSize.width;
          me.height = me._length = chart.height - me._margins.top - me._margins.bottom;
        }
      }
      _calculatePadding(first, last, sin, cos) {
        const me = this;
        const {ticks: {align, padding}, position} = me.options;
        const isRotated = me.labelRotation !== 0;
        const labelsBelowTicks = position !== 'top' && me.axis === 'x';
        if (me.isHorizontal()) {
          const offsetLeft = me.getPixelForTick(0) - me.left;
          const offsetRight = me.right - me.getPixelForTick(me.ticks.length - 1);
          let paddingLeft = 0;
          let paddingRight = 0;
          if (isRotated) {
            if (labelsBelowTicks) {
              paddingLeft = cos * first.width;
              paddingRight = sin * last.height;
            } else {
              paddingLeft = sin * first.height;
              paddingRight = cos * last.width;
            }
          } else if (align === 'start') {
            paddingRight = last.width;
          } else if (align === 'end') {
            paddingLeft = first.width;
          } else {
            paddingLeft = first.width / 2;
            paddingRight = last.width / 2;
          }
          me.paddingLeft = Math.max((paddingLeft - offsetLeft + padding) * me.width / (me.width - offsetLeft), 0);
          me.paddingRight = Math.max((paddingRight - offsetRight + padding) * me.width / (me.width - offsetRight), 0);
        } else {
          let paddingTop = last.height / 2;
          let paddingBottom = first.height / 2;
          if (align === 'start') {
            paddingTop = 0;
            paddingBottom = first.height;
          } else if (align === 'end') {
            paddingTop = last.height;
            paddingBottom = 0;
          }
          me.paddingTop = paddingTop + padding;
          me.paddingBottom = paddingBottom + padding;
        }
      }
      _handleMargins() {
        const me = this;
        if (me._margins) {
          me._margins.left = Math.max(me.paddingLeft, me._margins.left);
          me._margins.top = Math.max(me.paddingTop, me._margins.top);
          me._margins.right = Math.max(me.paddingRight, me._margins.right);
          me._margins.bottom = Math.max(me.paddingBottom, me._margins.bottom);
        }
      }
      afterFit() {
        callback(this.options.afterFit, [this]);
      }
      isHorizontal() {
        const {axis, position} = this.options;
        return position === 'top' || position === 'bottom' || axis === 'x';
      }
      isFullSize() {
        return this.options.fullSize;
      }
      _convertTicksToLabels(ticks) {
        const me = this;
        me.beforeTickToLabelConversion();
        me.generateTickLabels(ticks);
        let i, ilen;
        for (i = 0, ilen = ticks.length; i < ilen; i++) {
          if (isNullOrUndef(ticks[i].label)) {
            ticks.splice(i, 1);
            ilen--;
            i--;
          }
        }
        me.afterTickToLabelConversion();
      }
      _getLabelSizes() {
        const me = this;
        let labelSizes = me._labelSizes;
        if (!labelSizes) {
          const sampleSize = me.options.ticks.sampleSize;
          let ticks = me.ticks;
          if (sampleSize < ticks.length) {
            ticks = sample(ticks, sampleSize);
          }
          me._labelSizes = labelSizes = me._computeLabelSizes(ticks, ticks.length);
        }
        return labelSizes;
      }
      _computeLabelSizes(ticks, length) {
        const {ctx, _longestTextCache: caches} = this;
        const widths = [];
        const heights = [];
        let widestLabelSize = 0;
        let highestLabelSize = 0;
        let i, j, jlen, label, tickFont, fontString, cache, lineHeight, width, height, nestedLabel;
        for (i = 0; i < length; ++i) {
          label = ticks[i].label;
          tickFont = this._resolveTickFontOptions(i);
          ctx.font = fontString = tickFont.string;
          cache = caches[fontString] = caches[fontString] || {data: {}, gc: []};
          lineHeight = tickFont.lineHeight;
          width = height = 0;
          if (!isNullOrUndef(label) && !isArray(label)) {
            width = _measureText(ctx, cache.data, cache.gc, width, label);
            height = lineHeight;
          } else if (isArray(label)) {
            for (j = 0, jlen = label.length; j < jlen; ++j) {
              nestedLabel = label[j];
              if (!isNullOrUndef(nestedLabel) && !isArray(nestedLabel)) {
                width = _measureText(ctx, cache.data, cache.gc, width, nestedLabel);
                height += lineHeight;
              }
            }
          }
          widths.push(width);
          heights.push(height);
          widestLabelSize = Math.max(width, widestLabelSize);
          highestLabelSize = Math.max(height, highestLabelSize);
        }
        garbageCollect(caches, length);
        const widest = widths.indexOf(widestLabelSize);
        const highest = heights.indexOf(highestLabelSize);
        const valueAt = (idx) => ({width: widths[idx] || 0, height: heights[idx] || 0});
        return {
          first: valueAt(0),
          last: valueAt(length - 1),
          widest: valueAt(widest),
          highest: valueAt(highest),
          widths,
          heights,
        };
      }
      getLabelForValue(value) {
        return value;
      }
      getPixelForValue(value, index) {
        return NaN;
      }
      getValueForPixel(pixel) {}
      getPixelForTick(index) {
        const ticks = this.ticks;
        if (index < 0 || index > ticks.length - 1) {
          return null;
        }
        return this.getPixelForValue(ticks[index].value);
      }
      getPixelForDecimal(decimal) {
        const me = this;
        if (me._reversePixels) {
          decimal = 1 - decimal;
        }
        const pixel = me._startPixel + decimal * me._length;
        return _int16Range(me._alignToPixels ? _alignPixel(me.chart, pixel, 0) : pixel);
      }
      getDecimalForPixel(pixel) {
        const decimal = (pixel - this._startPixel) / this._length;
        return this._reversePixels ? 1 - decimal : decimal;
      }
      getBasePixel() {
        return this.getPixelForValue(this.getBaseValue());
      }
      getBaseValue() {
        const {min, max} = this;
        return min < 0 && max < 0 ? max :
          min > 0 && max > 0 ? min :
          0;
      }
      getContext(index) {
        const me = this;
        const ticks = me.ticks || [];
        if (index >= 0 && index < ticks.length) {
          const tick = ticks[index];
          return tick.$context ||
                    (tick.$context = createTickContext(me.getContext(), index, tick));
        }
        return me.$context ||
                (me.$context = createScaleContext(me.chart.getContext(), me));
      }
      _tickSize() {
        const me = this;
        const optionTicks = me.options.ticks;
        const rot = toRadians(me.labelRotation);
        const cos = Math.abs(Math.cos(rot));
        const sin = Math.abs(Math.sin(rot));
        const labelSizes = me._getLabelSizes();
        const padding = optionTicks.autoSkipPadding || 0;
        const w = labelSizes ? labelSizes.widest.width + padding : 0;
        const h = labelSizes ? labelSizes.highest.height + padding : 0;
        return me.isHorizontal()
          ? h * cos > w * sin ? w / cos : h / sin
          : h * sin < w * cos ? h / cos : w / sin;
      }
      _isVisible() {
        const display = this.options.display;
        if (display !== 'auto') {
          return !!display;
        }
        return this.getMatchingVisibleMetas().length > 0;
      }
      _computeGridLineItems(chartArea) {
        const me = this;
        const axis = me.axis;
        const chart = me.chart;
        const options = me.options;
        const {grid, position} = options;
        const offset = grid.offset;
        const isHorizontal = me.isHorizontal();
        const ticks = me.ticks;
        const ticksLength = ticks.length + (offset ? 1 : 0);
        const tl = getTickMarkLength(grid);
        const items = [];
        const borderOpts = grid.setContext(me.getContext());
        const axisWidth = borderOpts.drawBorder ? borderOpts.borderWidth : 0;
        const axisHalfWidth = axisWidth / 2;
        const alignBorderValue = function(pixel) {
          return _alignPixel(chart, pixel, axisWidth);
        };
        let borderValue, i, lineValue, alignedLineValue;
        let tx1, ty1, tx2, ty2, x1, y1, x2, y2;
        if (position === 'top') {
          borderValue = alignBorderValue(me.bottom);
          ty1 = me.bottom - tl;
          ty2 = borderValue - axisHalfWidth;
          y1 = alignBorderValue(chartArea.top) + axisHalfWidth;
          y2 = chartArea.bottom;
        } else if (position === 'bottom') {
          borderValue = alignBorderValue(me.top);
          y1 = chartArea.top;
          y2 = alignBorderValue(chartArea.bottom) - axisHalfWidth;
          ty1 = borderValue + axisHalfWidth;
          ty2 = me.top + tl;
        } else if (position === 'left') {
          borderValue = alignBorderValue(me.right);
          tx1 = me.right - tl;
          tx2 = borderValue - axisHalfWidth;
          x1 = alignBorderValue(chartArea.left) + axisHalfWidth;
          x2 = chartArea.right;
        } else if (position === 'right') {
          borderValue = alignBorderValue(me.left);
          x1 = chartArea.left;
          x2 = alignBorderValue(chartArea.right) - axisHalfWidth;
          tx1 = borderValue + axisHalfWidth;
          tx2 = me.left + tl;
        } else if (axis === 'x') {
          if (position === 'center') {
            borderValue = alignBorderValue((chartArea.top + chartArea.bottom) / 2 + 0.5);
          } else if (isObject(position)) {
            const positionAxisID = Object.keys(position)[0];
            const value = position[positionAxisID];
            borderValue = alignBorderValue(me.chart.scales[positionAxisID].getPixelForValue(value));
          }
          y1 = chartArea.top;
          y2 = chartArea.bottom;
          ty1 = borderValue + axisHalfWidth;
          ty2 = ty1 + tl;
        } else if (axis === 'y') {
          if (position === 'center') {
            borderValue = alignBorderValue((chartArea.left + chartArea.right) / 2);
          } else if (isObject(position)) {
            const positionAxisID = Object.keys(position)[0];
            const value = position[positionAxisID];
            borderValue = alignBorderValue(me.chart.scales[positionAxisID].getPixelForValue(value));
          }
          tx1 = borderValue - axisHalfWidth;
          tx2 = tx1 - tl;
          x1 = chartArea.left;
          x2 = chartArea.right;
        }
        const limit = valueOrDefault(options.ticks.maxTicksLimit, ticksLength);
        const step = Math.max(1, Math.ceil(ticksLength / limit));
        for (i = 0; i < ticksLength; i += step) {
          const optsAtIndex = grid.setContext(me.getContext(i));
          const lineWidth = optsAtIndex.lineWidth;
          const lineColor = optsAtIndex.color;
          const borderDash = grid.borderDash || [];
          const borderDashOffset = optsAtIndex.borderDashOffset;
          const tickWidth = optsAtIndex.tickWidth;
          const tickColor = optsAtIndex.tickColor;
          const tickBorderDash = optsAtIndex.tickBorderDash || [];
          const tickBorderDashOffset = optsAtIndex.tickBorderDashOffset;
          lineValue = getPixelForGridLine(me, i, offset);
          if (lineValue === undefined) {
            continue;
          }
          alignedLineValue = _alignPixel(chart, lineValue, lineWidth);
          if (isHorizontal) {
            tx1 = tx2 = x1 = x2 = alignedLineValue;
          } else {
            ty1 = ty2 = y1 = y2 = alignedLineValue;
          }
          items.push({
            tx1,
            ty1,
            tx2,
            ty2,
            x1,
            y1,
            x2,
            y2,
            width: lineWidth,
            color: lineColor,
            borderDash,
            borderDashOffset,
            tickWidth,
            tickColor,
            tickBorderDash,
            tickBorderDashOffset,
          });
        }
        me._ticksLength = ticksLength;
        me._borderValue = borderValue;
        return items;
      }
      _computeLabelItems(chartArea) {
        const me = this;
        const axis = me.axis;
        const options = me.options;
        const {position, ticks: optionTicks} = options;
        const isHorizontal = me.isHorizontal();
        const ticks = me.ticks;
        const {align, crossAlign, padding, mirror} = optionTicks;
        const tl = getTickMarkLength(options.grid);
        const tickAndPadding = tl + padding;
        const hTickAndPadding = mirror ? -padding : tickAndPadding;
        const rotation = -toRadians(me.labelRotation);
        const items = [];
        let i, ilen, tick, label, x, y, textAlign, pixel, font, lineHeight, lineCount, textOffset;
        let textBaseline = 'middle';
        if (position === 'top') {
          y = me.bottom - hTickAndPadding;
          textAlign = me._getXAxisLabelAlignment();
        } else if (position === 'bottom') {
          y = me.top + hTickAndPadding;
          textAlign = me._getXAxisLabelAlignment();
        } else if (position === 'left') {
          const ret = me._getYAxisLabelAlignment(tl);
          textAlign = ret.textAlign;
          x = ret.x;
        } else if (position === 'right') {
          const ret = me._getYAxisLabelAlignment(tl);
          textAlign = ret.textAlign;
          x = ret.x;
        } else if (axis === 'x') {
          if (position === 'center') {
            y = ((chartArea.top + chartArea.bottom) / 2) + tickAndPadding;
          } else if (isObject(position)) {
            const positionAxisID = Object.keys(position)[0];
            const value = position[positionAxisID];
            y = me.chart.scales[positionAxisID].getPixelForValue(value) + tickAndPadding;
          }
          textAlign = me._getXAxisLabelAlignment();
        } else if (axis === 'y') {
          if (position === 'center') {
            x = ((chartArea.left + chartArea.right) / 2) - tickAndPadding;
          } else if (isObject(position)) {
            const positionAxisID = Object.keys(position)[0];
            const value = position[positionAxisID];
            x = me.chart.scales[positionAxisID].getPixelForValue(value);
          }
          textAlign = me._getYAxisLabelAlignment(tl).textAlign;
        }
        if (axis === 'y') {
          if (align === 'start') {
            textBaseline = 'top';
          } else if (align === 'end') {
            textBaseline = 'bottom';
          }
        }
        const labelSizes = me._getLabelSizes();
        for (i = 0, ilen = ticks.length; i < ilen; ++i) {
          tick = ticks[i];
          label = tick.label;
          const optsAtIndex = optionTicks.setContext(me.getContext(i));
          pixel = me.getPixelForTick(i) + optionTicks.labelOffset;
          font = me._resolveTickFontOptions(i);
          lineHeight = font.lineHeight;
          lineCount = isArray(label) ? label.length : 1;
          const halfCount = lineCount / 2;
          const color = optsAtIndex.color;
          const strokeColor = optsAtIndex.textStrokeColor;
          const strokeWidth = optsAtIndex.textStrokeWidth;
          if (isHorizontal) {
            x = pixel;
            if (position === 'top') {
              if (crossAlign === 'near' || rotation !== 0) {
                textOffset = -lineCount * lineHeight + lineHeight / 2;
              } else if (crossAlign === 'center') {
                textOffset = -labelSizes.highest.height / 2 - halfCount * lineHeight + lineHeight;
              } else {
                textOffset = -labelSizes.highest.height + lineHeight / 2;
              }
            } else {
              if (crossAlign === 'near' || rotation !== 0) {
                textOffset = lineHeight / 2;
              } else if (crossAlign === 'center') {
                textOffset = labelSizes.highest.height / 2 - halfCount * lineHeight;
              } else {
                textOffset = labelSizes.highest.height - lineCount * lineHeight;
              }
            }
            if (mirror) {
              textOffset *= -1;
            }
          } else {
            y = pixel;
            textOffset = (1 - lineCount) * lineHeight / 2;
          }
          let backdrop;
          if (optsAtIndex.showLabelBackdrop) {
            const labelPadding = toPadding(optsAtIndex.backdropPadding);
            const height = labelSizes.heights[i];
            const width = labelSizes.widths[i];
            let top = y + textOffset - labelPadding.top;
            let left = x - labelPadding.left;
            switch (textBaseline) {
            case 'middle':
              top -= height / 2;
              break;
            case 'bottom':
              top -= height;
              break;
            }
            switch (textAlign) {
            case 'center':
              left -= width / 2;
              break;
            case 'right':
              left -= width;
              break;
            }
            backdrop = {
              left,
              top,
              width: width + labelPadding.width,
              height: height + labelPadding.height,
              color: optsAtIndex.backdropColor,
            };
          }
          items.push({
            rotation,
            label,
            font,
            color,
            strokeColor,
            strokeWidth,
            textOffset,
            textAlign,
            textBaseline,
            translation: [x, y],
            backdrop,
          });
        }
        return items;
      }
      _getXAxisLabelAlignment() {
        const me = this;
        const {position, ticks} = me.options;
        const rotation = -toRadians(me.labelRotation);
        if (rotation) {
          return position === 'top' ? 'left' : 'right';
        }
        let align = 'center';
        if (ticks.align === 'start') {
          align = 'left';
        } else if (ticks.align === 'end') {
          align = 'right';
        }
        return align;
      }
      _getYAxisLabelAlignment(tl) {
        const me = this;
        const {position, ticks: {crossAlign, mirror, padding}} = me.options;
        const labelSizes = me._getLabelSizes();
        const tickAndPadding = tl + padding;
        const widest = labelSizes.widest.width;
        let textAlign;
        let x;
        if (position === 'left') {
          if (mirror) {
            x = me.right + padding;
            if (crossAlign === 'near') {
              textAlign = 'left';
            } else if (crossAlign === 'center') {
              textAlign = 'center';
              x += (widest / 2);
            } else {
              textAlign = 'right';
              x += widest;
            }
          } else {
            x = me.right - tickAndPadding;
            if (crossAlign === 'near') {
              textAlign = 'right';
            } else if (crossAlign === 'center') {
              textAlign = 'center';
              x -= (widest / 2);
            } else {
              textAlign = 'left';
              x = me.left;
            }
          }
        } else if (position === 'right') {
          if (mirror) {
            x = me.left + padding;
            if (crossAlign === 'near') {
              textAlign = 'right';
            } else if (crossAlign === 'center') {
              textAlign = 'center';
              x -= (widest / 2);
            } else {
              textAlign = 'left';
              x -= widest;
            }
          } else {
            x = me.left + tickAndPadding;
            if (crossAlign === 'near') {
              textAlign = 'left';
            } else if (crossAlign === 'center') {
              textAlign = 'center';
              x += widest / 2;
            } else {
              textAlign = 'right';
              x = me.right;
            }
          }
        } else {
          textAlign = 'right';
        }
        return {textAlign, x};
      }
      _computeLabelArea() {
        const me = this;
        if (me.options.ticks.mirror) {
          return;
        }
        const chart = me.chart;
        const position = me.options.position;
        if (position === 'left' || position === 'right') {
          return {top: 0, left: me.left, bottom: chart.height, right: me.right};
        } if (position === 'top' || position === 'bottom') {
          return {top: me.top, left: 0, bottom: me.bottom, right: chart.width};
        }
      }
      drawBackground() {
        const {ctx, options: {backgroundColor}, left, top, width, height} = this;
        if (backgroundColor) {
          ctx.save();
          ctx.fillStyle = backgroundColor;
          ctx.fillRect(left, top, width, height);
          ctx.restore();
        }
      }
      getLineWidthForValue(value) {
        const me = this;
        const grid = me.options.grid;
        if (!me._isVisible() || !grid.display) {
          return 0;
        }
        const ticks = me.ticks;
        const index = ticks.findIndex(t => t.value === value);
        if (index >= 0) {
          const opts = grid.setContext(me.getContext(index));
          return opts.lineWidth;
        }
        return 0;
      }
      drawGrid(chartArea) {
        const me = this;
        const grid = me.options.grid;
        const ctx = me.ctx;
        const items = me._gridLineItems || (me._gridLineItems = me._computeGridLineItems(chartArea));
        let i, ilen;
        const drawLine = (p1, p2, style) => {
          if (!style.width || !style.color) {
            return;
          }
          ctx.save();
          ctx.lineWidth = style.width;
          ctx.strokeStyle = style.color;
          ctx.setLineDash(style.borderDash || []);
          ctx.lineDashOffset = style.borderDashOffset;
          ctx.beginPath();
          ctx.moveTo(p1.x, p1.y);
          ctx.lineTo(p2.x, p2.y);
          ctx.stroke();
          ctx.restore();
        };
        if (grid.display) {
          for (i = 0, ilen = items.length; i < ilen; ++i) {
            const item = items[i];
            if (grid.drawOnChartArea) {
              drawLine(
                {x: item.x1, y: item.y1},
                {x: item.x2, y: item.y2},
                item
              );
            }
            if (grid.drawTicks) {
              drawLine(
                {x: item.tx1, y: item.ty1},
                {x: item.tx2, y: item.ty2},
                {
                  color: item.tickColor,
                  width: item.tickWidth,
                  borderDash: item.tickBorderDash,
                  borderDashOffset: item.tickBorderDashOffset
                }
              );
            }
          }
        }
      }
      drawBorder() {
        const me = this;
        const {chart, ctx, options: {grid}} = me;
        const borderOpts = grid.setContext(me.getContext());
        const axisWidth = grid.drawBorder ? borderOpts.borderWidth : 0;
        if (!axisWidth) {
          return;
        }
        const lastLineWidth = grid.setContext(me.getContext(0)).lineWidth;
        const borderValue = me._borderValue;
        let x1, x2, y1, y2;
        if (me.isHorizontal()) {
          x1 = _alignPixel(chart, me.left, axisWidth) - axisWidth / 2;
          x2 = _alignPixel(chart, me.right, lastLineWidth) + lastLineWidth / 2;
          y1 = y2 = borderValue;
        } else {
          y1 = _alignPixel(chart, me.top, axisWidth) - axisWidth / 2;
          y2 = _alignPixel(chart, me.bottom, lastLineWidth) + lastLineWidth / 2;
          x1 = x2 = borderValue;
        }
        ctx.save();
        ctx.lineWidth = borderOpts.borderWidth;
        ctx.strokeStyle = borderOpts.borderColor;
        ctx.beginPath();
        ctx.moveTo(x1, y1);
        ctx.lineTo(x2, y2);
        ctx.stroke();
        ctx.restore();
      }
      drawLabels(chartArea) {
        const me = this;
        const optionTicks = me.options.ticks;
        if (!optionTicks.display) {
          return;
        }
        const ctx = me.ctx;
        const area = me._computeLabelArea();
        if (area) {
          clipArea(ctx, area);
        }
        const items = me._labelItems || (me._labelItems = me._computeLabelItems(chartArea));
        let i, ilen;
        for (i = 0, ilen = items.length; i < ilen; ++i) {
          const item = items[i];
          const tickFont = item.font;
          const label = item.label;
          if (item.backdrop) {
            ctx.fillStyle = item.backdrop.color;
            ctx.fillRect(item.backdrop.left, item.backdrop.top, item.backdrop.width, item.backdrop.height);
          }
          let y = item.textOffset;
          renderText(ctx, label, 0, y, tickFont, item);
        }
        if (area) {
          unclipArea(ctx);
        }
      }
      drawTitle() {
        const {ctx, options: {position, title, reverse}} = this;
        if (!title.display) {
          return;
        }
        const font = toFont(title.font);
        const padding = toPadding(title.padding);
        const align = title.align;
        let offset = font.lineHeight / 2;
        if (position === 'bottom' || position === 'center' || isObject(position)) {
          offset += padding.bottom;
          if (isArray(title.text)) {
            offset += font.lineHeight * (title.text.length - 1);
          }
        } else {
          offset += padding.top;
        }
        const {titleX, titleY, maxWidth, rotation} = titleArgs(this, offset, position, align);
        renderText(ctx, title.text, 0, 0, font, {
          color: title.color,
          maxWidth,
          rotation,
          textAlign: titleAlign(align, position, reverse),
          textBaseline: 'middle',
          translation: [titleX, titleY],
        });
      }
      draw(chartArea) {
        const me = this;
        if (!me._isVisible()) {
          return;
        }
        me.drawBackground();
        me.drawGrid(chartArea);
        me.drawBorder();
        me.drawTitle();
        me.drawLabels(chartArea);
      }
      _layers() {
        const me = this;
        const opts = me.options;
        const tz = opts.ticks && opts.ticks.z || 0;
        const gz = valueOrDefault(opts.grid && opts.grid.z, -1);
        if (!me._isVisible() || me.draw !== Scale.prototype.draw) {
          return [{
            z: tz,
            draw(chartArea) {
              me.draw(chartArea);
            }
          }];
        }
        return [{
          z: gz,
          draw(chartArea) {
            me.drawBackground();
            me.drawGrid(chartArea);
            me.drawTitle();
          }
        }, {
          z: gz + 1,
          draw() {
            me.drawBorder();
          }
        }, {
          z: tz,
          draw(chartArea) {
            me.drawLabels(chartArea);
          }
        }];
      }
      getMatchingVisibleMetas(type) {
        const me = this;
        const metas = me.chart.getSortedVisibleDatasetMetas();
        const axisID = me.axis + 'AxisID';
        const result = [];
        let i, ilen;
        for (i = 0, ilen = metas.length; i < ilen; ++i) {
          const meta = metas[i];
          if (meta[axisID] === me.id && (!type || meta.type === type)) {
            result.push(meta);
          }
        }
        return result;
      }
      _resolveTickFontOptions(index) {
        const opts = this.options.ticks.setContext(this.getContext(index));
        return toFont(opts.font);
      }
      _maxDigits() {
        const me = this;
        const fontSize = me._resolveTickFontOptions(0).lineHeight;
        return (me.isHorizontal() ? me.width : me.height) / fontSize;
      }
    }
    
    class TypedRegistry {
      constructor(type, scope, override) {
        this.type = type;
        this.scope = scope;
        this.override = override;
        this.items = Object.create(null);
      }
      isForType(type) {
        return Object.prototype.isPrototypeOf.call(this.type.prototype, type.prototype);
      }
      register(item) {
        const me = this;
        const proto = Object.getPrototypeOf(item);
        let parentScope;
        if (isIChartComponent(proto)) {
          parentScope = me.register(proto);
        }
        const items = me.items;
        const id = item.id;
        const scope = me.scope + '.' + id;
        if (!id) {
          throw new Error('class does not have id: ' + item);
        }
        if (id in items) {
          return scope;
        }
        items[id] = item;
        registerDefaults(item, scope, parentScope);
        if (me.override) {
          defaults.override(item.id, item.overrides);
        }
        return scope;
      }
      get(id) {
        return this.items[id];
      }
      unregister(item) {
        const items = this.items;
        const id = item.id;
        const scope = this.scope;
        if (id in items) {
          delete items[id];
        }
        if (scope && id in defaults[scope]) {
          delete defaults[scope][id];
          if (this.override) {
            delete overrides[id];
          }
        }
      }
    }
    function registerDefaults(item, scope, parentScope) {
      const itemDefaults = merge(Object.create(null), [
        parentScope ? defaults.get(parentScope) : {},
        defaults.get(scope),
        item.defaults
      ]);
      defaults.set(scope, itemDefaults);
      if (item.defaultRoutes) {
        routeDefaults(scope, item.defaultRoutes);
      }
      if (item.descriptors) {
        defaults.describe(scope, item.descriptors);
      }
    }
    function routeDefaults(scope, routes) {
      Object.keys(routes).forEach(property => {
        const propertyParts = property.split('.');
        const sourceName = propertyParts.pop();
        const sourceScope = [scope].concat(propertyParts).join('.');
        const parts = routes[property].split('.');
        const targetName = parts.pop();
        const targetScope = parts.join('.');
        defaults.route(sourceScope, sourceName, targetScope, targetName);
      });
    }
    function isIChartComponent(proto) {
      return 'id' in proto && 'defaults' in proto;
    }
    
    class Registry {
      constructor() {
        this.controllers = new TypedRegistry(DatasetController, 'datasets', true);
        this.elements = new TypedRegistry(Element, 'elements');
        this.plugins = new TypedRegistry(Object, 'plugins');
        this.scales = new TypedRegistry(Scale, 'scales');
        this._typedRegistries = [this.controllers, this.scales, this.elements];
      }
      add(...args) {
        this._each('register', args);
      }
      remove(...args) {
        this._each('unregister', args);
      }
      addControllers(...args) {
        this._each('register', args, this.controllers);
      }
      addElements(...args) {
        this._each('register', args, this.elements);
      }
      addPlugins(...args) {
        this._each('register', args, this.plugins);
      }
      addScales(...args) {
        this._each('register', args, this.scales);
      }
      getController(id) {
        return this._get(id, this.controllers, 'controller');
      }
      getElement(id) {
        return this._get(id, this.elements, 'element');
      }
      getPlugin(id) {
        return this._get(id, this.plugins, 'plugin');
      }
      getScale(id) {
        return this._get(id, this.scales, 'scale');
      }
      removeControllers(...args) {
        this._each('unregister', args, this.controllers);
      }
      removeElements(...args) {
        this._each('unregister', args, this.elements);
      }
      removePlugins(...args) {
        this._each('unregister', args, this.plugins);
      }
      removeScales(...args) {
        this._each('unregister', args, this.scales);
      }
      _each(method, args, typedRegistry) {
        const me = this;
        [...args].forEach(arg => {
          const reg = typedRegistry || me._getRegistryForType(arg);
          if (typedRegistry || reg.isForType(arg) || (reg === me.plugins && arg.id)) {
            me._exec(method, reg, arg);
          } else {
            each(arg, item => {
              const itemReg = typedRegistry || me._getRegistryForType(item);
              me._exec(method, itemReg, item);
            });
          }
        });
      }
      _exec(method, registry, component) {
        const camelMethod = _capitalize(method);
        callback(component['before' + camelMethod], [], component);
        registry[method](component);
        callback(component['after' + camelMethod], [], component);
      }
      _getRegistryForType(type) {
        for (let i = 0; i < this._typedRegistries.length; i++) {
          const reg = this._typedRegistries[i];
          if (reg.isForType(type)) {
            return reg;
          }
        }
        return this.plugins;
      }
      _get(id, typedRegistry, type) {
        const item = typedRegistry.get(id);
        if (item === undefined) {
          throw new Error('"' + id + '" is not a registered ' + type + '.');
        }
        return item;
      }
    }
    var registry = new Registry();
    
    class PluginService {
      constructor() {
        this._init = [];
      }
      notify(chart, hook, args, filter) {
        const me = this;
        if (hook === 'beforeInit') {
          me._init = me._createDescriptors(chart, true);
          me._notify(me._init, chart, 'install');
        }
        const descriptors = filter ? me._descriptors(chart).filter(filter) : me._descriptors(chart);
        const result = me._notify(descriptors, chart, hook, args);
        if (hook === 'destroy') {
          me._notify(descriptors, chart, 'stop');
          me._notify(me._init, chart, 'uninstall');
        }
        return result;
      }
      _notify(descriptors, chart, hook, args) {
        args = args || {};
        for (const descriptor of descriptors) {
          const plugin = descriptor.plugin;
          const method = plugin[hook];
          const params = [chart, args, descriptor.options];
          if (callback(method, params, plugin) === false && args.cancelable) {
            return false;
          }
        }
        return true;
      }
      invalidate() {
        if (!isNullOrUndef(this._cache)) {
          this._oldCache = this._cache;
          this._cache = undefined;
        }
      }
      _descriptors(chart) {
        if (this._cache) {
          return this._cache;
        }
        const descriptors = this._cache = this._createDescriptors(chart);
        this._notifyStateChanges(chart);
        return descriptors;
      }
      _createDescriptors(chart, all) {
        const config = chart && chart.config;
        const options = valueOrDefault(config.options && config.options.plugins, {});
        const plugins = allPlugins(config);
        return options === false && !all ? [] : createDescriptors(chart, plugins, options, all);
      }
      _notifyStateChanges(chart) {
        const previousDescriptors = this._oldCache || [];
        const descriptors = this._cache;
        const diff = (a, b) => a.filter(x => !b.some(y => x.plugin.id === y.plugin.id));
        this._notify(diff(previousDescriptors, descriptors), chart, 'stop');
        this._notify(diff(descriptors, previousDescriptors), chart, 'start');
      }
    }
    function allPlugins(config) {
      const plugins = [];
      const keys = Object.keys(registry.plugins.items);
      for (let i = 0; i < keys.length; i++) {
        plugins.push(registry.getPlugin(keys[i]));
      }
      const local = config.plugins || [];
      for (let i = 0; i < local.length; i++) {
        const plugin = local[i];
        if (plugins.indexOf(plugin) === -1) {
          plugins.push(plugin);
        }
      }
      return plugins;
    }
    function getOpts(options, all) {
      if (!all && options === false) {
        return null;
      }
      if (options === true) {
        return {};
      }
      return options;
    }
    function createDescriptors(chart, plugins, options, all) {
      const result = [];
      const context = chart.getContext();
      for (let i = 0; i < plugins.length; i++) {
        const plugin = plugins[i];
        const id = plugin.id;
        const opts = getOpts(options[id], all);
        if (opts === null) {
          continue;
        }
        result.push({
          plugin,
          options: pluginOpts(chart.config, plugin, opts, context)
        });
      }
      return result;
    }
    function pluginOpts(config, plugin, opts, context) {
      const keys = config.pluginScopeKeys(plugin);
      const scopes = config.getOptionScopes(opts, keys);
      return config.createResolver(scopes, context, [''], {scriptable: false, indexable: false, allKeys: true});
    }
    
    function getIndexAxis(type, options) {
      const datasetDefaults = defaults.datasets[type] || {};
      const datasetOptions = (options.datasets || {})[type] || {};
      return datasetOptions.indexAxis || options.indexAxis || datasetDefaults.indexAxis || 'x';
    }
    function getAxisFromDefaultScaleID(id, indexAxis) {
      let axis = id;
      if (id === '_index_') {
        axis = indexAxis;
      } else if (id === '_value_') {
        axis = indexAxis === 'x' ? 'y' : 'x';
      }
      return axis;
    }
    function getDefaultScaleIDFromAxis(axis, indexAxis) {
      return axis === indexAxis ? '_index_' : '_value_';
    }
    function axisFromPosition(position) {
      if (position === 'top' || position === 'bottom') {
        return 'x';
      }
      if (position === 'left' || position === 'right') {
        return 'y';
      }
    }
    function determineAxis(id, scaleOptions) {
      if (id === 'x' || id === 'y') {
        return id;
      }
      return scaleOptions.axis || axisFromPosition(scaleOptions.position) || id.charAt(0).toLowerCase();
    }
    function mergeScaleConfig(config, options) {
      const chartDefaults = overrides[config.type] || {scales: {}};
      const configScales = options.scales || {};
      const chartIndexAxis = getIndexAxis(config.type, options);
      const firstIDs = Object.create(null);
      const scales = Object.create(null);
      Object.keys(configScales).forEach(id => {
        const scaleConf = configScales[id];
        const axis = determineAxis(id, scaleConf);
        const defaultId = getDefaultScaleIDFromAxis(axis, chartIndexAxis);
        const defaultScaleOptions = chartDefaults.scales || {};
        firstIDs[axis] = firstIDs[axis] || id;
        scales[id] = mergeIf(Object.create(null), [{axis}, scaleConf, defaultScaleOptions[axis], defaultScaleOptions[defaultId]]);
      });
      config.data.datasets.forEach(dataset => {
        const type = dataset.type || config.type;
        const indexAxis = dataset.indexAxis || getIndexAxis(type, options);
        const datasetDefaults = overrides[type] || {};
        const defaultScaleOptions = datasetDefaults.scales || {};
        Object.keys(defaultScaleOptions).forEach(defaultID => {
          const axis = getAxisFromDefaultScaleID(defaultID, indexAxis);
          const id = dataset[axis + 'AxisID'] || firstIDs[axis] || axis;
          scales[id] = scales[id] || Object.create(null);
          mergeIf(scales[id], [{axis}, configScales[id], defaultScaleOptions[defaultID]]);
        });
      });
      Object.keys(scales).forEach(key => {
        const scale = scales[key];
        mergeIf(scale, [defaults.scales[scale.type], defaults.scale]);
      });
      return scales;
    }
    function initOptions(config) {
      const options = config.options || (config.options = {});
      options.plugins = valueOrDefault(options.plugins, {});
      options.scales = mergeScaleConfig(config, options);
    }
    function initData(data) {
      data = data || {};
      data.datasets = data.datasets || [];
      data.labels = data.labels || [];
      return data;
    }
    function initConfig(config) {
      config = config || {};
      config.data = initData(config.data);
      initOptions(config);
      return config;
    }
    const keyCache = new Map();
    const keysCached = new Set();
    function cachedKeys(cacheKey, generate) {
      let keys = keyCache.get(cacheKey);
      if (!keys) {
        keys = generate();
        keyCache.set(cacheKey, keys);
        keysCached.add(keys);
      }
      return keys;
    }
    const addIfFound = (set, obj, key) => {
      const opts = resolveObjectKey(obj, key);
      if (opts !== undefined) {
        set.add(opts);
      }
    };
    class Config {
      constructor(config) {
        this._config = initConfig(config);
        this._scopeCache = new Map();
        this._resolverCache = new Map();
      }
      get platform() {
        return this._config.platform;
      }
      get type() {
        return this._config.type;
      }
      set type(type) {
        this._config.type = type;
      }
      get data() {
        return this._config.data;
      }
      set data(data) {
        this._config.data = initData(data);
      }
      get options() {
        return this._config.options;
      }
      set options(options) {
        this._config.options = options;
      }
      get plugins() {
        return this._config.plugins;
      }
      update() {
        const config = this._config;
        this.clearCache();
        initOptions(config);
      }
      clearCache() {
        this._scopeCache.clear();
        this._resolverCache.clear();
      }
      datasetScopeKeys(datasetType) {
        return cachedKeys(datasetType,
          () => [[
            `datasets.${datasetType}`,
            ''
          ]]);
      }
      datasetAnimationScopeKeys(datasetType, transition) {
        return cachedKeys(`${datasetType}.transition.${transition}`,
          () => [
            [
              `datasets.${datasetType}.transitions.${transition}`,
              `transitions.${transition}`,
            ],
            [
              `datasets.${datasetType}`,
              ''
            ]
          ]);
      }
      datasetElementScopeKeys(datasetType, elementType) {
        return cachedKeys(`${datasetType}-${elementType}`,
          () => [[
            `datasets.${datasetType}.elements.${elementType}`,
            `datasets.${datasetType}`,
            `elements.${elementType}`,
            ''
          ]]);
      }
      pluginScopeKeys(plugin) {
        const id = plugin.id;
        const type = this.type;
        return cachedKeys(`${type}-plugin-${id}`,
          () => [[
            `plugins.${id}`,
            ...plugin.additionalOptionScopes || [],
          ]]);
      }
      _cachedScopes(mainScope, resetCache) {
        const _scopeCache = this._scopeCache;
        let cache = _scopeCache.get(mainScope);
        if (!cache || resetCache) {
          cache = new Map();
          _scopeCache.set(mainScope, cache);
        }
        return cache;
      }
      getOptionScopes(mainScope, keyLists, resetCache) {
        const {options, type} = this;
        const cache = this._cachedScopes(mainScope, resetCache);
        const cached = cache.get(keyLists);
        if (cached) {
          return cached;
        }
        const scopes = new Set();
        keyLists.forEach(keys => {
          if (mainScope) {
            scopes.add(mainScope);
            keys.forEach(key => addIfFound(scopes, mainScope, key));
          }
          keys.forEach(key => addIfFound(scopes, options, key));
          keys.forEach(key => addIfFound(scopes, overrides[type] || {}, key));
          keys.forEach(key => addIfFound(scopes, defaults, key));
          keys.forEach(key => addIfFound(scopes, descriptors, key));
        });
        const array = Array.from(scopes);
        if (array.length === 0) {
          array.push(Object.create(null));
        }
        if (keysCached.has(keyLists)) {
          cache.set(keyLists, array);
        }
        return array;
      }
      chartOptionScopes() {
        const {options, type} = this;
        return [
          options,
          overrides[type] || {},
          defaults.datasets[type] || {},
          {type},
          defaults,
          descriptors
        ];
      }
      resolveNamedOptions(scopes, names, context, prefixes = ['']) {
        const result = {$shared: true};
        const {resolver, subPrefixes} = getResolver(this._resolverCache, scopes, prefixes);
        let options = resolver;
        if (needContext(resolver, names)) {
          result.$shared = false;
          context = isFunction(context) ? context() : context;
          const subResolver = this.createResolver(scopes, context, subPrefixes);
          options = _attachContext(resolver, context, subResolver);
        }
        for (const prop of names) {
          result[prop] = options[prop];
        }
        return result;
      }
      createResolver(scopes, context, prefixes = [''], descriptorDefaults) {
        const {resolver} = getResolver(this._resolverCache, scopes, prefixes);
        return isObject(context)
          ? _attachContext(resolver, context, undefined, descriptorDefaults)
          : resolver;
      }
    }
    function getResolver(resolverCache, scopes, prefixes) {
      let cache = resolverCache.get(scopes);
      if (!cache) {
        cache = new Map();
        resolverCache.set(scopes, cache);
      }
      const cacheKey = prefixes.join();
      let cached = cache.get(cacheKey);
      if (!cached) {
        const resolver = _createResolver(scopes, prefixes);
        cached = {
          resolver,
          subPrefixes: prefixes.filter(p => !p.toLowerCase().includes('hover'))
        };
        cache.set(cacheKey, cached);
      }
      return cached;
    }
    function needContext(proxy, names) {
      const {isScriptable, isIndexable} = _descriptors(proxy);
      for (const prop of names) {
        if ((isScriptable(prop) && isFunction(proxy[prop]))
          || (isIndexable(prop) && isArray(proxy[prop]))) {
          return true;
        }
      }
      return false;
    }
    
    var version = "3.5.1";
    
    const KNOWN_POSITIONS = ['top', 'bottom', 'left', 'right', 'chartArea'];
    function positionIsHorizontal(position, axis) {
      return position === 'top' || position === 'bottom' || (KNOWN_POSITIONS.indexOf(position) === -1 && axis === 'x');
    }
    function compare2Level(l1, l2) {
      return function(a, b) {
        return a[l1] === b[l1]
          ? a[l2] - b[l2]
          : a[l1] - b[l1];
      };
    }
    function onAnimationsComplete(context) {
      const chart = context.chart;
      const animationOptions = chart.options.animation;
      chart.notifyPlugins('afterRender');
      callback(animationOptions && animationOptions.onComplete, [context], chart);
    }
    function onAnimationProgress(context) {
      const chart = context.chart;
      const animationOptions = chart.options.animation;
      callback(animationOptions && animationOptions.onProgress, [context], chart);
    }
    function getCanvas(item) {
      if (_isDomSupported() && typeof item === 'string') {
        item = document.getElementById(item);
      } else if (item && item.length) {
        item = item[0];
      }
      if (item && item.canvas) {
        item = item.canvas;
      }
      return item;
    }
    const instances = {};
    const getChart = (key) => {
      const canvas = getCanvas(key);
      return Object.values(instances).filter((c) => c.canvas === canvas).pop();
    };
    class Chart {
      constructor(item, userConfig) {
        const me = this;
        const config = this.config = new Config(userConfig);
        const initialCanvas = getCanvas(item);
        const existingChart = getChart(initialCanvas);
        if (existingChart) {
          throw new Error(
            'Canvas is already in use. Chart with ID \'' + existingChart.id + '\'' +
                    ' must be destroyed before the canvas can be reused.'
          );
        }
        const options = config.createResolver(config.chartOptionScopes(), me.getContext());
        this.platform = new (config.platform || _detectPlatform(initialCanvas))();
        const context = me.platform.acquireContext(initialCanvas, options.aspectRatio);
        const canvas = context && context.canvas;
        const height = canvas && canvas.height;
        const width = canvas && canvas.width;
        this.id = uid();
        this.ctx = context;
        this.canvas = canvas;
        this.width = width;
        this.height = height;
        this._options = options;
        this._aspectRatio = this.aspectRatio;
        this._layers = [];
        this._metasets = [];
        this._stacks = undefined;
        this.boxes = [];
        this.currentDevicePixelRatio = undefined;
        this.chartArea = undefined;
        this._active = [];
        this._lastEvent = undefined;
        this._listeners = {};
        this._responsiveListeners = undefined;
        this._sortedMetasets = [];
        this.scales = {};
        this._plugins = new PluginService();
        this.$proxies = {};
        this._hiddenIndices = {};
        this.attached = false;
        this._animationsDisabled = undefined;
        this.$context = undefined;
        this._doResize = debounce(() => this.update('resize'), options.resizeDelay || 0);
        instances[me.id] = me;
        if (!context || !canvas) {
          console.error("Failed to create chart: can't acquire context from the given item");
          return;
        }
        animator.listen(me, 'complete', onAnimationsComplete);
        animator.listen(me, 'progress', onAnimationProgress);
        me._initialize();
        if (me.attached) {
          me.update();
        }
      }
      get aspectRatio() {
        const {options: {aspectRatio, maintainAspectRatio}, width, height, _aspectRatio} = this;
        if (!isNullOrUndef(aspectRatio)) {
          return aspectRatio;
        }
        if (maintainAspectRatio && _aspectRatio) {
          return _aspectRatio;
        }
        return height ? width / height : null;
      }
      get data() {
        return this.config.data;
      }
      set data(data) {
        this.config.data = data;
      }
      get options() {
        return this._options;
      }
      set options(options) {
        this.config.options = options;
      }
      _initialize() {
        const me = this;
        me.notifyPlugins('beforeInit');
        if (me.options.responsive) {
          me.resize();
        } else {
          retinaScale(me, me.options.devicePixelRatio);
        }
        me.bindEvents();
        me.notifyPlugins('afterInit');
        return me;
      }
      clear() {
        clearCanvas(this.canvas, this.ctx);
        return this;
      }
      stop() {
        animator.stop(this);
        return this;
      }
      resize(width, height) {
        if (!animator.running(this)) {
          this._resize(width, height);
        } else {
          this._resizeBeforeDraw = {width, height};
        }
      }
      _resize(width, height) {
        const me = this;
        const options = me.options;
        const canvas = me.canvas;
        const aspectRatio = options.maintainAspectRatio && me.aspectRatio;
        const newSize = me.platform.getMaximumSize(canvas, width, height, aspectRatio);
        const newRatio = options.devicePixelRatio || me.platform.getDevicePixelRatio();
        me.width = newSize.width;
        me.height = newSize.height;
        me._aspectRatio = me.aspectRatio;
        if (!retinaScale(me, newRatio, true)) {
          return;
        }
        me.notifyPlugins('resize', {size: newSize});
        callback(options.onResize, [me, newSize], me);
        if (me.attached) {
          if (me._doResize()) {
            me.render();
          }
        }
      }
      ensureScalesHaveIDs() {
        const options = this.options;
        const scalesOptions = options.scales || {};
        each(scalesOptions, (axisOptions, axisID) => {
          axisOptions.id = axisID;
        });
      }
      buildOrUpdateScales() {
        const me = this;
        const options = me.options;
        const scaleOpts = options.scales;
        const scales = me.scales;
        const updated = Object.keys(scales).reduce((obj, id) => {
          obj[id] = false;
          return obj;
        }, {});
        let items = [];
        if (scaleOpts) {
          items = items.concat(
            Object.keys(scaleOpts).map((id) => {
              const scaleOptions = scaleOpts[id];
              const axis = determineAxis(id, scaleOptions);
              const isRadial = axis === 'r';
              const isHorizontal = axis === 'x';
              return {
                options: scaleOptions,
                dposition: isRadial ? 'chartArea' : isHorizontal ? 'bottom' : 'left',
                dtype: isRadial ? 'radialLinear' : isHorizontal ? 'category' : 'linear'
              };
            })
          );
        }
        each(items, (item) => {
          const scaleOptions = item.options;
          const id = scaleOptions.id;
          const axis = determineAxis(id, scaleOptions);
          const scaleType = valueOrDefault(scaleOptions.type, item.dtype);
          if (scaleOptions.position === undefined || positionIsHorizontal(scaleOptions.position, axis) !== positionIsHorizontal(item.dposition)) {
            scaleOptions.position = item.dposition;
          }
          updated[id] = true;
          let scale = null;
          if (id in scales && scales[id].type === scaleType) {
            scale = scales[id];
          } else {
            const scaleClass = registry.getScale(scaleType);
            scale = new scaleClass({
              id,
              type: scaleType,
              ctx: me.ctx,
              chart: me
            });
            scales[scale.id] = scale;
          }
          scale.init(scaleOptions, options);
        });
        each(updated, (hasUpdated, id) => {
          if (!hasUpdated) {
            delete scales[id];
          }
        });
        each(scales, (scale) => {
          layouts.configure(me, scale, scale.options);
          layouts.addBox(me, scale);
        });
      }
      _updateMetasets() {
        const me = this;
        const metasets = me._metasets;
        const numData = me.data.datasets.length;
        const numMeta = metasets.length;
        metasets.sort((a, b) => a.index - b.index);
        if (numMeta > numData) {
          for (let i = numData; i < numMeta; ++i) {
            me._destroyDatasetMeta(i);
          }
          metasets.splice(numData, numMeta - numData);
        }
        me._sortedMetasets = metasets.slice(0).sort(compare2Level('order', 'index'));
      }
      _removeUnreferencedMetasets() {
        const me = this;
        const {_metasets: metasets, data: {datasets}} = me;
        if (metasets.length > datasets.length) {
          delete me._stacks;
        }
        metasets.forEach((meta, index) => {
          if (datasets.filter(x => x === meta._dataset).length === 0) {
            me._destroyDatasetMeta(index);
          }
        });
      }
      buildOrUpdateControllers() {
        const me = this;
        const newControllers = [];
        const datasets = me.data.datasets;
        let i, ilen;
        me._removeUnreferencedMetasets();
        for (i = 0, ilen = datasets.length; i < ilen; i++) {
          const dataset = datasets[i];
          let meta = me.getDatasetMeta(i);
          const type = dataset.type || me.config.type;
          if (meta.type && meta.type !== type) {
            me._destroyDatasetMeta(i);
            meta = me.getDatasetMeta(i);
          }
          meta.type = type;
          meta.indexAxis = dataset.indexAxis || getIndexAxis(type, me.options);
          meta.order = dataset.order || 0;
          meta.index = i;
          meta.label = '' + dataset.label;
          meta.visible = me.isDatasetVisible(i);
          if (meta.controller) {
            meta.controller.updateIndex(i);
            meta.controller.linkScales();
          } else {
            const ControllerClass = registry.getController(type);
            const {datasetElementType, dataElementType} = defaults.datasets[type];
            Object.assign(ControllerClass.prototype, {
              dataElementType: registry.getElement(dataElementType),
              datasetElementType: datasetElementType && registry.getElement(datasetElementType)
            });
            meta.controller = new ControllerClass(me, i);
            newControllers.push(meta.controller);
          }
        }
        me._updateMetasets();
        return newControllers;
      }
      _resetElements() {
        const me = this;
        each(me.data.datasets, (dataset, datasetIndex) => {
          me.getDatasetMeta(datasetIndex).controller.reset();
        }, me);
      }
      reset() {
        this._resetElements();
        this.notifyPlugins('reset');
      }
      update(mode) {
        const me = this;
        const config = me.config;
        config.update();
        me._options = config.createResolver(config.chartOptionScopes(), me.getContext());
        each(me.scales, (scale) => {
          layouts.removeBox(me, scale);
        });
        const animsDisabled = me._animationsDisabled = !me.options.animation;
        me.ensureScalesHaveIDs();
        me.buildOrUpdateScales();
        const existingEvents = new Set(Object.keys(me._listeners));
        const newEvents = new Set(me.options.events);
        if (!setsEqual(existingEvents, newEvents) || !!this._responsiveListeners !== me.options.responsive) {
          me.unbindEvents();
          me.bindEvents();
        }
        me._plugins.invalidate();
        if (me.notifyPlugins('beforeUpdate', {mode, cancelable: true}) === false) {
          return;
        }
        const newControllers = me.buildOrUpdateControllers();
        me.notifyPlugins('beforeElementsUpdate');
        let minPadding = 0;
        for (let i = 0, ilen = me.data.datasets.length; i < ilen; i++) {
          const {controller} = me.getDatasetMeta(i);
          const reset = !animsDisabled && newControllers.indexOf(controller) === -1;
          controller.buildOrUpdateElements(reset);
          minPadding = Math.max(+controller.getMaxOverflow(), minPadding);
        }
        me._minPadding = minPadding;
        me._updateLayout(minPadding);
        if (!animsDisabled) {
          each(newControllers, (controller) => {
            controller.reset();
          });
        }
        me._updateDatasets(mode);
        me.notifyPlugins('afterUpdate', {mode});
        me._layers.sort(compare2Level('z', '_idx'));
        if (me._lastEvent) {
          me._eventHandler(me._lastEvent, true);
        }
        me.render();
      }
      _updateLayout(minPadding) {
        const me = this;
        if (me.notifyPlugins('beforeLayout', {cancelable: true}) === false) {
          return;
        }
        layouts.update(me, me.width, me.height, minPadding);
        const area = me.chartArea;
        const noArea = area.width <= 0 || area.height <= 0;
        me._layers = [];
        each(me.boxes, (box) => {
          if (noArea && box.position === 'chartArea') {
            return;
          }
          if (box.configure) {
            box.configure();
          }
          me._layers.push(...box._layers());
        }, me);
        me._layers.forEach((item, index) => {
          item._idx = index;
        });
        me.notifyPlugins('afterLayout');
      }
      _updateDatasets(mode) {
        const me = this;
        const isFunction = typeof mode === 'function';
        if (me.notifyPlugins('beforeDatasetsUpdate', {mode, cancelable: true}) === false) {
          return;
        }
        for (let i = 0, ilen = me.data.datasets.length; i < ilen; ++i) {
          me._updateDataset(i, isFunction ? mode({datasetIndex: i}) : mode);
        }
        me.notifyPlugins('afterDatasetsUpdate', {mode});
      }
      _updateDataset(index, mode) {
        const me = this;
        const meta = me.getDatasetMeta(index);
        const args = {meta, index, mode, cancelable: true};
        if (me.notifyPlugins('beforeDatasetUpdate', args) === false) {
          return;
        }
        meta.controller._update(mode);
        args.cancelable = false;
        me.notifyPlugins('afterDatasetUpdate', args);
      }
      render() {
        const me = this;
        if (me.notifyPlugins('beforeRender', {cancelable: true}) === false) {
          return;
        }
        if (animator.has(me)) {
          if (me.attached && !animator.running(me)) {
            animator.start(me);
          }
        } else {
          me.draw();
          onAnimationsComplete({chart: me});
        }
      }
      draw() {
        const me = this;
        let i;
        if (me._resizeBeforeDraw) {
          const {width, height} = me._resizeBeforeDraw;
          me._resize(width, height);
          me._resizeBeforeDraw = null;
        }
        me.clear();
        if (me.width <= 0 || me.height <= 0) {
          return;
        }
        if (me.notifyPlugins('beforeDraw', {cancelable: true}) === false) {
          return;
        }
        const layers = me._layers;
        for (i = 0; i < layers.length && layers[i].z <= 0; ++i) {
          layers[i].draw(me.chartArea);
        }
        me._drawDatasets();
        for (; i < layers.length; ++i) {
          layers[i].draw(me.chartArea);
        }
        me.notifyPlugins('afterDraw');
      }
      _getSortedDatasetMetas(filterVisible) {
        const me = this;
        const metasets = me._sortedMetasets;
        const result = [];
        let i, ilen;
        for (i = 0, ilen = metasets.length; i < ilen; ++i) {
          const meta = metasets[i];
          if (!filterVisible || meta.visible) {
            result.push(meta);
          }
        }
        return result;
      }
      getSortedVisibleDatasetMetas() {
        return this._getSortedDatasetMetas(true);
      }
      _drawDatasets() {
        const me = this;
        if (me.notifyPlugins('beforeDatasetsDraw', {cancelable: true}) === false) {
          return;
        }
        const metasets = me.getSortedVisibleDatasetMetas();
        for (let i = metasets.length - 1; i >= 0; --i) {
          me._drawDataset(metasets[i]);
        }
        me.notifyPlugins('afterDatasetsDraw');
      }
      _drawDataset(meta) {
        const me = this;
        const ctx = me.ctx;
        const clip = meta._clip;
        const useClip = !clip.disabled;
        const area = me.chartArea;
        const args = {
          meta,
          index: meta.index,
          cancelable: true
        };
        if (me.notifyPlugins('beforeDatasetDraw', args) === false) {
          return;
        }
        if (useClip) {
          clipArea(ctx, {
            left: clip.left === false ? 0 : area.left - clip.left,
            right: clip.right === false ? me.width : area.right + clip.right,
            top: clip.top === false ? 0 : area.top - clip.top,
            bottom: clip.bottom === false ? me.height : area.bottom + clip.bottom
          });
        }
        meta.controller.draw();
        if (useClip) {
          unclipArea(ctx);
        }
        args.cancelable = false;
        me.notifyPlugins('afterDatasetDraw', args);
      }
      getElementsAtEventForMode(e, mode, options, useFinalPosition) {
        const method = Interaction.modes[mode];
        if (typeof method === 'function') {
          return method(this, e, options, useFinalPosition);
        }
        return [];
      }
      getDatasetMeta(datasetIndex) {
        const me = this;
        const dataset = me.data.datasets[datasetIndex];
        const metasets = me._metasets;
        let meta = metasets.filter(x => x && x._dataset === dataset).pop();
        if (!meta) {
          meta = {
            type: null,
            data: [],
            dataset: null,
            controller: null,
            hidden: null,
            xAxisID: null,
            yAxisID: null,
            order: dataset && dataset.order || 0,
            index: datasetIndex,
            _dataset: dataset,
            _parsed: [],
            _sorted: false
          };
          metasets.push(meta);
        }
        return meta;
      }
      getContext() {
        return this.$context || (this.$context = {chart: this, type: 'chart'});
      }
      getVisibleDatasetCount() {
        return this.getSortedVisibleDatasetMetas().length;
      }
      isDatasetVisible(datasetIndex) {
        const dataset = this.data.datasets[datasetIndex];
        if (!dataset) {
          return false;
        }
        const meta = this.getDatasetMeta(datasetIndex);
        return typeof meta.hidden === 'boolean' ? !meta.hidden : !dataset.hidden;
      }
      setDatasetVisibility(datasetIndex, visible) {
        const meta = this.getDatasetMeta(datasetIndex);
        meta.hidden = !visible;
      }
      toggleDataVisibility(index) {
        this._hiddenIndices[index] = !this._hiddenIndices[index];
      }
      getDataVisibility(index) {
        return !this._hiddenIndices[index];
      }
      _updateVisibility(datasetIndex, dataIndex, visible) {
        const me = this;
        const mode = visible ? 'show' : 'hide';
        const meta = me.getDatasetMeta(datasetIndex);
        const anims = meta.controller._resolveAnimations(undefined, mode);
        if (defined(dataIndex)) {
          meta.data[dataIndex].hidden = !visible;
          me.update();
        } else {
          me.setDatasetVisibility(datasetIndex, visible);
          anims.update(meta, {visible});
          me.update((ctx) => ctx.datasetIndex === datasetIndex ? mode : undefined);
        }
      }
      hide(datasetIndex, dataIndex) {
        this._updateVisibility(datasetIndex, dataIndex, false);
      }
      show(datasetIndex, dataIndex) {
        this._updateVisibility(datasetIndex, dataIndex, true);
      }
      _destroyDatasetMeta(datasetIndex) {
        const me = this;
        const meta = me._metasets && me._metasets[datasetIndex];
        if (meta && meta.controller) {
          meta.controller._destroy();
          delete me._metasets[datasetIndex];
        }
      }
      destroy() {
        const me = this;
        const {canvas, ctx} = me;
        let i, ilen;
        me.stop();
        animator.remove(me);
        for (i = 0, ilen = me.data.datasets.length; i < ilen; ++i) {
          me._destroyDatasetMeta(i);
        }
        me.config.clearCache();
        if (canvas) {
          me.unbindEvents();
          clearCanvas(canvas, ctx);
          me.platform.releaseContext(ctx);
          me.canvas = null;
          me.ctx = null;
        }
        me.notifyPlugins('destroy');
        delete instances[me.id];
      }
      toBase64Image(...args) {
        return this.canvas.toDataURL(...args);
      }
      bindEvents() {
        this.bindUserEvents();
        if (this.options.responsive) {
          this.bindResponsiveEvents();
        } else {
          this.attached = true;
        }
      }
      bindUserEvents() {
        const me = this;
        const listeners = me._listeners;
        const platform = me.platform;
        const _add = (type, listener) => {
          platform.addEventListener(me, type, listener);
          listeners[type] = listener;
        };
        const listener = function(e, x, y) {
          e.offsetX = x;
          e.offsetY = y;
          me._eventHandler(e);
        };
        each(me.options.events, (type) => _add(type, listener));
      }
      bindResponsiveEvents() {
        const me = this;
        if (!me._responsiveListeners) {
          me._responsiveListeners = {};
        }
        const listeners = me._responsiveListeners;
        const platform = me.platform;
        const _add = (type, listener) => {
          platform.addEventListener(me, type, listener);
          listeners[type] = listener;
        };
        const _remove = (type, listener) => {
          if (listeners[type]) {
            platform.removeEventListener(me, type, listener);
            delete listeners[type];
          }
        };
        const listener = (width, height) => {
          if (me.canvas) {
            me.resize(width, height);
          }
        };
        let detached;
        const attached = () => {
          _remove('attach', attached);
          me.attached = true;
          me.resize();
          _add('resize', listener);
          _add('detach', detached);
        };
        detached = () => {
          me.attached = false;
          _remove('resize', listener);
          _add('attach', attached);
        };
        if (platform.isAttached(me.canvas)) {
          attached();
        } else {
          detached();
        }
      }
      unbindEvents() {
        const me = this;
        each(me._listeners, (listener, type) => {
          me.platform.removeEventListener(me, type, listener);
        });
        me._listeners = {};
        each(me._responsiveListeners, (listener, type) => {
          me.platform.removeEventListener(me, type, listener);
        });
        me._responsiveListeners = undefined;
      }
      updateHoverStyle(items, mode, enabled) {
        const prefix = enabled ? 'set' : 'remove';
        let meta, item, i, ilen;
        if (mode === 'dataset') {
          meta = this.getDatasetMeta(items[0].datasetIndex);
          meta.controller['_' + prefix + 'DatasetHoverStyle']();
        }
        for (i = 0, ilen = items.length; i < ilen; ++i) {
          item = items[i];
          const controller = item && this.getDatasetMeta(item.datasetIndex).controller;
          if (controller) {
            controller[prefix + 'HoverStyle'](item.element, item.datasetIndex, item.index);
          }
        }
      }
      getActiveElements() {
        return this._active || [];
      }
      setActiveElements(activeElements) {
        const me = this;
        const lastActive = me._active || [];
        const active = activeElements.map(({datasetIndex, index}) => {
          const meta = me.getDatasetMeta(datasetIndex);
          if (!meta) {
            throw new Error('No dataset found at index ' + datasetIndex);
          }
          return {
            datasetIndex,
            element: meta.data[index],
            index,
          };
        });
        const changed = !_elementsEqual(active, lastActive);
        if (changed) {
          me._active = active;
          me._updateHoverStyles(active, lastActive);
        }
      }
      notifyPlugins(hook, args, filter) {
        return this._plugins.notify(this, hook, args, filter);
      }
      _updateHoverStyles(active, lastActive, replay) {
        const me = this;
        const hoverOptions = me.options.hover;
        const diff = (a, b) => a.filter(x => !b.some(y => x.datasetIndex === y.datasetIndex && x.index === y.index));
        const deactivated = diff(lastActive, active);
        const activated = replay ? active : diff(active, lastActive);
        if (deactivated.length) {
          me.updateHoverStyle(deactivated, hoverOptions.mode, false);
        }
        if (activated.length && hoverOptions.mode) {
          me.updateHoverStyle(activated, hoverOptions.mode, true);
        }
      }
      _eventHandler(e, replay) {
        const me = this;
        const args = {event: e, replay, cancelable: true};
        const eventFilter = (plugin) => (plugin.options.events || this.options.events).includes(e.type);
        if (me.notifyPlugins('beforeEvent', args, eventFilter) === false) {
          return;
        }
        const changed = me._handleEvent(e, replay);
        args.cancelable = false;
        me.notifyPlugins('afterEvent', args, eventFilter);
        if (changed || args.changed) {
          me.render();
        }
        return me;
      }
      _handleEvent(e, replay) {
        const me = this;
        const {_active: lastActive = [], options} = me;
        const hoverOptions = options.hover;
        const useFinalPosition = replay;
        let active = [];
        let changed = false;
        let lastEvent = null;
        if (e.type !== 'mouseout') {
          active = me.getElementsAtEventForMode(e, hoverOptions.mode, hoverOptions, useFinalPosition);
          lastEvent = e.type === 'click' ? me._lastEvent : e;
        }
        me._lastEvent = null;
        if (_isPointInArea(e, me.chartArea, me._minPadding)) {
          callback(options.onHover, [e, active, me], me);
          if (e.type === 'mouseup' || e.type === 'click' || e.type === 'contextmenu') {
            callback(options.onClick, [e, active, me], me);
          }
        }
        changed = !_elementsEqual(active, lastActive);
        if (changed || replay) {
          me._active = active;
          me._updateHoverStyles(active, lastActive, replay);
        }
        me._lastEvent = lastEvent;
        return changed;
      }
    }
    const invalidatePlugins = () => each(Chart.instances, (chart) => chart._plugins.invalidate());
    const enumerable = true;
    Object.defineProperties(Chart, {
      defaults: {
        enumerable,
        value: defaults
      },
      instances: {
        enumerable,
        value: instances
      },
      overrides: {
        enumerable,
        value: overrides
      },
      registry: {
        enumerable,
        value: registry
      },
      version: {
        enumerable,
        value: version
      },
      getChart: {
        enumerable,
        value: getChart
      },
      register: {
        enumerable,
        value: (...items) => {
          registry.add(...items);
          invalidatePlugins();
        }
      },
      unregister: {
        enumerable,
        value: (...items) => {
          registry.remove(...items);
          invalidatePlugins();
        }
      }
    });
    
    function abstract() {
      throw new Error('This method is not implemented: Check that a complete date adapter is provided.');
    }
    class DateAdapter {
      constructor(options) {
        this.options = options || {};
      }
      formats() {
        return abstract();
      }
      parse(value, format) {
        return abstract();
      }
      format(timestamp, format) {
        return abstract();
      }
      add(timestamp, amount, unit) {
        return abstract();
      }
      diff(a, b, unit) {
        return abstract();
      }
      startOf(timestamp, unit, weekday) {
        return abstract();
      }
      endOf(timestamp, unit) {
        return abstract();
      }
    }
    DateAdapter.override = function(members) {
      Object.assign(DateAdapter.prototype, members);
    };
    var _adapters = {
      _date: DateAdapter
    };
    
    function getAllScaleValues(scale) {
      if (!scale._cache.$bar) {
        const metas = scale.getMatchingVisibleMetas('bar');
        let values = [];
        for (let i = 0, ilen = metas.length; i < ilen; i++) {
          values = values.concat(metas[i].controller.getAllParsedValues(scale));
        }
        scale._cache.$bar = _arrayUnique(values.sort((a, b) => a - b));
      }
      return scale._cache.$bar;
    }
    function computeMinSampleSize(scale) {
      const values = getAllScaleValues(scale);
      let min = scale._length;
      let i, ilen, curr, prev;
      const updateMinAndPrev = () => {
        if (curr === 32767 || curr === -32768) {
          return;
        }
        if (defined(prev)) {
          min = Math.min(min, Math.abs(curr - prev) || min);
        }
        prev = curr;
      };
      for (i = 0, ilen = values.length; i < ilen; ++i) {
        curr = scale.getPixelForValue(values[i]);
        updateMinAndPrev();
      }
      prev = undefined;
      for (i = 0, ilen = scale.ticks.length; i < ilen; ++i) {
        curr = scale.getPixelForTick(i);
        updateMinAndPrev();
      }
      return min;
    }
    function computeFitCategoryTraits(index, ruler, options, stackCount) {
      const thickness = options.barThickness;
      let size, ratio;
      if (isNullOrUndef(thickness)) {
        size = ruler.min * options.categoryPercentage;
        ratio = options.barPercentage;
      } else {
        size = thickness * stackCount;
        ratio = 1;
      }
      return {
        chunk: size / stackCount,
        ratio,
        start: ruler.pixels[index] - (size / 2)
      };
    }
    function computeFlexCategoryTraits(index, ruler, options, stackCount) {
      const pixels = ruler.pixels;
      const curr = pixels[index];
      let prev = index > 0 ? pixels[index - 1] : null;
      let next = index < pixels.length - 1 ? pixels[index + 1] : null;
      const percent = options.categoryPercentage;
      if (prev === null) {
        prev = curr - (next === null ? ruler.end - ruler.start : next - curr);
      }
      if (next === null) {
        next = curr + curr - prev;
      }
      const start = curr - (curr - Math.min(prev, next)) / 2 * percent;
      const size = Math.abs(next - prev) / 2 * percent;
      return {
        chunk: size / stackCount,
        ratio: options.barPercentage,
        start
      };
    }
    function parseFloatBar(entry, item, vScale, i) {
      const startValue = vScale.parse(entry[0], i);
      const endValue = vScale.parse(entry[1], i);
      const min = Math.min(startValue, endValue);
      const max = Math.max(startValue, endValue);
      let barStart = min;
      let barEnd = max;
      if (Math.abs(min) > Math.abs(max)) {
        barStart = max;
        barEnd = min;
      }
      item[vScale.axis] = barEnd;
      item._custom = {
        barStart,
        barEnd,
        start: startValue,
        end: endValue,
        min,
        max
      };
    }
    function parseValue(entry, item, vScale, i) {
      if (isArray(entry)) {
        parseFloatBar(entry, item, vScale, i);
      } else {
        item[vScale.axis] = vScale.parse(entry, i);
      }
      return item;
    }
    function parseArrayOrPrimitive(meta, data, start, count) {
      const iScale = meta.iScale;
      const vScale = meta.vScale;
      const labels = iScale.getLabels();
      const singleScale = iScale === vScale;
      const parsed = [];
      let i, ilen, item, entry;
      for (i = start, ilen = start + count; i < ilen; ++i) {
        entry = data[i];
        item = {};
        item[iScale.axis] = singleScale || iScale.parse(labels[i], i);
        parsed.push(parseValue(entry, item, vScale, i));
      }
      return parsed;
    }
    function isFloatBar(custom) {
      return custom && custom.barStart !== undefined && custom.barEnd !== undefined;
    }
    function barSign(size, vScale, actualBase) {
      if (size !== 0) {
        return sign(size);
      }
      return (vScale.isHorizontal() ? 1 : -1) * (vScale.min >= actualBase ? 1 : -1);
    }
    function borderProps(properties) {
      let reverse, start, end, top, bottom;
      if (properties.horizontal) {
        reverse = properties.base > properties.x;
        start = 'left';
        end = 'right';
      } else {
        reverse = properties.base < properties.y;
        start = 'bottom';
        end = 'top';
      }
      if (reverse) {
        top = 'end';
        bottom = 'start';
      } else {
        top = 'start';
        bottom = 'end';
      }
      return {start, end, reverse, top, bottom};
    }
    function setBorderSkipped(properties, options, stack, index) {
      let edge = options.borderSkipped;
      const res = {};
      if (!edge) {
        properties.borderSkipped = res;
        return;
      }
      const {start, end, reverse, top, bottom} = borderProps(properties);
      if (edge === 'middle' && stack) {
        properties.enableBorderRadius = true;
        if ((stack._top || 0) === index) {
          edge = top;
        } else if ((stack._bottom || 0) === index) {
          edge = bottom;
        } else {
          res[parseEdge(bottom, start, end, reverse)] = true;
          edge = top;
        }
      }
      res[parseEdge(edge, start, end, reverse)] = true;
      properties.borderSkipped = res;
    }
    function parseEdge(edge, a, b, reverse) {
      if (reverse) {
        edge = swap(edge, a, b);
        edge = startEnd(edge, b, a);
      } else {
        edge = startEnd(edge, a, b);
      }
      return edge;
    }
    function swap(orig, v1, v2) {
      return orig === v1 ? v2 : orig === v2 ? v1 : orig;
    }
    function startEnd(v, start, end) {
      return v === 'start' ? start : v === 'end' ? end : v;
    }
    class BarController extends DatasetController {
      parsePrimitiveData(meta, data, start, count) {
        return parseArrayOrPrimitive(meta, data, start, count);
      }
      parseArrayData(meta, data, start, count) {
        return parseArrayOrPrimitive(meta, data, start, count);
      }
      parseObjectData(meta, data, start, count) {
        const {iScale, vScale} = meta;
        const {xAxisKey = 'x', yAxisKey = 'y'} = this._parsing;
        const iAxisKey = iScale.axis === 'x' ? xAxisKey : yAxisKey;
        const vAxisKey = vScale.axis === 'x' ? xAxisKey : yAxisKey;
        const parsed = [];
        let i, ilen, item, obj;
        for (i = start, ilen = start + count; i < ilen; ++i) {
          obj = data[i];
          item = {};
          item[iScale.axis] = iScale.parse(resolveObjectKey(obj, iAxisKey), i);
          parsed.push(parseValue(resolveObjectKey(obj, vAxisKey), item, vScale, i));
        }
        return parsed;
      }
      updateRangeFromParsed(range, scale, parsed, stack) {
        super.updateRangeFromParsed(range, scale, parsed, stack);
        const custom = parsed._custom;
        if (custom && scale === this._cachedMeta.vScale) {
          range.min = Math.min(range.min, custom.min);
          range.max = Math.max(range.max, custom.max);
        }
      }
      getMaxOverflow() {
        return 0;
      }
      getLabelAndValue(index) {
        const me = this;
        const meta = me._cachedMeta;
        const {iScale, vScale} = meta;
        const parsed = me.getParsed(index);
        const custom = parsed._custom;
        const value = isFloatBar(custom)
          ? '[' + custom.start + ', ' + custom.end + ']'
          : '' + vScale.getLabelForValue(parsed[vScale.axis]);
        return {
          label: '' + iScale.getLabelForValue(parsed[iScale.axis]),
          value
        };
      }
      initialize() {
        const me = this;
        me.enableOptionSharing = true;
        super.initialize();
        const meta = me._cachedMeta;
        meta.stack = me.getDataset().stack;
      }
      update(mode) {
        const me = this;
        const meta = me._cachedMeta;
        me.updateElements(meta.data, 0, meta.data.length, mode);
      }
      updateElements(bars, start, count, mode) {
        const me = this;
        const reset = mode === 'reset';
        const {index, _cachedMeta: {vScale}} = me;
        const base = vScale.getBasePixel();
        const horizontal = vScale.isHorizontal();
        const ruler = me._getRuler();
        const firstOpts = me.resolveDataElementOptions(start, mode);
        const sharedOptions = me.getSharedOptions(firstOpts);
        const includeOptions = me.includeOptions(mode, sharedOptions);
        me.updateSharedOptions(sharedOptions, mode, firstOpts);
        for (let i = start; i < start + count; i++) {
          const parsed = me.getParsed(i);
          const vpixels = reset || isNullOrUndef(parsed[vScale.axis]) ? {base, head: base} : me._calculateBarValuePixels(i);
          const ipixels = me._calculateBarIndexPixels(i, ruler);
          const stack = (parsed._stacks || {})[vScale.axis];
          const properties = {
            horizontal,
            base: vpixels.base,
            enableBorderRadius: !stack || isFloatBar(parsed._custom) || (index === stack._top || index === stack._bottom),
            x: horizontal ? vpixels.head : ipixels.center,
            y: horizontal ? ipixels.center : vpixels.head,
            height: horizontal ? ipixels.size : Math.abs(vpixels.size),
            width: horizontal ? Math.abs(vpixels.size) : ipixels.size
          };
          if (includeOptions) {
            properties.options = sharedOptions || me.resolveDataElementOptions(i, bars[i].active ? 'active' : mode);
          }
          setBorderSkipped(properties, properties.options || bars[i].options, stack, index);
          me.updateElement(bars[i], i, properties, mode);
        }
      }
      _getStacks(last, dataIndex) {
        const me = this;
        const meta = me._cachedMeta;
        const iScale = meta.iScale;
        const metasets = iScale.getMatchingVisibleMetas(me._type);
        const stacked = iScale.options.stacked;
        const ilen = metasets.length;
        const stacks = [];
        let i, item;
        for (i = 0; i < ilen; ++i) {
          item = metasets[i];
          if (!item.controller.options.grouped) {
            continue;
          }
          if (typeof dataIndex !== 'undefined') {
            const val = item.controller.getParsed(dataIndex)[
              item.controller._cachedMeta.vScale.axis
            ];
            if (isNullOrUndef(val) || isNaN(val)) {
              continue;
            }
          }
          if (stacked === false || stacks.indexOf(item.stack) === -1 ||
                    (stacked === undefined && item.stack === undefined)) {
            stacks.push(item.stack);
          }
          if (item.index === last) {
            break;
          }
        }
        if (!stacks.length) {
          stacks.push(undefined);
        }
        return stacks;
      }
      _getStackCount(index) {
        return this._getStacks(undefined, index).length;
      }
      _getStackIndex(datasetIndex, name, dataIndex) {
        const stacks = this._getStacks(datasetIndex, dataIndex);
        const index = (name !== undefined)
          ? stacks.indexOf(name)
          : -1;
        return (index === -1)
          ? stacks.length - 1
          : index;
      }
      _getRuler() {
        const me = this;
        const opts = me.options;
        const meta = me._cachedMeta;
        const iScale = meta.iScale;
        const pixels = [];
        let i, ilen;
        for (i = 0, ilen = meta.data.length; i < ilen; ++i) {
          pixels.push(iScale.getPixelForValue(me.getParsed(i)[iScale.axis], i));
        }
        const barThickness = opts.barThickness;
        const min = barThickness || computeMinSampleSize(iScale);
        return {
          min,
          pixels,
          start: iScale._startPixel,
          end: iScale._endPixel,
          stackCount: me._getStackCount(),
          scale: iScale,
          grouped: opts.grouped,
          ratio: barThickness ? 1 : opts.categoryPercentage * opts.barPercentage
        };
      }
      _calculateBarValuePixels(index) {
        const me = this;
        const {_cachedMeta: {vScale, _stacked}, options: {base: baseValue, minBarLength}} = me;
        const actualBase = baseValue || 0;
        const parsed = me.getParsed(index);
        const custom = parsed._custom;
        const floating = isFloatBar(custom);
        let value = parsed[vScale.axis];
        let start = 0;
        let length = _stacked ? me.applyStack(vScale, parsed, _stacked) : value;
        let head, size;
        if (length !== value) {
          start = length - value;
          length = value;
        }
        if (floating) {
          value = custom.barStart;
          length = custom.barEnd - custom.barStart;
          if (value !== 0 && sign(value) !== sign(custom.barEnd)) {
            start = 0;
          }
          start += value;
        }
        const startValue = !isNullOrUndef(baseValue) && !floating ? baseValue : start;
        let base = vScale.getPixelForValue(startValue);
        if (me.chart.getDataVisibility(index)) {
          head = vScale.getPixelForValue(start + length);
        } else {
          head = base;
        }
        size = head - base;
        if (Math.abs(size) < minBarLength) {
          size = barSign(size, vScale, actualBase) * minBarLength;
          if (value === actualBase) {
            base -= size / 2;
          }
          head = base + size;
        }
        if (base === vScale.getPixelForValue(actualBase)) {
          const halfGrid = sign(size) * vScale.getLineWidthForValue(actualBase) / 2;
          base += halfGrid;
          size -= halfGrid;
        }
        return {
          size,
          base,
          head,
          center: head + size / 2
        };
      }
      _calculateBarIndexPixels(index, ruler) {
        const me = this;
        const scale = ruler.scale;
        const options = me.options;
        const skipNull = options.skipNull;
        const maxBarThickness = valueOrDefault(options.maxBarThickness, Infinity);
        let center, size;
        if (ruler.grouped) {
          const stackCount = skipNull ? me._getStackCount(index) : ruler.stackCount;
          const range = options.barThickness === 'flex'
            ? computeFlexCategoryTraits(index, ruler, options, stackCount)
            : computeFitCategoryTraits(index, ruler, options, stackCount);
          const stackIndex = me._getStackIndex(me.index, me._cachedMeta.stack, skipNull ? index : undefined);
          center = range.start + (range.chunk * stackIndex) + (range.chunk / 2);
          size = Math.min(maxBarThickness, range.chunk * range.ratio);
        } else {
          center = scale.getPixelForValue(me.getParsed(index)[scale.axis], index);
          size = Math.min(maxBarThickness, ruler.min * ruler.ratio);
        }
        return {
          base: center - size / 2,
          head: center + size / 2,
          center,
          size
        };
      }
      draw() {
        const me = this;
        const meta = me._cachedMeta;
        const vScale = meta.vScale;
        const rects = meta.data;
        const ilen = rects.length;
        let i = 0;
        for (; i < ilen; ++i) {
          if (me.getParsed(i)[vScale.axis] !== null) {
            rects[i].draw(me._ctx);
          }
        }
      }
    }
    BarController.id = 'bar';
    BarController.defaults = {
      datasetElementType: false,
      dataElementType: 'bar',
      categoryPercentage: 0.8,
      barPercentage: 0.9,
      grouped: true,
      animations: {
        numbers: {
          type: 'number',
          properties: ['x', 'y', 'base', 'width', 'height']
        }
      }
    };
    BarController.overrides = {
      scales: {
        _index_: {
          type: 'category',
          offset: true,
          grid: {
            offset: true
          }
        },
        _value_: {
          type: 'linear',
          beginAtZero: true,
        }
      }
    };
    
    class BubbleController extends DatasetController {
      initialize() {
        this.enableOptionSharing = true;
        super.initialize();
      }
      parseObjectData(meta, data, start, count) {
        const {xScale, yScale} = meta;
        const {xAxisKey = 'x', yAxisKey = 'y'} = this._parsing;
        const parsed = [];
        let i, ilen, item;
        for (i = start, ilen = start + count; i < ilen; ++i) {
          item = data[i];
          parsed.push({
            x: xScale.parse(resolveObjectKey(item, xAxisKey), i),
            y: yScale.parse(resolveObjectKey(item, yAxisKey), i),
            _custom: item && item.r && +item.r
          });
        }
        return parsed;
      }
      getMaxOverflow() {
        const {data, _parsed} = this._cachedMeta;
        let max = 0;
        for (let i = data.length - 1; i >= 0; --i) {
          max = Math.max(max, data[i].size() / 2, _parsed[i]._custom);
        }
        return max > 0 && max;
      }
      getLabelAndValue(index) {
        const me = this;
        const meta = me._cachedMeta;
        const {xScale, yScale} = meta;
        const parsed = me.getParsed(index);
        const x = xScale.getLabelForValue(parsed.x);
        const y = yScale.getLabelForValue(parsed.y);
        const r = parsed._custom;
        return {
          label: meta.label,
          value: '(' + x + ', ' + y + (r ? ', ' + r : '') + ')'
        };
      }
      update(mode) {
        const me = this;
        const points = me._cachedMeta.data;
        me.updateElements(points, 0, points.length, mode);
      }
      updateElements(points, start, count, mode) {
        const me = this;
        const reset = mode === 'reset';
        const {iScale, vScale} = me._cachedMeta;
        const firstOpts = me.resolveDataElementOptions(start, mode);
        const sharedOptions = me.getSharedOptions(firstOpts);
        const includeOptions = me.includeOptions(mode, sharedOptions);
        const iAxis = iScale.axis;
        const vAxis = vScale.axis;
        for (let i = start; i < start + count; i++) {
          const point = points[i];
          const parsed = !reset && me.getParsed(i);
          const properties = {};
          const iPixel = properties[iAxis] = reset ? iScale.getPixelForDecimal(0.5) : iScale.getPixelForValue(parsed[iAxis]);
          const vPixel = properties[vAxis] = reset ? vScale.getBasePixel() : vScale.getPixelForValue(parsed[vAxis]);
          properties.skip = isNaN(iPixel) || isNaN(vPixel);
          if (includeOptions) {
            properties.options = me.resolveDataElementOptions(i, point.active ? 'active' : mode);
            if (reset) {
              properties.options.radius = 0;
            }
          }
          me.updateElement(point, i, properties, mode);
        }
        me.updateSharedOptions(sharedOptions, mode, firstOpts);
      }
      resolveDataElementOptions(index, mode) {
        const parsed = this.getParsed(index);
        let values = super.resolveDataElementOptions(index, mode);
        if (values.$shared) {
          values = Object.assign({}, values, {$shared: false});
        }
        const radius = values.radius;
        if (mode !== 'active') {
          values.radius = 0;
        }
        values.radius += valueOrDefault(parsed && parsed._custom, radius);
        return values;
      }
    }
    BubbleController.id = 'bubble';
    BubbleController.defaults = {
      datasetElementType: false,
      dataElementType: 'point',
      animations: {
        numbers: {
          type: 'number',
          properties: ['x', 'y', 'borderWidth', 'radius']
        }
      }
    };
    BubbleController.overrides = {
      scales: {
        x: {
          type: 'linear'
        },
        y: {
          type: 'linear'
        }
      },
      plugins: {
        tooltip: {
          callbacks: {
            title() {
              return '';
            }
          }
        }
      }
    };
    
    function getRatioAndOffset(rotation, circumference, cutout) {
      let ratioX = 1;
      let ratioY = 1;
      let offsetX = 0;
      let offsetY = 0;
      if (circumference < TAU) {
        const startAngle = rotation;
        const endAngle = startAngle + circumference;
        const startX = Math.cos(startAngle);
        const startY = Math.sin(startAngle);
        const endX = Math.cos(endAngle);
        const endY = Math.sin(endAngle);
        const calcMax = (angle, a, b) => _angleBetween(angle, startAngle, endAngle, true) ? 1 : Math.max(a, a * cutout, b, b * cutout);
        const calcMin = (angle, a, b) => _angleBetween(angle, startAngle, endAngle, true) ? -1 : Math.min(a, a * cutout, b, b * cutout);
        const maxX = calcMax(0, startX, endX);
        const maxY = calcMax(HALF_PI, startY, endY);
        const minX = calcMin(PI, startX, endX);
        const minY = calcMin(PI + HALF_PI, startY, endY);
        ratioX = (maxX - minX) / 2;
        ratioY = (maxY - minY) / 2;
        offsetX = -(maxX + minX) / 2;
        offsetY = -(maxY + minY) / 2;
      }
      return {ratioX, ratioY, offsetX, offsetY};
    }
    class DoughnutController extends DatasetController {
      constructor(chart, datasetIndex) {
        super(chart, datasetIndex);
        this.enableOptionSharing = true;
        this.innerRadius = undefined;
        this.outerRadius = undefined;
        this.offsetX = undefined;
        this.offsetY = undefined;
      }
      linkScales() {}
      parse(start, count) {
        const data = this.getDataset().data;
        const meta = this._cachedMeta;
        let i, ilen;
        for (i = start, ilen = start + count; i < ilen; ++i) {
          meta._parsed[i] = +data[i];
        }
      }
      _getRotation() {
        return toRadians(this.options.rotation - 90);
      }
      _getCircumference() {
        return toRadians(this.options.circumference);
      }
      _getRotationExtents() {
        let min = TAU;
        let max = -TAU;
        const me = this;
        for (let i = 0; i < me.chart.data.datasets.length; ++i) {
          if (me.chart.isDatasetVisible(i)) {
            const controller = me.chart.getDatasetMeta(i).controller;
            const rotation = controller._getRotation();
            const circumference = controller._getCircumference();
            min = Math.min(min, rotation);
            max = Math.max(max, rotation + circumference);
          }
        }
        return {
          rotation: min,
          circumference: max - min,
        };
      }
      update(mode) {
        const me = this;
        const chart = me.chart;
        const {chartArea} = chart;
        const meta = me._cachedMeta;
        const arcs = meta.data;
        const spacing = me.getMaxBorderWidth() + me.getMaxOffset(arcs) + me.options.spacing;
        const maxSize = Math.max((Math.min(chartArea.width, chartArea.height) - spacing) / 2, 0);
        const cutout = Math.min(toPercentage(me.options.cutout, maxSize), 1);
        const chartWeight = me._getRingWeight(me.index);
        const {circumference, rotation} = me._getRotationExtents();
        const {ratioX, ratioY, offsetX, offsetY} = getRatioAndOffset(rotation, circumference, cutout);
        const maxWidth = (chartArea.width - spacing) / ratioX;
        const maxHeight = (chartArea.height - spacing) / ratioY;
        const maxRadius = Math.max(Math.min(maxWidth, maxHeight) / 2, 0);
        const outerRadius = toDimension(me.options.radius, maxRadius);
        const innerRadius = Math.max(outerRadius * cutout, 0);
        const radiusLength = (outerRadius - innerRadius) / me._getVisibleDatasetWeightTotal();
        me.offsetX = offsetX * outerRadius;
        me.offsetY = offsetY * outerRadius;
        meta.total = me.calculateTotal();
        me.outerRadius = outerRadius - radiusLength * me._getRingWeightOffset(me.index);
        me.innerRadius = Math.max(me.outerRadius - radiusLength * chartWeight, 0);
        me.updateElements(arcs, 0, arcs.length, mode);
      }
      _circumference(i, reset) {
        const me = this;
        const opts = me.options;
        const meta = me._cachedMeta;
        const circumference = me._getCircumference();
        if ((reset && opts.animation.animateRotate) || !this.chart.getDataVisibility(i) || meta._parsed[i] === null || meta.data[i].hidden) {
          return 0;
        }
        return me.calculateCircumference(meta._parsed[i] * circumference / TAU);
      }
      updateElements(arcs, start, count, mode) {
        const me = this;
        const reset = mode === 'reset';
        const chart = me.chart;
        const chartArea = chart.chartArea;
        const opts = chart.options;
        const animationOpts = opts.animation;
        const centerX = (chartArea.left + chartArea.right) / 2;
        const centerY = (chartArea.top + chartArea.bottom) / 2;
        const animateScale = reset && animationOpts.animateScale;
        const innerRadius = animateScale ? 0 : me.innerRadius;
        const outerRadius = animateScale ? 0 : me.outerRadius;
        const firstOpts = me.resolveDataElementOptions(start, mode);
        const sharedOptions = me.getSharedOptions(firstOpts);
        const includeOptions = me.includeOptions(mode, sharedOptions);
        let startAngle = me._getRotation();
        let i;
        for (i = 0; i < start; ++i) {
          startAngle += me._circumference(i, reset);
        }
        for (i = start; i < start + count; ++i) {
          const circumference = me._circumference(i, reset);
          const arc = arcs[i];
          const properties = {
            x: centerX + me.offsetX,
            y: centerY + me.offsetY,
            startAngle,
            endAngle: startAngle + circumference,
            circumference,
            outerRadius,
            innerRadius
          };
          if (includeOptions) {
            properties.options = sharedOptions || me.resolveDataElementOptions(i, arc.active ? 'active' : mode);
          }
          startAngle += circumference;
          me.updateElement(arc, i, properties, mode);
        }
        me.updateSharedOptions(sharedOptions, mode, firstOpts);
      }
      calculateTotal() {
        const meta = this._cachedMeta;
        const metaData = meta.data;
        let total = 0;
        let i;
        for (i = 0; i < metaData.length; i++) {
          const value = meta._parsed[i];
          if (value !== null && !isNaN(value) && this.chart.getDataVisibility(i) && !metaData[i].hidden) {
            total += Math.abs(value);
          }
        }
        return total;
      }
      calculateCircumference(value) {
        const total = this._cachedMeta.total;
        if (total > 0 && !isNaN(value)) {
          return TAU * (Math.abs(value) / total);
        }
        return 0;
      }
      getLabelAndValue(index) {
        const me = this;
        const meta = me._cachedMeta;
        const chart = me.chart;
        const labels = chart.data.labels || [];
        const value = formatNumber(meta._parsed[index], chart.options.locale);
        return {
          label: labels[index] || '',
          value,
        };
      }
      getMaxBorderWidth(arcs) {
        const me = this;
        let max = 0;
        const chart = me.chart;
        let i, ilen, meta, controller, options;
        if (!arcs) {
          for (i = 0, ilen = chart.data.datasets.length; i < ilen; ++i) {
            if (chart.isDatasetVisible(i)) {
              meta = chart.getDatasetMeta(i);
              arcs = meta.data;
              controller = meta.controller;
              if (controller !== me) {
                controller.configure();
              }
              break;
            }
          }
        }
        if (!arcs) {
          return 0;
        }
        for (i = 0, ilen = arcs.length; i < ilen; ++i) {
          options = controller.resolveDataElementOptions(i);
          if (options.borderAlign !== 'inner') {
            max = Math.max(max, options.borderWidth || 0, options.hoverBorderWidth || 0);
          }
        }
        return max;
      }
      getMaxOffset(arcs) {
        let max = 0;
        for (let i = 0, ilen = arcs.length; i < ilen; ++i) {
          const options = this.resolveDataElementOptions(i);
          max = Math.max(max, options.offset || 0, options.hoverOffset || 0);
        }
        return max;
      }
      _getRingWeightOffset(datasetIndex) {
        let ringWeightOffset = 0;
        for (let i = 0; i < datasetIndex; ++i) {
          if (this.chart.isDatasetVisible(i)) {
            ringWeightOffset += this._getRingWeight(i);
          }
        }
        return ringWeightOffset;
      }
      _getRingWeight(datasetIndex) {
        return Math.max(valueOrDefault(this.chart.data.datasets[datasetIndex].weight, 1), 0);
      }
      _getVisibleDatasetWeightTotal() {
        return this._getRingWeightOffset(this.chart.data.datasets.length) || 1;
      }
    }
    DoughnutController.id = 'doughnut';
    DoughnutController.defaults = {
      datasetElementType: false,
      dataElementType: 'arc',
      animation: {
        animateRotate: true,
        animateScale: false
      },
      animations: {
        numbers: {
          type: 'number',
          properties: ['circumference', 'endAngle', 'innerRadius', 'outerRadius', 'startAngle', 'x', 'y', 'offset', 'borderWidth', 'spacing']
        },
      },
      cutout: '50%',
      rotation: 0,
      circumference: 360,
      radius: '100%',
      spacing: 0,
      indexAxis: 'r',
    };
    DoughnutController.descriptors = {
      _scriptable: (name) => name !== 'spacing',
      _indexable: (name) => name !== 'spacing',
    };
    DoughnutController.overrides = {
      aspectRatio: 1,
      plugins: {
        legend: {
          labels: {
            generateLabels(chart) {
              const data = chart.data;
              if (data.labels.length && data.datasets.length) {
                const {labels: {pointStyle}} = chart.legend.options;
                return data.labels.map((label, i) => {
                  const meta = chart.getDatasetMeta(0);
                  const style = meta.controller.getStyle(i);
                  return {
                    text: label,
                    fillStyle: style.backgroundColor,
                    strokeStyle: style.borderColor,
                    lineWidth: style.borderWidth,
                    pointStyle: pointStyle,
                    hidden: !chart.getDataVisibility(i),
                    index: i
                  };
                });
              }
              return [];
            }
          },
          onClick(e, legendItem, legend) {
            legend.chart.toggleDataVisibility(legendItem.index);
            legend.chart.update();
          }
        },
        tooltip: {
          callbacks: {
            title() {
              return '';
            },
            label(tooltipItem) {
              let dataLabel = tooltipItem.label;
              const value = ': ' + tooltipItem.formattedValue;
              if (isArray(dataLabel)) {
                dataLabel = dataLabel.slice();
                dataLabel[0] += value;
              } else {
                dataLabel += value;
              }
              return dataLabel;
            }
          }
        }
      }
    };
    
    class LineController extends DatasetController {
      initialize() {
        this.enableOptionSharing = true;
        super.initialize();
      }
      update(mode) {
        const me = this;
        const meta = me._cachedMeta;
        const {dataset: line, data: points = [], _dataset} = meta;
        const animationsDisabled = me.chart._animationsDisabled;
        let {start, count} = getStartAndCountOfVisiblePoints(meta, points, animationsDisabled);
        me._drawStart = start;
        me._drawCount = count;
        if (scaleRangesChanged(meta)) {
          start = 0;
          count = points.length;
        }
        line._datasetIndex = me.index;
        line._decimated = !!_dataset._decimated;
        line.points = points;
        const options = me.resolveDatasetElementOptions(mode);
        if (!me.options.showLine) {
          options.borderWidth = 0;
        }
        options.segment = me.options.segment;
        me.updateElement(line, undefined, {
          animated: !animationsDisabled,
          options
        }, mode);
        me.updateElements(points, start, count, mode);
      }
      updateElements(points, start, count, mode) {
        const me = this;
        const reset = mode === 'reset';
        const {iScale, vScale, _stacked} = me._cachedMeta;
        const firstOpts = me.resolveDataElementOptions(start, mode);
        const sharedOptions = me.getSharedOptions(firstOpts);
        const includeOptions = me.includeOptions(mode, sharedOptions);
        const iAxis = iScale.axis;
        const vAxis = vScale.axis;
        const spanGaps = me.options.spanGaps;
        const maxGapLength = isNumber(spanGaps) ? spanGaps : Number.POSITIVE_INFINITY;
        const directUpdate = me.chart._animationsDisabled || reset || mode === 'none';
        let prevParsed = start > 0 && me.getParsed(start - 1);
        for (let i = start; i < start + count; ++i) {
          const point = points[i];
          const parsed = me.getParsed(i);
          const properties = directUpdate ? point : {};
          const nullData = isNullOrUndef(parsed[vAxis]);
          const iPixel = properties[iAxis] = iScale.getPixelForValue(parsed[iAxis], i);
          const vPixel = properties[vAxis] = reset || nullData ? vScale.getBasePixel() : vScale.getPixelForValue(_stacked ? me.applyStack(vScale, parsed, _stacked) : parsed[vAxis], i);
          properties.skip = isNaN(iPixel) || isNaN(vPixel) || nullData;
          properties.stop = i > 0 && (parsed[iAxis] - prevParsed[iAxis]) > maxGapLength;
          properties.parsed = parsed;
          if (includeOptions) {
            properties.options = sharedOptions || me.resolveDataElementOptions(i, point.active ? 'active' : mode);
          }
          if (!directUpdate) {
            me.updateElement(point, i, properties, mode);
          }
          prevParsed = parsed;
        }
        me.updateSharedOptions(sharedOptions, mode, firstOpts);
      }
      getMaxOverflow() {
        const me = this;
        const meta = me._cachedMeta;
        const dataset = meta.dataset;
        const border = dataset.options && dataset.options.borderWidth || 0;
        const data = meta.data || [];
        if (!data.length) {
          return border;
        }
        const firstPoint = data[0].size(me.resolveDataElementOptions(0));
        const lastPoint = data[data.length - 1].size(me.resolveDataElementOptions(data.length - 1));
        return Math.max(border, firstPoint, lastPoint) / 2;
      }
      draw() {
        const meta = this._cachedMeta;
        meta.dataset.updateControlPoints(this.chart.chartArea, meta.iScale.axis);
        super.draw();
      }
    }
    LineController.id = 'line';
    LineController.defaults = {
      datasetElementType: 'line',
      dataElementType: 'point',
      showLine: true,
      spanGaps: false,
    };
    LineController.overrides = {
      scales: {
        _index_: {
          type: 'category',
        },
        _value_: {
          type: 'linear',
        },
      }
    };
    function getStartAndCountOfVisiblePoints(meta, points, animationsDisabled) {
      const pointCount = points.length;
      let start = 0;
      let count = pointCount;
      if (meta._sorted) {
        const {iScale, _parsed} = meta;
        const axis = iScale.axis;
        const {min, max, minDefined, maxDefined} = iScale.getUserBounds();
        if (minDefined) {
          start = _limitValue(Math.min(
            _lookupByKey(_parsed, iScale.axis, min).lo,
            animationsDisabled ? pointCount : _lookupByKey(points, axis, iScale.getPixelForValue(min)).lo),
          0, pointCount - 1);
        }
        if (maxDefined) {
          count = _limitValue(Math.max(
            _lookupByKey(_parsed, iScale.axis, max).hi + 1,
            animationsDisabled ? 0 : _lookupByKey(points, axis, iScale.getPixelForValue(max)).hi + 1),
          start, pointCount) - start;
        } else {
          count = pointCount - start;
        }
      }
      return {start, count};
    }
    function scaleRangesChanged(meta) {
      const {xScale, yScale, _scaleRanges} = meta;
      const newRanges = {
        xmin: xScale.min,
        xmax: xScale.max,
        ymin: yScale.min,
        ymax: yScale.max
      };
      if (!_scaleRanges) {
        meta._scaleRanges = newRanges;
        return true;
      }
      const changed = _scaleRanges.xmin !== xScale.min
            || _scaleRanges.xmax !== xScale.max
            || _scaleRanges.ymin !== yScale.min
            || _scaleRanges.ymax !== yScale.max;
      Object.assign(_scaleRanges, newRanges);
      return changed;
    }
    
    class PolarAreaController extends DatasetController {
      constructor(chart, datasetIndex) {
        super(chart, datasetIndex);
        this.innerRadius = undefined;
        this.outerRadius = undefined;
      }
      getLabelAndValue(index) {
        const me = this;
        const meta = me._cachedMeta;
        const chart = me.chart;
        const labels = chart.data.labels || [];
        const value = formatNumber(meta._parsed[index].r, chart.options.locale);
        return {
          label: labels[index] || '',
          value,
        };
      }
      update(mode) {
        const arcs = this._cachedMeta.data;
        this._updateRadius();
        this.updateElements(arcs, 0, arcs.length, mode);
      }
      _updateRadius() {
        const me = this;
        const chart = me.chart;
        const chartArea = chart.chartArea;
        const opts = chart.options;
        const minSize = Math.min(chartArea.right - chartArea.left, chartArea.bottom - chartArea.top);
        const outerRadius = Math.max(minSize / 2, 0);
        const innerRadius = Math.max(opts.cutoutPercentage ? (outerRadius / 100) * (opts.cutoutPercentage) : 1, 0);
        const radiusLength = (outerRadius - innerRadius) / chart.getVisibleDatasetCount();
        me.outerRadius = outerRadius - (radiusLength * me.index);
        me.innerRadius = me.outerRadius - radiusLength;
      }
      updateElements(arcs, start, count, mode) {
        const me = this;
        const reset = mode === 'reset';
        const chart = me.chart;
        const dataset = me.getDataset();
        const opts = chart.options;
        const animationOpts = opts.animation;
        const scale = me._cachedMeta.rScale;
        const centerX = scale.xCenter;
        const centerY = scale.yCenter;
        const datasetStartAngle = scale.getIndexAngle(0) - 0.5 * PI;
        let angle = datasetStartAngle;
        let i;
        const defaultAngle = 360 / me.countVisibleElements();
        for (i = 0; i < start; ++i) {
          angle += me._computeAngle(i, mode, defaultAngle);
        }
        for (i = start; i < start + count; i++) {
          const arc = arcs[i];
          let startAngle = angle;
          let endAngle = angle + me._computeAngle(i, mode, defaultAngle);
          let outerRadius = chart.getDataVisibility(i) ? scale.getDistanceFromCenterForValue(dataset.data[i]) : 0;
          angle = endAngle;
          if (reset) {
            if (animationOpts.animateScale) {
              outerRadius = 0;
            }
            if (animationOpts.animateRotate) {
              startAngle = endAngle = datasetStartAngle;
            }
          }
          const properties = {
            x: centerX,
            y: centerY,
            innerRadius: 0,
            outerRadius,
            startAngle,
            endAngle,
            options: me.resolveDataElementOptions(i, arc.active ? 'active' : mode)
          };
          me.updateElement(arc, i, properties, mode);
        }
      }
      countVisibleElements() {
        const dataset = this.getDataset();
        const meta = this._cachedMeta;
        let count = 0;
        meta.data.forEach((element, index) => {
          if (!isNaN(dataset.data[index]) && this.chart.getDataVisibility(index)) {
            count++;
          }
        });
        return count;
      }
      _computeAngle(index, mode, defaultAngle) {
        return this.chart.getDataVisibility(index)
          ? toRadians(this.resolveDataElementOptions(index, mode).angle || defaultAngle)
          : 0;
      }
    }
    PolarAreaController.id = 'polarArea';
    PolarAreaController.defaults = {
      dataElementType: 'arc',
      animation: {
        animateRotate: true,
        animateScale: true
      },
      animations: {
        numbers: {
          type: 'number',
          properties: ['x', 'y', 'startAngle', 'endAngle', 'innerRadius', 'outerRadius']
        },
      },
      indexAxis: 'r',
      startAngle: 0,
    };
    PolarAreaController.overrides = {
      aspectRatio: 1,
      plugins: {
        legend: {
          labels: {
            generateLabels(chart) {
              const data = chart.data;
              if (data.labels.length && data.datasets.length) {
                const {labels: {pointStyle}} = chart.legend.options;
                return data.labels.map((label, i) => {
                  const meta = chart.getDatasetMeta(0);
                  const style = meta.controller.getStyle(i);
                  return {
                    text: label,
                    fillStyle: style.backgroundColor,
                    strokeStyle: style.borderColor,
                    lineWidth: style.borderWidth,
                    pointStyle: pointStyle,
                    hidden: !chart.getDataVisibility(i),
                    index: i
                  };
                });
              }
              return [];
            }
          },
          onClick(e, legendItem, legend) {
            legend.chart.toggleDataVisibility(legendItem.index);
            legend.chart.update();
          }
        },
        tooltip: {
          callbacks: {
            title() {
              return '';
            },
            label(context) {
              return context.chart.data.labels[context.dataIndex] + ': ' + context.formattedValue;
            }
          }
        }
      },
      scales: {
        r: {
          type: 'radialLinear',
          angleLines: {
            display: false
          },
          beginAtZero: true,
          grid: {
            circular: true
          },
          pointLabels: {
            display: false
          },
          startAngle: 0
        }
      }
    };
    
    class PieController extends DoughnutController {
    }
    PieController.id = 'pie';
    PieController.defaults = {
      cutout: 0,
      rotation: 0,
      circumference: 360,
      radius: '100%'
    };
    
    class RadarController extends DatasetController {
      getLabelAndValue(index) {
        const me = this;
        const vScale = me._cachedMeta.vScale;
        const parsed = me.getParsed(index);
        return {
          label: vScale.getLabels()[index],
          value: '' + vScale.getLabelForValue(parsed[vScale.axis])
        };
      }
      update(mode) {
        const me = this;
        const meta = me._cachedMeta;
        const line = meta.dataset;
        const points = meta.data || [];
        const labels = meta.iScale.getLabels();
        line.points = points;
        if (mode !== 'resize') {
          const options = me.resolveDatasetElementOptions(mode);
          if (!me.options.showLine) {
            options.borderWidth = 0;
          }
          const properties = {
            _loop: true,
            _fullLoop: labels.length === points.length,
            options
          };
          me.updateElement(line, undefined, properties, mode);
        }
        me.updateElements(points, 0, points.length, mode);
      }
      updateElements(points, start, count, mode) {
        const me = this;
        const dataset = me.getDataset();
        const scale = me._cachedMeta.rScale;
        const reset = mode === 'reset';
        for (let i = start; i < start + count; i++) {
          const point = points[i];
          const options = me.resolveDataElementOptions(i, point.active ? 'active' : mode);
          const pointPosition = scale.getPointPositionForValue(i, dataset.data[i]);
          const x = reset ? scale.xCenter : pointPosition.x;
          const y = reset ? scale.yCenter : pointPosition.y;
          const properties = {
            x,
            y,
            angle: pointPosition.angle,
            skip: isNaN(x) || isNaN(y),
            options
          };
          me.updateElement(point, i, properties, mode);
        }
      }
    }
    RadarController.id = 'radar';
    RadarController.defaults = {
      datasetElementType: 'line',
      dataElementType: 'point',
      indexAxis: 'r',
      showLine: true,
      elements: {
        line: {
          fill: 'start'
        }
      },
    };
    RadarController.overrides = {
      aspectRatio: 1,
      scales: {
        r: {
          type: 'radialLinear',
        }
      }
    };
    
    class ScatterController extends LineController {
    }
    ScatterController.id = 'scatter';
    ScatterController.defaults = {
      showLine: false,
      fill: false
    };
    ScatterController.overrides = {
      interaction: {
        mode: 'point'
      },
      plugins: {
        tooltip: {
          callbacks: {
            title() {
              return '';
            },
            label(item) {
              return '(' + item.label + ', ' + item.formattedValue + ')';
            }
          }
        }
      },
      scales: {
        x: {
          type: 'linear'
        },
        y: {
          type: 'linear'
        }
      }
    };
    
    var controllers = /*#__PURE__*/Object.freeze({
    __proto__: null,
    BarController: BarController,
    BubbleController: BubbleController,
    DoughnutController: DoughnutController,
    LineController: LineController,
    PolarAreaController: PolarAreaController,
    PieController: PieController,
    RadarController: RadarController,
    ScatterController: ScatterController
    });
    
    function clipArc(ctx, element, endAngle) {
      const {startAngle, pixelMargin, x, y, outerRadius, innerRadius} = element;
      let angleMargin = pixelMargin / outerRadius;
      ctx.beginPath();
      ctx.arc(x, y, outerRadius, startAngle - angleMargin, endAngle + angleMargin);
      if (innerRadius > pixelMargin) {
        angleMargin = pixelMargin / innerRadius;
        ctx.arc(x, y, innerRadius, endAngle + angleMargin, startAngle - angleMargin, true);
      } else {
        ctx.arc(x, y, pixelMargin, endAngle + HALF_PI, startAngle - HALF_PI);
      }
      ctx.closePath();
      ctx.clip();
    }
    function toRadiusCorners(value) {
      return _readValueToProps(value, ['outerStart', 'outerEnd', 'innerStart', 'innerEnd']);
    }
    function parseBorderRadius$1(arc, innerRadius, outerRadius, angleDelta) {
      const o = toRadiusCorners(arc.options.borderRadius);
      const halfThickness = (outerRadius - innerRadius) / 2;
      const innerLimit = Math.min(halfThickness, angleDelta * innerRadius / 2);
      const computeOuterLimit = (val) => {
        const outerArcLimit = (outerRadius - Math.min(halfThickness, val)) * angleDelta / 2;
        return _limitValue(val, 0, Math.min(halfThickness, outerArcLimit));
      };
      return {
        outerStart: computeOuterLimit(o.outerStart),
        outerEnd: computeOuterLimit(o.outerEnd),
        innerStart: _limitValue(o.innerStart, 0, innerLimit),
        innerEnd: _limitValue(o.innerEnd, 0, innerLimit),
      };
    }
    function rThetaToXY(r, theta, x, y) {
      return {
        x: x + r * Math.cos(theta),
        y: y + r * Math.sin(theta),
      };
    }
    function pathArc(ctx, element, offset, spacing, end) {
      const {x, y, startAngle: start, pixelMargin, innerRadius: innerR} = element;
      const outerRadius = Math.max(element.outerRadius + spacing + offset - pixelMargin, 0);
      const innerRadius = innerR > 0 ? innerR + spacing + offset + pixelMargin : 0;
      let spacingOffset = 0;
      const alpha = end - start;
      if (spacing) {
        const noSpacingInnerRadius = innerR > 0 ? innerR - spacing : 0;
        const noSpacingOuterRadius = outerRadius > 0 ? outerRadius - spacing : 0;
        const avNogSpacingRadius = (noSpacingInnerRadius + noSpacingOuterRadius) / 2;
        const adjustedAngle = avNogSpacingRadius !== 0 ? (alpha * avNogSpacingRadius) / (avNogSpacingRadius + spacing) : alpha;
        spacingOffset = (alpha - adjustedAngle) / 2;
      }
      const beta = Math.max(0.001, alpha * outerRadius - offset / PI) / outerRadius;
      const angleOffset = (alpha - beta) / 2;
      const startAngle = start + angleOffset + spacingOffset;
      const endAngle = end - angleOffset - spacingOffset;
      const {outerStart, outerEnd, innerStart, innerEnd} = parseBorderRadius$1(element, innerRadius, outerRadius, endAngle - startAngle);
      const outerStartAdjustedRadius = outerRadius - outerStart;
      const outerEndAdjustedRadius = outerRadius - outerEnd;
      const outerStartAdjustedAngle = startAngle + outerStart / outerStartAdjustedRadius;
      const outerEndAdjustedAngle = endAngle - outerEnd / outerEndAdjustedRadius;
      const innerStartAdjustedRadius = innerRadius + innerStart;
      const innerEndAdjustedRadius = innerRadius + innerEnd;
      const innerStartAdjustedAngle = startAngle + innerStart / innerStartAdjustedRadius;
      const innerEndAdjustedAngle = endAngle - innerEnd / innerEndAdjustedRadius;
      ctx.beginPath();
      ctx.arc(x, y, outerRadius, outerStartAdjustedAngle, outerEndAdjustedAngle);
      if (outerEnd > 0) {
        const pCenter = rThetaToXY(outerEndAdjustedRadius, outerEndAdjustedAngle, x, y);
        ctx.arc(pCenter.x, pCenter.y, outerEnd, outerEndAdjustedAngle, endAngle + HALF_PI);
      }
      const p4 = rThetaToXY(innerEndAdjustedRadius, endAngle, x, y);
      ctx.lineTo(p4.x, p4.y);
      if (innerEnd > 0) {
        const pCenter = rThetaToXY(innerEndAdjustedRadius, innerEndAdjustedAngle, x, y);
        ctx.arc(pCenter.x, pCenter.y, innerEnd, endAngle + HALF_PI, innerEndAdjustedAngle + Math.PI);
      }
      ctx.arc(x, y, innerRadius, endAngle - (innerEnd / innerRadius), startAngle + (innerStart / innerRadius), true);
      if (innerStart > 0) {
        const pCenter = rThetaToXY(innerStartAdjustedRadius, innerStartAdjustedAngle, x, y);
        ctx.arc(pCenter.x, pCenter.y, innerStart, innerStartAdjustedAngle + Math.PI, startAngle - HALF_PI);
      }
      const p8 = rThetaToXY(outerStartAdjustedRadius, startAngle, x, y);
      ctx.lineTo(p8.x, p8.y);
      if (outerStart > 0) {
        const pCenter = rThetaToXY(outerStartAdjustedRadius, outerStartAdjustedAngle, x, y);
        ctx.arc(pCenter.x, pCenter.y, outerStart, startAngle - HALF_PI, outerStartAdjustedAngle);
      }
      ctx.closePath();
    }
    function drawArc(ctx, element, offset, spacing) {
      const {fullCircles, startAngle, circumference} = element;
      let endAngle = element.endAngle;
      if (fullCircles) {
        pathArc(ctx, element, offset, spacing, startAngle + TAU);
        for (let i = 0; i < fullCircles; ++i) {
          ctx.fill();
        }
        if (!isNaN(circumference)) {
          endAngle = startAngle + circumference % TAU;
          if (circumference % TAU === 0) {
            endAngle += TAU;
          }
        }
      }
      pathArc(ctx, element, offset, spacing, endAngle);
      ctx.fill();
      return endAngle;
    }
    function drawFullCircleBorders(ctx, element, inner) {
      const {x, y, startAngle, pixelMargin, fullCircles} = element;
      const outerRadius = Math.max(element.outerRadius - pixelMargin, 0);
      const innerRadius = element.innerRadius + pixelMargin;
      let i;
      if (inner) {
        clipArc(ctx, element, startAngle + TAU);
      }
      ctx.beginPath();
      ctx.arc(x, y, innerRadius, startAngle + TAU, startAngle, true);
      for (i = 0; i < fullCircles; ++i) {
        ctx.stroke();
      }
      ctx.beginPath();
      ctx.arc(x, y, outerRadius, startAngle, startAngle + TAU);
      for (i = 0; i < fullCircles; ++i) {
        ctx.stroke();
      }
    }
    function drawBorder(ctx, element, offset, spacing, endAngle) {
      const {options} = element;
      const inner = options.borderAlign === 'inner';
      if (!options.borderWidth) {
        return;
      }
      if (inner) {
        ctx.lineWidth = options.borderWidth * 2;
        ctx.lineJoin = 'round';
      } else {
        ctx.lineWidth = options.borderWidth;
        ctx.lineJoin = 'bevel';
      }
      if (element.fullCircles) {
        drawFullCircleBorders(ctx, element, inner);
      }
      if (inner) {
        clipArc(ctx, element, endAngle);
      }
      pathArc(ctx, element, offset, spacing, endAngle);
      ctx.stroke();
    }
    class ArcElement extends Element {
      constructor(cfg) {
        super();
        this.options = undefined;
        this.circumference = undefined;
        this.startAngle = undefined;
        this.endAngle = undefined;
        this.innerRadius = undefined;
        this.outerRadius = undefined;
        this.pixelMargin = 0;
        this.fullCircles = 0;
        if (cfg) {
          Object.assign(this, cfg);
        }
      }
      inRange(chartX, chartY, useFinalPosition) {
        const point = this.getProps(['x', 'y'], useFinalPosition);
        const {angle, distance} = getAngleFromPoint(point, {x: chartX, y: chartY});
        const {startAngle, endAngle, innerRadius, outerRadius, circumference} = this.getProps([
          'startAngle',
          'endAngle',
          'innerRadius',
          'outerRadius',
          'circumference'
        ], useFinalPosition);
        const rAdjust = this.options.spacing / 2;
        const betweenAngles = circumference >= TAU || _angleBetween(angle, startAngle, endAngle);
        const withinRadius = (distance >= innerRadius + rAdjust && distance <= outerRadius + rAdjust);
        return (betweenAngles && withinRadius);
      }
      getCenterPoint(useFinalPosition) {
        const {x, y, startAngle, endAngle, innerRadius, outerRadius} = this.getProps([
          'x',
          'y',
          'startAngle',
          'endAngle',
          'innerRadius',
          'outerRadius',
          'circumference',
        ], useFinalPosition);
        const {offset, spacing} = this.options;
        const halfAngle = (startAngle + endAngle) / 2;
        const halfRadius = (innerRadius + outerRadius + spacing + offset) / 2;
        return {
          x: x + Math.cos(halfAngle) * halfRadius,
          y: y + Math.sin(halfAngle) * halfRadius
        };
      }
      tooltipPosition(useFinalPosition) {
        return this.getCenterPoint(useFinalPosition);
      }
      draw(ctx) {
        const me = this;
        const {options, circumference} = me;
        const offset = (options.offset || 0) / 2;
        const spacing = (options.spacing || 0) / 2;
        me.pixelMargin = (options.borderAlign === 'inner') ? 0.33 : 0;
        me.fullCircles = circumference > TAU ? Math.floor(circumference / TAU) : 0;
        if (circumference === 0 || me.innerRadius < 0 || me.outerRadius < 0) {
          return;
        }
        ctx.save();
        let radiusOffset = 0;
        if (offset) {
          radiusOffset = offset / 2;
          const halfAngle = (me.startAngle + me.endAngle) / 2;
          ctx.translate(Math.cos(halfAngle) * radiusOffset, Math.sin(halfAngle) * radiusOffset);
          if (me.circumference >= PI) {
            radiusOffset = offset;
          }
        }
        ctx.fillStyle = options.backgroundColor;
        ctx.strokeStyle = options.borderColor;
        const endAngle = drawArc(ctx, me, radiusOffset, spacing);
        drawBorder(ctx, me, radiusOffset, spacing, endAngle);
        ctx.restore();
      }
    }
    ArcElement.id = 'arc';
    ArcElement.defaults = {
      borderAlign: 'center',
      borderColor: '#fff',
      borderRadius: 0,
      borderWidth: 2,
      offset: 0,
      spacing: 0,
      angle: undefined,
    };
    ArcElement.defaultRoutes = {
      backgroundColor: 'backgroundColor'
    };
    
    function setStyle(ctx, options, style = options) {
      ctx.lineCap = valueOrDefault(style.borderCapStyle, options.borderCapStyle);
      ctx.setLineDash(valueOrDefault(style.borderDash, options.borderDash));
      ctx.lineDashOffset = valueOrDefault(style.borderDashOffset, options.borderDashOffset);
      ctx.lineJoin = valueOrDefault(style.borderJoinStyle, options.borderJoinStyle);
      ctx.lineWidth = valueOrDefault(style.borderWidth, options.borderWidth);
      ctx.strokeStyle = valueOrDefault(style.borderColor, options.borderColor);
    }
    function lineTo(ctx, previous, target) {
      ctx.lineTo(target.x, target.y);
    }
    function getLineMethod(options) {
      if (options.stepped) {
        return _steppedLineTo;
      }
      if (options.tension || options.cubicInterpolationMode === 'monotone') {
        return _bezierCurveTo;
      }
      return lineTo;
    }
    function pathVars(points, segment, params = {}) {
      const count = points.length;
      const {start: paramsStart = 0, end: paramsEnd = count - 1} = params;
      const {start: segmentStart, end: segmentEnd} = segment;
      const start = Math.max(paramsStart, segmentStart);
      const end = Math.min(paramsEnd, segmentEnd);
      const outside = paramsStart < segmentStart && paramsEnd < segmentStart || paramsStart > segmentEnd && paramsEnd > segmentEnd;
      return {
        count,
        start,
        loop: segment.loop,
        ilen: end < start && !outside ? count + end - start : end - start
      };
    }
    function pathSegment(ctx, line, segment, params) {
      const {points, options} = line;
      const {count, start, loop, ilen} = pathVars(points, segment, params);
      const lineMethod = getLineMethod(options);
      let {move = true, reverse} = params || {};
      let i, point, prev;
      for (i = 0; i <= ilen; ++i) {
        point = points[(start + (reverse ? ilen - i : i)) % count];
        if (point.skip) {
          continue;
        } else if (move) {
          ctx.moveTo(point.x, point.y);
          move = false;
        } else {
          lineMethod(ctx, prev, point, reverse, options.stepped);
        }
        prev = point;
      }
      if (loop) {
        point = points[(start + (reverse ? ilen : 0)) % count];
        lineMethod(ctx, prev, point, reverse, options.stepped);
      }
      return !!loop;
    }
    function fastPathSegment(ctx, line, segment, params) {
      const points = line.points;
      const {count, start, ilen} = pathVars(points, segment, params);
      const {move = true, reverse} = params || {};
      let avgX = 0;
      let countX = 0;
      let i, point, prevX, minY, maxY, lastY;
      const pointIndex = (index) => (start + (reverse ? ilen - index : index)) % count;
      const drawX = () => {
        if (minY !== maxY) {
          ctx.lineTo(avgX, maxY);
          ctx.lineTo(avgX, minY);
          ctx.lineTo(avgX, lastY);
        }
      };
      if (move) {
        point = points[pointIndex(0)];
        ctx.moveTo(point.x, point.y);
      }
      for (i = 0; i <= ilen; ++i) {
        point = points[pointIndex(i)];
        if (point.skip) {
          continue;
        }
        const x = point.x;
        const y = point.y;
        const truncX = x | 0;
        if (truncX === prevX) {
          if (y < minY) {
            minY = y;
          } else if (y > maxY) {
            maxY = y;
          }
          avgX = (countX * avgX + x) / ++countX;
        } else {
          drawX();
          ctx.lineTo(x, y);
          prevX = truncX;
          countX = 0;
          minY = maxY = y;
        }
        lastY = y;
      }
      drawX();
    }
    function _getSegmentMethod(line) {
      const opts = line.options;
      const borderDash = opts.borderDash && opts.borderDash.length;
      const useFastPath = !line._decimated && !line._loop && !opts.tension && opts.cubicInterpolationMode !== 'monotone' && !opts.stepped && !borderDash;
      return useFastPath ? fastPathSegment : pathSegment;
    }
    function _getInterpolationMethod(options) {
      if (options.stepped) {
        return _steppedInterpolation;
      }
      if (options.tension || options.cubicInterpolationMode === 'monotone') {
        return _bezierInterpolation;
      }
      return _pointInLine;
    }
    function strokePathWithCache(ctx, line, start, count) {
      let path = line._path;
      if (!path) {
        path = line._path = new Path2D();
        if (line.path(path, start, count)) {
          path.closePath();
        }
      }
      setStyle(ctx, line.options);
      ctx.stroke(path);
    }
    function strokePathDirect(ctx, line, start, count) {
      const {segments, options} = line;
      const segmentMethod = _getSegmentMethod(line);
      for (const segment of segments) {
        setStyle(ctx, options, segment.style);
        ctx.beginPath();
        if (segmentMethod(ctx, line, segment, {start, end: start + count - 1})) {
          ctx.closePath();
        }
        ctx.stroke();
      }
    }
    const usePath2D = typeof Path2D === 'function';
    function draw(ctx, line, start, count) {
      if (usePath2D && line.segments.length === 1) {
        strokePathWithCache(ctx, line, start, count);
      } else {
        strokePathDirect(ctx, line, start, count);
      }
    }
    class LineElement extends Element {
      constructor(cfg) {
        super();
        this.animated = true;
        this.options = undefined;
        this._loop = undefined;
        this._fullLoop = undefined;
        this._path = undefined;
        this._points = undefined;
        this._segments = undefined;
        this._decimated = false;
        this._pointsUpdated = false;
        this._datasetIndex = undefined;
        if (cfg) {
          Object.assign(this, cfg);
        }
      }
      updateControlPoints(chartArea, indexAxis) {
        const me = this;
        const options = me.options;
        if ((options.tension || options.cubicInterpolationMode === 'monotone') && !options.stepped && !me._pointsUpdated) {
          const loop = options.spanGaps ? me._loop : me._fullLoop;
          _updateBezierControlPoints(me._points, options, chartArea, loop, indexAxis);
          me._pointsUpdated = true;
        }
      }
      set points(points) {
        const me = this;
        me._points = points;
        delete me._segments;
        delete me._path;
        me._pointsUpdated = false;
      }
      get points() {
        return this._points;
      }
      get segments() {
        return this._segments || (this._segments = _computeSegments(this, this.options.segment));
      }
      first() {
        const segments = this.segments;
        const points = this.points;
        return segments.length && points[segments[0].start];
      }
      last() {
        const segments = this.segments;
        const points = this.points;
        const count = segments.length;
        return count && points[segments[count - 1].end];
      }
      interpolate(point, property) {
        const me = this;
        const options = me.options;
        const value = point[property];
        const points = me.points;
        const segments = _boundSegments(me, {property, start: value, end: value});
        if (!segments.length) {
          return;
        }
        const result = [];
        const _interpolate = _getInterpolationMethod(options);
        let i, ilen;
        for (i = 0, ilen = segments.length; i < ilen; ++i) {
          const {start, end} = segments[i];
          const p1 = points[start];
          const p2 = points[end];
          if (p1 === p2) {
            result.push(p1);
            continue;
          }
          const t = Math.abs((value - p1[property]) / (p2[property] - p1[property]));
          const interpolated = _interpolate(p1, p2, t, options.stepped);
          interpolated[property] = point[property];
          result.push(interpolated);
        }
        return result.length === 1 ? result[0] : result;
      }
      pathSegment(ctx, segment, params) {
        const segmentMethod = _getSegmentMethod(this);
        return segmentMethod(ctx, this, segment, params);
      }
      path(ctx, start, count) {
        const me = this;
        const segments = me.segments;
        const segmentMethod = _getSegmentMethod(me);
        let loop = me._loop;
        start = start || 0;
        count = count || (me.points.length - start);
        for (const segment of segments) {
          loop &= segmentMethod(ctx, me, segment, {start, end: start + count - 1});
        }
        return !!loop;
      }
      draw(ctx, chartArea, start, count) {
        const me = this;
        const options = me.options || {};
        const points = me.points || [];
        if (!points.length || !options.borderWidth) {
          return;
        }
        ctx.save();
        draw(ctx, me, start, count);
        ctx.restore();
        if (me.animated) {
          me._pointsUpdated = false;
          me._path = undefined;
        }
      }
    }
    LineElement.id = 'line';
    LineElement.defaults = {
      borderCapStyle: 'butt',
      borderDash: [],
      borderDashOffset: 0,
      borderJoinStyle: 'miter',
      borderWidth: 3,
      capBezierPoints: true,
      cubicInterpolationMode: 'default',
      fill: false,
      spanGaps: false,
      stepped: false,
      tension: 0,
    };
    LineElement.defaultRoutes = {
      backgroundColor: 'backgroundColor',
      borderColor: 'borderColor'
    };
    LineElement.descriptors = {
      _scriptable: true,
      _indexable: (name) => name !== 'borderDash' && name !== 'fill',
    };
    
    function inRange$1(el, pos, axis, useFinalPosition) {
      const options = el.options;
      const {[axis]: value} = el.getProps([axis], useFinalPosition);
      return (Math.abs(pos - value) < options.radius + options.hitRadius);
    }
    class PointElement extends Element {
      constructor(cfg) {
        super();
        this.options = undefined;
        this.parsed = undefined;
        this.skip = undefined;
        this.stop = undefined;
        if (cfg) {
          Object.assign(this, cfg);
        }
      }
      inRange(mouseX, mouseY, useFinalPosition) {
        const options = this.options;
        const {x, y} = this.getProps(['x', 'y'], useFinalPosition);
        return ((Math.pow(mouseX - x, 2) + Math.pow(mouseY - y, 2)) < Math.pow(options.hitRadius + options.radius, 2));
      }
      inXRange(mouseX, useFinalPosition) {
        return inRange$1(this, mouseX, 'x', useFinalPosition);
      }
      inYRange(mouseY, useFinalPosition) {
        return inRange$1(this, mouseY, 'y', useFinalPosition);
      }
      getCenterPoint(useFinalPosition) {
        const {x, y} = this.getProps(['x', 'y'], useFinalPosition);
        return {x, y};
      }
      size(options) {
        options = options || this.options || {};
        let radius = options.radius || 0;
        radius = Math.max(radius, radius && options.hoverRadius || 0);
        const borderWidth = radius && options.borderWidth || 0;
        return (radius + borderWidth) * 2;
      }
      draw(ctx, area) {
        const me = this;
        const options = me.options;
        if (me.skip || options.radius < 0.1 || !_isPointInArea(me, area, me.size(options) / 2)) {
          return;
        }
        ctx.strokeStyle = options.borderColor;
        ctx.lineWidth = options.borderWidth;
        ctx.fillStyle = options.backgroundColor;
        drawPoint(ctx, options, me.x, me.y);
      }
      getRange() {
        const options = this.options || {};
        return options.radius + options.hitRadius;
      }
    }
    PointElement.id = 'point';
    PointElement.defaults = {
      borderWidth: 1,
      hitRadius: 1,
      hoverBorderWidth: 1,
      hoverRadius: 4,
      pointStyle: 'circle',
      radius: 3,
      rotation: 0
    };
    PointElement.defaultRoutes = {
      backgroundColor: 'backgroundColor',
      borderColor: 'borderColor'
    };
    
    function getBarBounds(bar, useFinalPosition) {
      const {x, y, base, width, height} = bar.getProps(['x', 'y', 'base', 'width', 'height'], useFinalPosition);
      let left, right, top, bottom, half;
      if (bar.horizontal) {
        half = height / 2;
        left = Math.min(x, base);
        right = Math.max(x, base);
        top = y - half;
        bottom = y + half;
      } else {
        half = width / 2;
        left = x - half;
        right = x + half;
        top = Math.min(y, base);
        bottom = Math.max(y, base);
      }
      return {left, top, right, bottom};
    }
    function skipOrLimit(skip, value, min, max) {
      return skip ? 0 : _limitValue(value, min, max);
    }
    function parseBorderWidth(bar, maxW, maxH) {
      const value = bar.options.borderWidth;
      const skip = bar.borderSkipped;
      const o = toTRBL(value);
      return {
        t: skipOrLimit(skip.top, o.top, 0, maxH),
        r: skipOrLimit(skip.right, o.right, 0, maxW),
        b: skipOrLimit(skip.bottom, o.bottom, 0, maxH),
        l: skipOrLimit(skip.left, o.left, 0, maxW)
      };
    }
    function parseBorderRadius(bar, maxW, maxH) {
      const {enableBorderRadius} = bar.getProps(['enableBorderRadius']);
      const value = bar.options.borderRadius;
      const o = toTRBLCorners(value);
      const maxR = Math.min(maxW, maxH);
      const skip = bar.borderSkipped;
      const enableBorder = enableBorderRadius || isObject(value);
      return {
        topLeft: skipOrLimit(!enableBorder || skip.top || skip.left, o.topLeft, 0, maxR),
        topRight: skipOrLimit(!enableBorder || skip.top || skip.right, o.topRight, 0, maxR),
        bottomLeft: skipOrLimit(!enableBorder || skip.bottom || skip.left, o.bottomLeft, 0, maxR),
        bottomRight: skipOrLimit(!enableBorder || skip.bottom || skip.right, o.bottomRight, 0, maxR)
      };
    }
    function boundingRects(bar) {
      const bounds = getBarBounds(bar);
      const width = bounds.right - bounds.left;
      const height = bounds.bottom - bounds.top;
      const border = parseBorderWidth(bar, width / 2, height / 2);
      const radius = parseBorderRadius(bar, width / 2, height / 2);
      return {
        outer: {
          x: bounds.left,
          y: bounds.top,
          w: width,
          h: height,
          radius
        },
        inner: {
          x: bounds.left + border.l,
          y: bounds.top + border.t,
          w: width - border.l - border.r,
          h: height - border.t - border.b,
          radius: {
            topLeft: Math.max(0, radius.topLeft - Math.max(border.t, border.l)),
            topRight: Math.max(0, radius.topRight - Math.max(border.t, border.r)),
            bottomLeft: Math.max(0, radius.bottomLeft - Math.max(border.b, border.l)),
            bottomRight: Math.max(0, radius.bottomRight - Math.max(border.b, border.r)),
          }
        }
      };
    }
    function inRange(bar, x, y, useFinalPosition) {
      const skipX = x === null;
      const skipY = y === null;
      const skipBoth = skipX && skipY;
      const bounds = bar && !skipBoth && getBarBounds(bar, useFinalPosition);
      return bounds
            && (skipX || x >= bounds.left && x <= bounds.right)
            && (skipY || y >= bounds.top && y <= bounds.bottom);
    }
    function hasRadius(radius) {
      return radius.topLeft || radius.topRight || radius.bottomLeft || radius.bottomRight;
    }
    function addNormalRectPath(ctx, rect) {
      ctx.rect(rect.x, rect.y, rect.w, rect.h);
    }
    function inflateRect(rect, amount, refRect = {}) {
      const x = rect.x !== refRect.x ? -amount : 0;
      const y = rect.y !== refRect.y ? -amount : 0;
      const w = (rect.x + rect.w !== refRect.x + refRect.w ? amount : 0) - x;
      const h = (rect.y + rect.h !== refRect.y + refRect.h ? amount : 0) - y;
      return {
        x: rect.x + x,
        y: rect.y + y,
        w: rect.w + w,
        h: rect.h + h,
        radius: rect.radius
      };
    }
    class BarElement extends Element {
      constructor(cfg) {
        super();
        this.options = undefined;
        this.horizontal = undefined;
        this.base = undefined;
        this.width = undefined;
        this.height = undefined;
        if (cfg) {
          Object.assign(this, cfg);
        }
      }
      draw(ctx) {
        const options = this.options;
        const {inner, outer} = boundingRects(this);
        const addRectPath = hasRadius(outer.radius) ? addRoundedRectPath : addNormalRectPath;
        const inflateAmount = 0.33;
        ctx.save();
        if (outer.w !== inner.w || outer.h !== inner.h) {
          ctx.beginPath();
          addRectPath(ctx, inflateRect(outer, inflateAmount, inner));
          ctx.clip();
          addRectPath(ctx, inflateRect(inner, -inflateAmount, outer));
          ctx.fillStyle = options.borderColor;
          ctx.fill('evenodd');
        }
        ctx.beginPath();
        addRectPath(ctx, inflateRect(inner, inflateAmount, outer));
        ctx.fillStyle = options.backgroundColor;
        ctx.fill();
        ctx.restore();
      }
      inRange(mouseX, mouseY, useFinalPosition) {
        return inRange(this, mouseX, mouseY, useFinalPosition);
      }
      inXRange(mouseX, useFinalPosition) {
        return inRange(this, mouseX, null, useFinalPosition);
      }
      inYRange(mouseY, useFinalPosition) {
        return inRange(this, null, mouseY, useFinalPosition);
      }
      getCenterPoint(useFinalPosition) {
        const {x, y, base, horizontal} = this.getProps(['x', 'y', 'base', 'horizontal'], useFinalPosition);
        return {
          x: horizontal ? (x + base) / 2 : x,
          y: horizontal ? y : (y + base) / 2
        };
      }
      getRange(axis) {
        return axis === 'x' ? this.width / 2 : this.height / 2;
      }
    }
    BarElement.id = 'bar';
    BarElement.defaults = {
      borderSkipped: 'start',
      borderWidth: 0,
      borderRadius: 0,
      enableBorderRadius: true,
      pointStyle: undefined
    };
    BarElement.defaultRoutes = {
      backgroundColor: 'backgroundColor',
      borderColor: 'borderColor'
    };
    
    var elements = /*#__PURE__*/Object.freeze({
    __proto__: null,
    ArcElement: ArcElement,
    LineElement: LineElement,
    PointElement: PointElement,
    BarElement: BarElement
    });
    
    function lttbDecimation(data, start, count, availableWidth, options) {
      const samples = options.samples || availableWidth;
      if (samples >= count) {
        return data.slice(start, start + count);
      }
      const decimated = [];
      const bucketWidth = (count - 2) / (samples - 2);
      let sampledIndex = 0;
      const endIndex = start + count - 1;
      let a = start;
      let i, maxAreaPoint, maxArea, area, nextA;
      decimated[sampledIndex++] = data[a];
      for (i = 0; i < samples - 2; i++) {
        let avgX = 0;
        let avgY = 0;
        let j;
        const avgRangeStart = Math.floor((i + 1) * bucketWidth) + 1 + start;
        const avgRangeEnd = Math.min(Math.floor((i + 2) * bucketWidth) + 1, count) + start;
        const avgRangeLength = avgRangeEnd - avgRangeStart;
        for (j = avgRangeStart; j < avgRangeEnd; j++) {
          avgX += data[j].x;
          avgY += data[j].y;
        }
        avgX /= avgRangeLength;
        avgY /= avgRangeLength;
        const rangeOffs = Math.floor(i * bucketWidth) + 1 + start;
        const rangeTo = Math.min(Math.floor((i + 1) * bucketWidth) + 1, count) + start;
        const {x: pointAx, y: pointAy} = data[a];
        maxArea = area = -1;
        for (j = rangeOffs; j < rangeTo; j++) {
          area = 0.5 * Math.abs(
            (pointAx - avgX) * (data[j].y - pointAy) -
            (pointAx - data[j].x) * (avgY - pointAy)
          );
          if (area > maxArea) {
            maxArea = area;
            maxAreaPoint = data[j];
            nextA = j;
          }
        }
        decimated[sampledIndex++] = maxAreaPoint;
        a = nextA;
      }
      decimated[sampledIndex++] = data[endIndex];
      return decimated;
    }
    function minMaxDecimation(data, start, count, availableWidth) {
      let avgX = 0;
      let countX = 0;
      let i, point, x, y, prevX, minIndex, maxIndex, startIndex, minY, maxY;
      const decimated = [];
      const endIndex = start + count - 1;
      const xMin = data[start].x;
      const xMax = data[endIndex].x;
      const dx = xMax - xMin;
      for (i = start; i < start + count; ++i) {
        point = data[i];
        x = (point.x - xMin) / dx * availableWidth;
        y = point.y;
        const truncX = x | 0;
        if (truncX === prevX) {
          if (y < minY) {
            minY = y;
            minIndex = i;
          } else if (y > maxY) {
            maxY = y;
            maxIndex = i;
          }
          avgX = (countX * avgX + point.x) / ++countX;
        } else {
          const lastIndex = i - 1;
          if (!isNullOrUndef(minIndex) && !isNullOrUndef(maxIndex)) {
            const intermediateIndex1 = Math.min(minIndex, maxIndex);
            const intermediateIndex2 = Math.max(minIndex, maxIndex);
            if (intermediateIndex1 !== startIndex && intermediateIndex1 !== lastIndex) {
              decimated.push({
                ...data[intermediateIndex1],
                x: avgX,
              });
            }
            if (intermediateIndex2 !== startIndex && intermediateIndex2 !== lastIndex) {
              decimated.push({
                ...data[intermediateIndex2],
                x: avgX
              });
            }
          }
          if (i > 0 && lastIndex !== startIndex) {
            decimated.push(data[lastIndex]);
          }
          decimated.push(point);
          prevX = truncX;
          countX = 0;
          minY = maxY = y;
          minIndex = maxIndex = startIndex = i;
        }
      }
      return decimated;
    }
    function cleanDecimatedDataset(dataset) {
      if (dataset._decimated) {
        const data = dataset._data;
        delete dataset._decimated;
        delete dataset._data;
        Object.defineProperty(dataset, 'data', {value: data});
      }
    }
    function cleanDecimatedData(chart) {
      chart.data.datasets.forEach((dataset) => {
        cleanDecimatedDataset(dataset);
      });
    }
    function getStartAndCountOfVisiblePointsSimplified(meta, points) {
      const pointCount = points.length;
      let start = 0;
      let count;
      const {iScale} = meta;
      const {min, max, minDefined, maxDefined} = iScale.getUserBounds();
      if (minDefined) {
        start = _limitValue(_lookupByKey(points, iScale.axis, min).lo, 0, pointCount - 1);
      }
      if (maxDefined) {
        count = _limitValue(_lookupByKey(points, iScale.axis, max).hi + 1, start, pointCount) - start;
      } else {
        count = pointCount - start;
      }
      return {start, count};
    }
    var plugin_decimation = {
      id: 'decimation',
      defaults: {
        algorithm: 'min-max',
        enabled: false,
      },
      beforeElementsUpdate: (chart, args, options) => {
        if (!options.enabled) {
          cleanDecimatedData(chart);
          return;
        }
        const availableWidth = chart.width;
        chart.data.datasets.forEach((dataset, datasetIndex) => {
          const {_data, indexAxis} = dataset;
          const meta = chart.getDatasetMeta(datasetIndex);
          const data = _data || dataset.data;
          if (resolve([indexAxis, chart.options.indexAxis]) === 'y') {
            return;
          }
          if (meta.type !== 'line') {
            return;
          }
          const xAxis = chart.scales[meta.xAxisID];
          if (xAxis.type !== 'linear' && xAxis.type !== 'time') {
            return;
          }
          if (chart.options.parsing) {
            return;
          }
          let {start, count} = getStartAndCountOfVisiblePointsSimplified(meta, data);
          const threshold = options.threshold || 4 * availableWidth;
          if (count <= threshold) {
            cleanDecimatedDataset(dataset);
            return;
          }
          if (isNullOrUndef(_data)) {
            dataset._data = data;
            delete dataset.data;
            Object.defineProperty(dataset, 'data', {
              configurable: true,
              enumerable: true,
              get: function() {
                return this._decimated;
              },
              set: function(d) {
                this._data = d;
              }
            });
          }
          let decimated;
          switch (options.algorithm) {
          case 'lttb':
            decimated = lttbDecimation(data, start, count, availableWidth, options);
            break;
          case 'min-max':
            decimated = minMaxDecimation(data, start, count, availableWidth);
            break;
          default:
            throw new Error(`Unsupported decimation algorithm '${options.algorithm}'`);
          }
          dataset._decimated = decimated;
        });
      },
      destroy(chart) {
        cleanDecimatedData(chart);
      }
    };
    
    function getLineByIndex(chart, index) {
      const meta = chart.getDatasetMeta(index);
      const visible = meta && chart.isDatasetVisible(index);
      return visible ? meta.dataset : null;
    }
    function parseFillOption(line) {
      const options = line.options;
      const fillOption = options.fill;
      let fill = valueOrDefault(fillOption && fillOption.target, fillOption);
      if (fill === undefined) {
        fill = !!options.backgroundColor;
      }
      if (fill === false || fill === null) {
        return false;
      }
      if (fill === true) {
        return 'origin';
      }
      return fill;
    }
    function decodeFill(line, index, count) {
      const fill = parseFillOption(line);
      if (isObject(fill)) {
        return isNaN(fill.value) ? false : fill;
      }
      let target = parseFloat(fill);
      if (isNumberFinite(target) && Math.floor(target) === target) {
        if (fill[0] === '-' || fill[0] === '+') {
          target = index + target;
        }
        if (target === index || target < 0 || target >= count) {
          return false;
        }
        return target;
      }
      return ['origin', 'start', 'end', 'stack', 'shape'].indexOf(fill) >= 0 && fill;
    }
    function computeLinearBoundary(source) {
      const {scale = {}, fill} = source;
      let target = null;
      let horizontal;
      if (fill === 'start') {
        target = scale.bottom;
      } else if (fill === 'end') {
        target = scale.top;
      } else if (isObject(fill)) {
        target = scale.getPixelForValue(fill.value);
      } else if (scale.getBasePixel) {
        target = scale.getBasePixel();
      }
      if (isNumberFinite(target)) {
        horizontal = scale.isHorizontal();
        return {
          x: horizontal ? target : null,
          y: horizontal ? null : target
        };
      }
      return null;
    }
    class simpleArc {
      constructor(opts) {
        this.x = opts.x;
        this.y = opts.y;
        this.radius = opts.radius;
      }
      pathSegment(ctx, bounds, opts) {
        const {x, y, radius} = this;
        bounds = bounds || {start: 0, end: TAU};
        ctx.arc(x, y, radius, bounds.end, bounds.start, true);
        return !opts.bounds;
      }
      interpolate(point) {
        const {x, y, radius} = this;
        const angle = point.angle;
        return {
          x: x + Math.cos(angle) * radius,
          y: y + Math.sin(angle) * radius,
          angle
        };
      }
    }
    function computeCircularBoundary(source) {
      const {scale, fill} = source;
      const options = scale.options;
      const length = scale.getLabels().length;
      const target = [];
      const start = options.reverse ? scale.max : scale.min;
      const end = options.reverse ? scale.min : scale.max;
      let i, center, value;
      if (fill === 'start') {
        value = start;
      } else if (fill === 'end') {
        value = end;
      } else if (isObject(fill)) {
        value = fill.value;
      } else {
        value = scale.getBaseValue();
      }
      if (options.grid.circular) {
        center = scale.getPointPositionForValue(0, start);
        return new simpleArc({
          x: center.x,
          y: center.y,
          radius: scale.getDistanceFromCenterForValue(value)
        });
      }
      for (i = 0; i < length; ++i) {
        target.push(scale.getPointPositionForValue(i, value));
      }
      return target;
    }
    function computeBoundary(source) {
      const scale = source.scale || {};
      if (scale.getPointPositionForValue) {
        return computeCircularBoundary(source);
      }
      return computeLinearBoundary(source);
    }
    function findSegmentEnd(start, end, points) {
      for (;end > start; end--) {
        const point = points[end];
        if (!isNaN(point.x) && !isNaN(point.y)) {
          break;
        }
      }
      return end;
    }
    function pointsFromSegments(boundary, line) {
      const {x = null, y = null} = boundary || {};
      const linePoints = line.points;
      const points = [];
      line.segments.forEach(({start, end}) => {
        end = findSegmentEnd(start, end, linePoints);
        const first = linePoints[start];
        const last = linePoints[end];
        if (y !== null) {
          points.push({x: first.x, y});
          points.push({x: last.x, y});
        } else if (x !== null) {
          points.push({x, y: first.y});
          points.push({x, y: last.y});
        }
      });
      return points;
    }
    function buildStackLine(source) {
      const {chart, scale, index, line} = source;
      const points = [];
      const segments = line.segments;
      const sourcePoints = line.points;
      const linesBelow = getLinesBelow(chart, index);
      linesBelow.push(createBoundaryLine({x: null, y: scale.bottom}, line));
      for (let i = 0; i < segments.length; i++) {
        const segment = segments[i];
        for (let j = segment.start; j <= segment.end; j++) {
          addPointsBelow(points, sourcePoints[j], linesBelow);
        }
      }
      return new LineElement({points, options: {}});
    }
    const isLineAndNotInHideAnimation = (meta) => meta.type === 'line' && !meta.hidden;
    function getLinesBelow(chart, index) {
      const below = [];
      const metas = chart.getSortedVisibleDatasetMetas();
      for (let i = 0; i < metas.length; i++) {
        const meta = metas[i];
        if (meta.index === index) {
          break;
        }
        if (isLineAndNotInHideAnimation(meta)) {
          below.unshift(meta.dataset);
        }
      }
      return below;
    }
    function addPointsBelow(points, sourcePoint, linesBelow) {
      const postponed = [];
      for (let j = 0; j < linesBelow.length; j++) {
        const line = linesBelow[j];
        const {first, last, point} = findPoint(line, sourcePoint, 'x');
        if (!point || (first && last)) {
          continue;
        }
        if (first) {
          postponed.unshift(point);
        } else {
          points.push(point);
          if (!last) {
            break;
          }
        }
      }
      points.push(...postponed);
    }
    function findPoint(line, sourcePoint, property) {
      const point = line.interpolate(sourcePoint, property);
      if (!point) {
        return {};
      }
      const pointValue = point[property];
      const segments = line.segments;
      const linePoints = line.points;
      let first = false;
      let last = false;
      for (let i = 0; i < segments.length; i++) {
        const segment = segments[i];
        const firstValue = linePoints[segment.start][property];
        const lastValue = linePoints[segment.end][property];
        if (pointValue >= firstValue && pointValue <= lastValue) {
          first = pointValue === firstValue;
          last = pointValue === lastValue;
          break;
        }
      }
      return {first, last, point};
    }
    function getTarget(source) {
      const {chart, fill, line} = source;
      if (isNumberFinite(fill)) {
        return getLineByIndex(chart, fill);
      }
      if (fill === 'stack') {
        return buildStackLine(source);
      }
      if (fill === 'shape') {
        return true;
      }
      const boundary = computeBoundary(source);
      if (boundary instanceof simpleArc) {
        return boundary;
      }
      return createBoundaryLine(boundary, line);
    }
    function createBoundaryLine(boundary, line) {
      let points = [];
      let _loop = false;
      if (isArray(boundary)) {
        _loop = true;
        points = boundary;
      } else {
        points = pointsFromSegments(boundary, line);
      }
      return points.length ? new LineElement({
        points,
        options: {tension: 0},
        _loop,
        _fullLoop: _loop
      }) : null;
    }
    function resolveTarget(sources, index, propagate) {
      const source = sources[index];
      let fill = source.fill;
      const visited = [index];
      let target;
      if (!propagate) {
        return fill;
      }
      while (fill !== false && visited.indexOf(fill) === -1) {
        if (!isNumberFinite(fill)) {
          return fill;
        }
        target = sources[fill];
        if (!target) {
          return false;
        }
        if (target.visible) {
          return fill;
        }
        visited.push(fill);
        fill = target.fill;
      }
      return false;
    }
    function _clip(ctx, target, clipY) {
      ctx.beginPath();
      target.path(ctx);
      ctx.lineTo(target.last().x, clipY);
      ctx.lineTo(target.first().x, clipY);
      ctx.closePath();
      ctx.clip();
    }
    function getBounds(property, first, last, loop) {
      if (loop) {
        return;
      }
      let start = first[property];
      let end = last[property];
      if (property === 'angle') {
        start = _normalizeAngle(start);
        end = _normalizeAngle(end);
      }
      return {property, start, end};
    }
    function _getEdge(a, b, prop, fn) {
      if (a && b) {
        return fn(a[prop], b[prop]);
      }
      return a ? a[prop] : b ? b[prop] : 0;
    }
    function _segments(line, target, property) {
      const segments = line.segments;
      const points = line.points;
      const tpoints = target.points;
      const parts = [];
      for (const segment of segments) {
        let {start, end} = segment;
        end = findSegmentEnd(start, end, points);
        const bounds = getBounds(property, points[start], points[end], segment.loop);
        if (!target.segments) {
          parts.push({
            source: segment,
            target: bounds,
            start: points[start],
            end: points[end]
          });
          continue;
        }
        const targetSegments = _boundSegments(target, bounds);
        for (const tgt of targetSegments) {
          const subBounds = getBounds(property, tpoints[tgt.start], tpoints[tgt.end], tgt.loop);
          const fillSources = _boundSegment(segment, points, subBounds);
          for (const fillSource of fillSources) {
            parts.push({
              source: fillSource,
              target: tgt,
              start: {
                [property]: _getEdge(bounds, subBounds, 'start', Math.max)
              },
              end: {
                [property]: _getEdge(bounds, subBounds, 'end', Math.min)
              }
            });
          }
        }
      }
      return parts;
    }
    function clipBounds(ctx, scale, bounds) {
      const {top, bottom} = scale.chart.chartArea;
      const {property, start, end} = bounds || {};
      if (property === 'x') {
        ctx.beginPath();
        ctx.rect(start, top, end - start, bottom - top);
        ctx.clip();
      }
    }
    function interpolatedLineTo(ctx, target, point, property) {
      const interpolatedPoint = target.interpolate(point, property);
      if (interpolatedPoint) {
        ctx.lineTo(interpolatedPoint.x, interpolatedPoint.y);
      }
    }
    function _fill(ctx, cfg) {
      const {line, target, property, color, scale} = cfg;
      const segments = _segments(line, target, property);
      for (const {source: src, target: tgt, start, end} of segments) {
        const {style: {backgroundColor = color} = {}} = src;
        const notShape = target !== true;
        ctx.save();
        ctx.fillStyle = backgroundColor;
        clipBounds(ctx, scale, notShape && getBounds(property, start, end));
        ctx.beginPath();
        const lineLoop = !!line.pathSegment(ctx, src);
        let loop;
        if (notShape) {
          if (lineLoop) {
            ctx.closePath();
          } else {
            interpolatedLineTo(ctx, target, end, property);
          }
          const targetLoop = !!target.pathSegment(ctx, tgt, {move: lineLoop, reverse: true});
          loop = lineLoop && targetLoop;
          if (!loop) {
            interpolatedLineTo(ctx, target, start, property);
          }
        }
        ctx.closePath();
        ctx.fill(loop ? 'evenodd' : 'nonzero');
        ctx.restore();
      }
    }
    function doFill(ctx, cfg) {
      const {line, target, above, below, area, scale} = cfg;
      const property = line._loop ? 'angle' : cfg.axis;
      ctx.save();
      if (property === 'x' && below !== above) {
        _clip(ctx, target, area.top);
        _fill(ctx, {line, target, color: above, scale, property});
        ctx.restore();
        ctx.save();
        _clip(ctx, target, area.bottom);
      }
      _fill(ctx, {line, target, color: below, scale, property});
      ctx.restore();
    }
    function drawfill(ctx, source, area) {
      const target = getTarget(source);
      const {line, scale, axis} = source;
      const lineOpts = line.options;
      const fillOption = lineOpts.fill;
      const color = lineOpts.backgroundColor;
      const {above = color, below = color} = fillOption || {};
      if (target && line.points.length) {
        clipArea(ctx, area);
        doFill(ctx, {line, target, above, below, area, scale, axis});
        unclipArea(ctx);
      }
    }
    var plugin_filler = {
      id: 'filler',
      afterDatasetsUpdate(chart, _args, options) {
        const count = (chart.data.datasets || []).length;
        const sources = [];
        let meta, i, line, source;
        for (i = 0; i < count; ++i) {
          meta = chart.getDatasetMeta(i);
          line = meta.dataset;
          source = null;
          if (line && line.options && line instanceof LineElement) {
            source = {
              visible: chart.isDatasetVisible(i),
              index: i,
              fill: decodeFill(line, i, count),
              chart,
              axis: meta.controller.options.indexAxis,
              scale: meta.vScale,
              line,
            };
          }
          meta.$filler = source;
          sources.push(source);
        }
        for (i = 0; i < count; ++i) {
          source = sources[i];
          if (!source || source.fill === false) {
            continue;
          }
          source.fill = resolveTarget(sources, i, options.propagate);
        }
      },
      beforeDraw(chart, _args, options) {
        const draw = options.drawTime === 'beforeDraw';
        const metasets = chart.getSortedVisibleDatasetMetas();
        const area = chart.chartArea;
        for (let i = metasets.length - 1; i >= 0; --i) {
          const source = metasets[i].$filler;
          if (!source) {
            continue;
          }
          source.line.updateControlPoints(area, source.axis);
          if (draw) {
            drawfill(chart.ctx, source, area);
          }
        }
      },
      beforeDatasetsDraw(chart, _args, options) {
        if (options.drawTime !== 'beforeDatasetsDraw') {
          return;
        }
        const metasets = chart.getSortedVisibleDatasetMetas();
        for (let i = metasets.length - 1; i >= 0; --i) {
          const source = metasets[i].$filler;
          if (source) {
            drawfill(chart.ctx, source, chart.chartArea);
          }
        }
      },
      beforeDatasetDraw(chart, args, options) {
        const source = args.meta.$filler;
        if (!source || source.fill === false || options.drawTime !== 'beforeDatasetDraw') {
          return;
        }
        drawfill(chart.ctx, source, chart.chartArea);
      },
      defaults: {
        propagate: true,
        drawTime: 'beforeDatasetDraw'
      }
    };
    
    const getBoxSize = (labelOpts, fontSize) => {
      let {boxHeight = fontSize, boxWidth = fontSize} = labelOpts;
      if (labelOpts.usePointStyle) {
        boxHeight = Math.min(boxHeight, fontSize);
        boxWidth = Math.min(boxWidth, fontSize);
      }
      return {
        boxWidth,
        boxHeight,
        itemHeight: Math.max(fontSize, boxHeight)
      };
    };
    const itemsEqual = (a, b) => a !== null && b !== null && a.datasetIndex === b.datasetIndex && a.index === b.index;
    class Legend extends Element {
      constructor(config) {
        super();
        this._added = false;
        this.legendHitBoxes = [];
        this._hoveredItem = null;
        this.doughnutMode = false;
        this.chart = config.chart;
        this.options = config.options;
        this.ctx = config.ctx;
        this.legendItems = undefined;
        this.columnSizes = undefined;
        this.lineWidths = undefined;
        this.maxHeight = undefined;
        this.maxWidth = undefined;
        this.top = undefined;
        this.bottom = undefined;
        this.left = undefined;
        this.right = undefined;
        this.height = undefined;
        this.width = undefined;
        this._margins = undefined;
        this.position = undefined;
        this.weight = undefined;
        this.fullSize = undefined;
      }
      update(maxWidth, maxHeight, margins) {
        const me = this;
        me.maxWidth = maxWidth;
        me.maxHeight = maxHeight;
        me._margins = margins;
        me.setDimensions();
        me.buildLabels();
        me.fit();
      }
      setDimensions() {
        const me = this;
        if (me.isHorizontal()) {
          me.width = me.maxWidth;
          me.left = me._margins.left;
          me.right = me.width;
        } else {
          me.height = me.maxHeight;
          me.top = me._margins.top;
          me.bottom = me.height;
        }
      }
      buildLabels() {
        const me = this;
        const labelOpts = me.options.labels || {};
        let legendItems = callback(labelOpts.generateLabels, [me.chart], me) || [];
        if (labelOpts.filter) {
          legendItems = legendItems.filter((item) => labelOpts.filter(item, me.chart.data));
        }
        if (labelOpts.sort) {
          legendItems = legendItems.sort((a, b) => labelOpts.sort(a, b, me.chart.data));
        }
        if (me.options.reverse) {
          legendItems.reverse();
        }
        me.legendItems = legendItems;
      }
      fit() {
        const me = this;
        const {options, ctx} = me;
        if (!options.display) {
          me.width = me.height = 0;
          return;
        }
        const labelOpts = options.labels;
        const labelFont = toFont(labelOpts.font);
        const fontSize = labelFont.size;
        const titleHeight = me._computeTitleHeight();
        const {boxWidth, itemHeight} = getBoxSize(labelOpts, fontSize);
        let width, height;
        ctx.font = labelFont.string;
        if (me.isHorizontal()) {
          width = me.maxWidth;
          height = me._fitRows(titleHeight, fontSize, boxWidth, itemHeight) + 10;
        } else {
          height = me.maxHeight;
          width = me._fitCols(titleHeight, fontSize, boxWidth, itemHeight) + 10;
        }
        me.width = Math.min(width, options.maxWidth || me.maxWidth);
        me.height = Math.min(height, options.maxHeight || me.maxHeight);
      }
      _fitRows(titleHeight, fontSize, boxWidth, itemHeight) {
        const me = this;
        const {ctx, maxWidth, options: {labels: {padding}}} = me;
        const hitboxes = me.legendHitBoxes = [];
        const lineWidths = me.lineWidths = [0];
        const lineHeight = itemHeight + padding;
        let totalHeight = titleHeight;
        ctx.textAlign = 'left';
        ctx.textBaseline = 'middle';
        let row = -1;
        let top = -lineHeight;
        me.legendItems.forEach((legendItem, i) => {
          const itemWidth = boxWidth + (fontSize / 2) + ctx.measureText(legendItem.text).width;
          if (i === 0 || lineWidths[lineWidths.length - 1] + itemWidth + 2 * padding > maxWidth) {
            totalHeight += lineHeight;
            lineWidths[lineWidths.length - (i > 0 ? 0 : 1)] = 0;
            top += lineHeight;
            row++;
          }
          hitboxes[i] = {left: 0, top, row, width: itemWidth, height: itemHeight};
          lineWidths[lineWidths.length - 1] += itemWidth + padding;
        });
        return totalHeight;
      }
      _fitCols(titleHeight, fontSize, boxWidth, itemHeight) {
        const me = this;
        const {ctx, maxHeight, options: {labels: {padding}}} = me;
        const hitboxes = me.legendHitBoxes = [];
        const columnSizes = me.columnSizes = [];
        const heightLimit = maxHeight - titleHeight;
        let totalWidth = padding;
        let currentColWidth = 0;
        let currentColHeight = 0;
        let left = 0;
        let col = 0;
        me.legendItems.forEach((legendItem, i) => {
          const itemWidth = boxWidth + (fontSize / 2) + ctx.measureText(legendItem.text).width;
          if (i > 0 && currentColHeight + itemHeight + 2 * padding > heightLimit) {
            totalWidth += currentColWidth + padding;
            columnSizes.push({width: currentColWidth, height: currentColHeight});
            left += currentColWidth + padding;
            col++;
            currentColWidth = currentColHeight = 0;
          }
          hitboxes[i] = {left, top: currentColHeight, col, width: itemWidth, height: itemHeight};
          currentColWidth = Math.max(currentColWidth, itemWidth);
          currentColHeight += itemHeight + padding;
        });
        totalWidth += currentColWidth;
        columnSizes.push({width: currentColWidth, height: currentColHeight});
        return totalWidth;
      }
      adjustHitBoxes() {
        const me = this;
        if (!me.options.display) {
          return;
        }
        const titleHeight = me._computeTitleHeight();
        const {legendHitBoxes: hitboxes, options: {align, labels: {padding}, rtl}} = me;
        const rtlHelper = getRtlAdapter(rtl, me.left, me.width);
        if (this.isHorizontal()) {
          let row = 0;
          let left = _alignStartEnd(align, me.left + padding, me.right - me.lineWidths[row]);
          for (const hitbox of hitboxes) {
            if (row !== hitbox.row) {
              row = hitbox.row;
              left = _alignStartEnd(align, me.left + padding, me.right - me.lineWidths[row]);
            }
            hitbox.top += me.top + titleHeight + padding;
            hitbox.left = rtlHelper.leftForLtr(rtlHelper.x(left), hitbox.width);
            left += hitbox.width + padding;
          }
        } else {
          let col = 0;
          let top = _alignStartEnd(align, me.top + titleHeight + padding, me.bottom - me.columnSizes[col].height);
          for (const hitbox of hitboxes) {
            if (hitbox.col !== col) {
              col = hitbox.col;
              top = _alignStartEnd(align, me.top + titleHeight + padding, me.bottom - me.columnSizes[col].height);
            }
            hitbox.top = top;
            hitbox.left += me.left + padding;
            hitbox.left = rtlHelper.leftForLtr(rtlHelper.x(hitbox.left), hitbox.width);
            top += hitbox.height + padding;
          }
        }
      }
      isHorizontal() {
        return this.options.position === 'top' || this.options.position === 'bottom';
      }
      draw() {
        const me = this;
        if (me.options.display) {
          const ctx = me.ctx;
          clipArea(ctx, me);
          me._draw();
          unclipArea(ctx);
        }
      }
      _draw() {
        const me = this;
        const {options: opts, columnSizes, lineWidths, ctx} = me;
        const {align, labels: labelOpts} = opts;
        const defaultColor = defaults.color;
        const rtlHelper = getRtlAdapter(opts.rtl, me.left, me.width);
        const labelFont = toFont(labelOpts.font);
        const {color: fontColor, padding} = labelOpts;
        const fontSize = labelFont.size;
        const halfFontSize = fontSize / 2;
        let cursor;
        me.drawTitle();
        ctx.textAlign = rtlHelper.textAlign('left');
        ctx.textBaseline = 'middle';
        ctx.lineWidth = 0.5;
        ctx.font = labelFont.string;
        const {boxWidth, boxHeight, itemHeight} = getBoxSize(labelOpts, fontSize);
        const drawLegendBox = function(x, y, legendItem) {
          if (isNaN(boxWidth) || boxWidth <= 0 || isNaN(boxHeight) || boxHeight < 0) {
            return;
          }
          ctx.save();
          const lineWidth = valueOrDefault(legendItem.lineWidth, 1);
          ctx.fillStyle = valueOrDefault(legendItem.fillStyle, defaultColor);
          ctx.lineCap = valueOrDefault(legendItem.lineCap, 'butt');
          ctx.lineDashOffset = valueOrDefault(legendItem.lineDashOffset, 0);
          ctx.lineJoin = valueOrDefault(legendItem.lineJoin, 'miter');
          ctx.lineWidth = lineWidth;
          ctx.strokeStyle = valueOrDefault(legendItem.strokeStyle, defaultColor);
          ctx.setLineDash(valueOrDefault(legendItem.lineDash, []));
          if (labelOpts.usePointStyle) {
            const drawOptions = {
              radius: boxWidth * Math.SQRT2 / 2,
              pointStyle: legendItem.pointStyle,
              rotation: legendItem.rotation,
              borderWidth: lineWidth
            };
            const centerX = rtlHelper.xPlus(x, boxWidth / 2);
            const centerY = y + halfFontSize;
            drawPoint(ctx, drawOptions, centerX, centerY);
          } else {
            const yBoxTop = y + Math.max((fontSize - boxHeight) / 2, 0);
            const xBoxLeft = rtlHelper.leftForLtr(x, boxWidth);
            const borderRadius = toTRBLCorners(legendItem.borderRadius);
            ctx.beginPath();
            if (Object.values(borderRadius).some(v => v !== 0)) {
              addRoundedRectPath(ctx, {
                x: xBoxLeft,
                y: yBoxTop,
                w: boxWidth,
                h: boxHeight,
                radius: borderRadius,
              });
            } else {
              ctx.rect(xBoxLeft, yBoxTop, boxWidth, boxHeight);
            }
            ctx.fill();
            if (lineWidth !== 0) {
              ctx.stroke();
            }
          }
          ctx.restore();
        };
        const fillText = function(x, y, legendItem) {
          renderText(ctx, legendItem.text, x, y + (itemHeight / 2), labelFont, {
            strikethrough: legendItem.hidden,
            textAlign: rtlHelper.textAlign(legendItem.textAlign)
          });
        };
        const isHorizontal = me.isHorizontal();
        const titleHeight = this._computeTitleHeight();
        if (isHorizontal) {
          cursor = {
            x: _alignStartEnd(align, me.left + padding, me.right - lineWidths[0]),
            y: me.top + padding + titleHeight,
            line: 0
          };
        } else {
          cursor = {
            x: me.left + padding,
            y: _alignStartEnd(align, me.top + titleHeight + padding, me.bottom - columnSizes[0].height),
            line: 0
          };
        }
        overrideTextDirection(me.ctx, opts.textDirection);
        const lineHeight = itemHeight + padding;
        me.legendItems.forEach((legendItem, i) => {
          ctx.strokeStyle = legendItem.fontColor || fontColor;
          ctx.fillStyle = legendItem.fontColor || fontColor;
          const textWidth = ctx.measureText(legendItem.text).width;
          const textAlign = rtlHelper.textAlign(legendItem.textAlign || (legendItem.textAlign = labelOpts.textAlign));
          const width = boxWidth + halfFontSize + textWidth;
          let x = cursor.x;
          let y = cursor.y;
          rtlHelper.setWidth(me.width);
          if (isHorizontal) {
            if (i > 0 && x + width + padding > me.right) {
              y = cursor.y += lineHeight;
              cursor.line++;
              x = cursor.x = _alignStartEnd(align, me.left + padding, me.right - lineWidths[cursor.line]);
            }
          } else if (i > 0 && y + lineHeight > me.bottom) {
            x = cursor.x = x + columnSizes[cursor.line].width + padding;
            cursor.line++;
            y = cursor.y = _alignStartEnd(align, me.top + titleHeight + padding, me.bottom - columnSizes[cursor.line].height);
          }
          const realX = rtlHelper.x(x);
          drawLegendBox(realX, y, legendItem);
          x = _textX(textAlign, x + boxWidth + halfFontSize, isHorizontal ? x + width : me.right, opts.rtl);
          fillText(rtlHelper.x(x), y, legendItem);
          if (isHorizontal) {
            cursor.x += width + padding;
          } else {
            cursor.y += lineHeight;
          }
        });
        restoreTextDirection(me.ctx, opts.textDirection);
      }
      drawTitle() {
        const me = this;
        const opts = me.options;
        const titleOpts = opts.title;
        const titleFont = toFont(titleOpts.font);
        const titlePadding = toPadding(titleOpts.padding);
        if (!titleOpts.display) {
          return;
        }
        const rtlHelper = getRtlAdapter(opts.rtl, me.left, me.width);
        const ctx = me.ctx;
        const position = titleOpts.position;
        const halfFontSize = titleFont.size / 2;
        const topPaddingPlusHalfFontSize = titlePadding.top + halfFontSize;
        let y;
        let left = me.left;
        let maxWidth = me.width;
        if (this.isHorizontal()) {
          maxWidth = Math.max(...me.lineWidths);
          y = me.top + topPaddingPlusHalfFontSize;
          left = _alignStartEnd(opts.align, left, me.right - maxWidth);
        } else {
          const maxHeight = me.columnSizes.reduce((acc, size) => Math.max(acc, size.height), 0);
          y = topPaddingPlusHalfFontSize + _alignStartEnd(opts.align, me.top, me.bottom - maxHeight - opts.labels.padding - me._computeTitleHeight());
        }
        const x = _alignStartEnd(position, left, left + maxWidth);
        ctx.textAlign = rtlHelper.textAlign(_toLeftRightCenter(position));
        ctx.textBaseline = 'middle';
        ctx.strokeStyle = titleOpts.color;
        ctx.fillStyle = titleOpts.color;
        ctx.font = titleFont.string;
        renderText(ctx, titleOpts.text, x, y, titleFont);
      }
      _computeTitleHeight() {
        const titleOpts = this.options.title;
        const titleFont = toFont(titleOpts.font);
        const titlePadding = toPadding(titleOpts.padding);
        return titleOpts.display ? titleFont.lineHeight + titlePadding.height : 0;
      }
      _getLegendItemAt(x, y) {
        const me = this;
        let i, hitBox, lh;
        if (x >= me.left && x <= me.right && y >= me.top && y <= me.bottom) {
          lh = me.legendHitBoxes;
          for (i = 0; i < lh.length; ++i) {
            hitBox = lh[i];
            if (x >= hitBox.left && x <= hitBox.left + hitBox.width && y >= hitBox.top && y <= hitBox.top + hitBox.height) {
              return me.legendItems[i];
            }
          }
        }
        return null;
      }
      handleEvent(e) {
        const me = this;
        const opts = me.options;
        if (!isListened(e.type, opts)) {
          return;
        }
        const hoveredItem = me._getLegendItemAt(e.x, e.y);
        if (e.type === 'mousemove') {
          const previous = me._hoveredItem;
          const sameItem = itemsEqual(previous, hoveredItem);
          if (previous && !sameItem) {
            callback(opts.onLeave, [e, previous, me], me);
          }
          me._hoveredItem = hoveredItem;
          if (hoveredItem && !sameItem) {
            callback(opts.onHover, [e, hoveredItem, me], me);
          }
        } else if (hoveredItem) {
          callback(opts.onClick, [e, hoveredItem, me], me);
        }
      }
    }
    function isListened(type, opts) {
      if (type === 'mousemove' && (opts.onHover || opts.onLeave)) {
        return true;
      }
      if (opts.onClick && (type === 'click' || type === 'mouseup')) {
        return true;
      }
      return false;
    }
    var plugin_legend = {
      id: 'legend',
      _element: Legend,
      start(chart, _args, options) {
        const legend = chart.legend = new Legend({ctx: chart.ctx, options, chart});
        layouts.configure(chart, legend, options);
        layouts.addBox(chart, legend);
      },
      stop(chart) {
        layouts.removeBox(chart, chart.legend);
        delete chart.legend;
      },
      beforeUpdate(chart, _args, options) {
        const legend = chart.legend;
        layouts.configure(chart, legend, options);
        legend.options = options;
      },
      afterUpdate(chart) {
        const legend = chart.legend;
        legend.buildLabels();
        legend.adjustHitBoxes();
      },
      afterEvent(chart, args) {
        if (!args.replay) {
          chart.legend.handleEvent(args.event);
        }
      },
      defaults: {
        display: true,
        position: 'top',
        align: 'center',
        fullSize: true,
        reverse: false,
        weight: 1000,
        onClick(e, legendItem, legend) {
          const index = legendItem.datasetIndex;
          const ci = legend.chart;
          if (ci.isDatasetVisible(index)) {
            ci.hide(index);
            legendItem.hidden = true;
          } else {
            ci.show(index);
            legendItem.hidden = false;
          }
        },
        onHover: null,
        onLeave: null,
        labels: {
          color: (ctx) => ctx.chart.options.color,
          boxWidth: 40,
          padding: 10,
          generateLabels(chart) {
            const datasets = chart.data.datasets;
            const {labels: {usePointStyle, pointStyle, textAlign, color}} = chart.legend.options;
            return chart._getSortedDatasetMetas().map((meta) => {
              const style = meta.controller.getStyle(usePointStyle ? 0 : undefined);
              const borderWidth = toPadding(style.borderWidth);
              return {
                text: datasets[meta.index].label,
                fillStyle: style.backgroundColor,
                fontColor: color,
                hidden: !meta.visible,
                lineCap: style.borderCapStyle,
                lineDash: style.borderDash,
                lineDashOffset: style.borderDashOffset,
                lineJoin: style.borderJoinStyle,
                lineWidth: (borderWidth.width + borderWidth.height) / 4,
                strokeStyle: style.borderColor,
                pointStyle: pointStyle || style.pointStyle,
                rotation: style.rotation,
                textAlign: textAlign || style.textAlign,
                borderRadius: 0,
                datasetIndex: meta.index
              };
            }, this);
          }
        },
        title: {
          color: (ctx) => ctx.chart.options.color,
          display: false,
          position: 'center',
          text: '',
        }
      },
      descriptors: {
        _scriptable: (name) => !name.startsWith('on'),
        labels: {
          _scriptable: (name) => !['generateLabels', 'filter', 'sort'].includes(name),
        }
      },
    };
    
    class Title extends Element {
      constructor(config) {
        super();
        this.chart = config.chart;
        this.options = config.options;
        this.ctx = config.ctx;
        this._padding = undefined;
        this.top = undefined;
        this.bottom = undefined;
        this.left = undefined;
        this.right = undefined;
        this.width = undefined;
        this.height = undefined;
        this.position = undefined;
        this.weight = undefined;
        this.fullSize = undefined;
      }
      update(maxWidth, maxHeight) {
        const me = this;
        const opts = me.options;
        me.left = 0;
        me.top = 0;
        if (!opts.display) {
          me.width = me.height = me.right = me.bottom = 0;
          return;
        }
        me.width = me.right = maxWidth;
        me.height = me.bottom = maxHeight;
        const lineCount = isArray(opts.text) ? opts.text.length : 1;
        me._padding = toPadding(opts.padding);
        const textSize = lineCount * toFont(opts.font).lineHeight + me._padding.height;
        if (me.isHorizontal()) {
          me.height = textSize;
        } else {
          me.width = textSize;
        }
      }
      isHorizontal() {
        const pos = this.options.position;
        return pos === 'top' || pos === 'bottom';
      }
      _drawArgs(offset) {
        const {top, left, bottom, right, options} = this;
        const align = options.align;
        let rotation = 0;
        let maxWidth, titleX, titleY;
        if (this.isHorizontal()) {
          titleX = _alignStartEnd(align, left, right);
          titleY = top + offset;
          maxWidth = right - left;
        } else {
          if (options.position === 'left') {
            titleX = left + offset;
            titleY = _alignStartEnd(align, bottom, top);
            rotation = PI * -0.5;
          } else {
            titleX = right - offset;
            titleY = _alignStartEnd(align, top, bottom);
            rotation = PI * 0.5;
          }
          maxWidth = bottom - top;
        }
        return {titleX, titleY, maxWidth, rotation};
      }
      draw() {
        const me = this;
        const ctx = me.ctx;
        const opts = me.options;
        if (!opts.display) {
          return;
        }
        const fontOpts = toFont(opts.font);
        const lineHeight = fontOpts.lineHeight;
        const offset = lineHeight / 2 + me._padding.top;
        const {titleX, titleY, maxWidth, rotation} = me._drawArgs(offset);
        renderText(ctx, opts.text, 0, 0, fontOpts, {
          color: opts.color,
          maxWidth,
          rotation,
          textAlign: _toLeftRightCenter(opts.align),
          textBaseline: 'middle',
          translation: [titleX, titleY],
        });
      }
    }
    function createTitle(chart, titleOpts) {
      const title = new Title({
        ctx: chart.ctx,
        options: titleOpts,
        chart
      });
      layouts.configure(chart, title, titleOpts);
      layouts.addBox(chart, title);
      chart.titleBlock = title;
    }
    var plugin_title = {
      id: 'title',
      _element: Title,
      start(chart, _args, options) {
        createTitle(chart, options);
      },
      stop(chart) {
        const titleBlock = chart.titleBlock;
        layouts.removeBox(chart, titleBlock);
        delete chart.titleBlock;
      },
      beforeUpdate(chart, _args, options) {
        const title = chart.titleBlock;
        layouts.configure(chart, title, options);
        title.options = options;
      },
      defaults: {
        align: 'center',
        display: false,
        font: {
          weight: 'bold',
        },
        fullSize: true,
        padding: 10,
        position: 'top',
        text: '',
        weight: 2000
      },
      defaultRoutes: {
        color: 'color'
      },
      descriptors: {
        _scriptable: true,
        _indexable: false,
      },
    };
    
    const map = new WeakMap();
    var plugin_subtitle = {
      id: 'subtitle',
      start(chart, _args, options) {
        const title = new Title({
          ctx: chart.ctx,
          options,
          chart
        });
        layouts.configure(chart, title, options);
        layouts.addBox(chart, title);
        map.set(chart, title);
      },
      stop(chart) {
        layouts.removeBox(chart, map.get(chart));
        map.delete(chart);
      },
      beforeUpdate(chart, _args, options) {
        const title = map.get(chart);
        layouts.configure(chart, title, options);
        title.options = options;
      },
      defaults: {
        align: 'center',
        display: false,
        font: {
          weight: 'normal',
        },
        fullSize: true,
        padding: 0,
        position: 'top',
        text: '',
        weight: 1500
      },
      defaultRoutes: {
        color: 'color'
      },
      descriptors: {
        _scriptable: true,
        _indexable: false,
      },
    };
    
    const positioners = {
      average(items) {
        if (!items.length) {
          return false;
        }
        let i, len;
        let x = 0;
        let y = 0;
        let count = 0;
        for (i = 0, len = items.length; i < len; ++i) {
          const el = items[i].element;
          if (el && el.hasValue()) {
            const pos = el.tooltipPosition();
            x += pos.x;
            y += pos.y;
            ++count;
          }
        }
        return {
          x: x / count,
          y: y / count
        };
      },
      nearest(items, eventPosition) {
        if (!items.length) {
          return false;
        }
        let x = eventPosition.x;
        let y = eventPosition.y;
        let minDistance = Number.POSITIVE_INFINITY;
        let i, len, nearestElement;
        for (i = 0, len = items.length; i < len; ++i) {
          const el = items[i].element;
          if (el && el.hasValue()) {
            const center = el.getCenterPoint();
            const d = distanceBetweenPoints(eventPosition, center);
            if (d < minDistance) {
              minDistance = d;
              nearestElement = el;
            }
          }
        }
        if (nearestElement) {
          const tp = nearestElement.tooltipPosition();
          x = tp.x;
          y = tp.y;
        }
        return {
          x,
          y
        };
      }
    };
    function pushOrConcat(base, toPush) {
      if (toPush) {
        if (isArray(toPush)) {
          Array.prototype.push.apply(base, toPush);
        } else {
          base.push(toPush);
        }
      }
      return base;
    }
    function splitNewlines(str) {
      if ((typeof str === 'string' || str instanceof String) && str.indexOf('\n') > -1) {
        return str.split('\n');
      }
      return str;
    }
    function createTooltipItem(chart, item) {
      const {element, datasetIndex, index} = item;
      const controller = chart.getDatasetMeta(datasetIndex).controller;
      const {label, value} = controller.getLabelAndValue(index);
      return {
        chart,
        label,
        parsed: controller.getParsed(index),
        raw: chart.data.datasets[datasetIndex].data[index],
        formattedValue: value,
        dataset: controller.getDataset(),
        dataIndex: index,
        datasetIndex,
        element
      };
    }
    function getTooltipSize(tooltip, options) {
      const ctx = tooltip._chart.ctx;
      const {body, footer, title} = tooltip;
      const {boxWidth, boxHeight} = options;
      const bodyFont = toFont(options.bodyFont);
      const titleFont = toFont(options.titleFont);
      const footerFont = toFont(options.footerFont);
      const titleLineCount = title.length;
      const footerLineCount = footer.length;
      const bodyLineItemCount = body.length;
      const padding = toPadding(options.padding);
      let height = padding.height;
      let width = 0;
      let combinedBodyLength = body.reduce((count, bodyItem) => count + bodyItem.before.length + bodyItem.lines.length + bodyItem.after.length, 0);
      combinedBodyLength += tooltip.beforeBody.length + tooltip.afterBody.length;
      if (titleLineCount) {
        height += titleLineCount * titleFont.lineHeight
                + (titleLineCount - 1) * options.titleSpacing
                + options.titleMarginBottom;
      }
      if (combinedBodyLength) {
        const bodyLineHeight = options.displayColors ? Math.max(boxHeight, bodyFont.lineHeight) : bodyFont.lineHeight;
        height += bodyLineItemCount * bodyLineHeight
                + (combinedBodyLength - bodyLineItemCount) * bodyFont.lineHeight
                + (combinedBodyLength - 1) * options.bodySpacing;
      }
      if (footerLineCount) {
        height += options.footerMarginTop
                + footerLineCount * footerFont.lineHeight
                + (footerLineCount - 1) * options.footerSpacing;
      }
      let widthPadding = 0;
      const maxLineWidth = function(line) {
        width = Math.max(width, ctx.measureText(line).width + widthPadding);
      };
      ctx.save();
      ctx.font = titleFont.string;
      each(tooltip.title, maxLineWidth);
      ctx.font = bodyFont.string;
      each(tooltip.beforeBody.concat(tooltip.afterBody), maxLineWidth);
      widthPadding = options.displayColors ? (boxWidth + 2) : 0;
      each(body, (bodyItem) => {
        each(bodyItem.before, maxLineWidth);
        each(bodyItem.lines, maxLineWidth);
        each(bodyItem.after, maxLineWidth);
      });
      widthPadding = 0;
      ctx.font = footerFont.string;
      each(tooltip.footer, maxLineWidth);
      ctx.restore();
      width += padding.width;
      return {width, height};
    }
    function determineYAlign(chart, size) {
      const {y, height} = size;
      if (y < height / 2) {
        return 'top';
      } else if (y > (chart.height - height / 2)) {
        return 'bottom';
      }
      return 'center';
    }
    function doesNotFitWithAlign(xAlign, chart, options, size) {
      const {x, width} = size;
      const caret = options.caretSize + options.caretPadding;
      if (xAlign === 'left' && x + width + caret > chart.width) {
        return true;
      }
      if (xAlign === 'right' && x - width - caret < 0) {
        return true;
      }
    }
    function determineXAlign(chart, options, size, yAlign) {
      const {x, width} = size;
      const {width: chartWidth, chartArea: {left, right}} = chart;
      let xAlign = 'center';
      if (yAlign === 'center') {
        xAlign = x <= (left + right) / 2 ? 'left' : 'right';
      } else if (x <= width / 2) {
        xAlign = 'left';
      } else if (x >= chartWidth - width / 2) {
        xAlign = 'right';
      }
      if (doesNotFitWithAlign(xAlign, chart, options, size)) {
        xAlign = 'center';
      }
      return xAlign;
    }
    function determineAlignment(chart, options, size) {
      const yAlign = options.yAlign || determineYAlign(chart, size);
      return {
        xAlign: options.xAlign || determineXAlign(chart, options, size, yAlign),
        yAlign
      };
    }
    function alignX(size, xAlign) {
      let {x, width} = size;
      if (xAlign === 'right') {
        x -= width;
      } else if (xAlign === 'center') {
        x -= (width / 2);
      }
      return x;
    }
    function alignY(size, yAlign, paddingAndSize) {
      let {y, height} = size;
      if (yAlign === 'top') {
        y += paddingAndSize;
      } else if (yAlign === 'bottom') {
        y -= height + paddingAndSize;
      } else {
        y -= (height / 2);
      }
      return y;
    }
    function getBackgroundPoint(options, size, alignment, chart) {
      const {caretSize, caretPadding, cornerRadius} = options;
      const {xAlign, yAlign} = alignment;
      const paddingAndSize = caretSize + caretPadding;
      const radiusAndPadding = cornerRadius + caretPadding;
      let x = alignX(size, xAlign);
      const y = alignY(size, yAlign, paddingAndSize);
      if (yAlign === 'center') {
        if (xAlign === 'left') {
          x += paddingAndSize;
        } else if (xAlign === 'right') {
          x -= paddingAndSize;
        }
      } else if (xAlign === 'left') {
        x -= radiusAndPadding;
      } else if (xAlign === 'right') {
        x += radiusAndPadding;
      }
      return {
        x: _limitValue(x, 0, chart.width - size.width),
        y: _limitValue(y, 0, chart.height - size.height)
      };
    }
    function getAlignedX(tooltip, align, options) {
      const padding = toPadding(options.padding);
      return align === 'center'
        ? tooltip.x + tooltip.width / 2
        : align === 'right'
          ? tooltip.x + tooltip.width - padding.right
          : tooltip.x + padding.left;
    }
    function getBeforeAfterBodyLines(callback) {
      return pushOrConcat([], splitNewlines(callback));
    }
    function createTooltipContext(parent, tooltip, tooltipItems) {
      return Object.assign(Object.create(parent), {
        tooltip,
        tooltipItems,
        type: 'tooltip'
      });
    }
    function overrideCallbacks(callbacks, context) {
      const override = context && context.dataset && context.dataset.tooltip && context.dataset.tooltip.callbacks;
      return override ? callbacks.override(override) : callbacks;
    }
    class Tooltip extends Element {
      constructor(config) {
        super();
        this.opacity = 0;
        this._active = [];
        this._chart = config._chart;
        this._eventPosition = undefined;
        this._size = undefined;
        this._cachedAnimations = undefined;
        this._tooltipItems = [];
        this.$animations = undefined;
        this.$context = undefined;
        this.options = config.options;
        this.dataPoints = undefined;
        this.title = undefined;
        this.beforeBody = undefined;
        this.body = undefined;
        this.afterBody = undefined;
        this.footer = undefined;
        this.xAlign = undefined;
        this.yAlign = undefined;
        this.x = undefined;
        this.y = undefined;
        this.height = undefined;
        this.width = undefined;
        this.caretX = undefined;
        this.caretY = undefined;
        this.labelColors = undefined;
        this.labelPointStyles = undefined;
        this.labelTextColors = undefined;
      }
      initialize(options) {
        this.options = options;
        this._cachedAnimations = undefined;
        this.$context = undefined;
      }
      _resolveAnimations() {
        const me = this;
        const cached = me._cachedAnimations;
        if (cached) {
          return cached;
        }
        const chart = me._chart;
        const options = me.options.setContext(me.getContext());
        const opts = options.enabled && chart.options.animation && options.animations;
        const animations = new Animations(me._chart, opts);
        if (opts._cacheable) {
          me._cachedAnimations = Object.freeze(animations);
        }
        return animations;
      }
      getContext() {
        const me = this;
        return me.$context ||
                (me.$context = createTooltipContext(me._chart.getContext(), me, me._tooltipItems));
      }
      getTitle(context, options) {
        const me = this;
        const {callbacks} = options;
        const beforeTitle = callbacks.beforeTitle.apply(me, [context]);
        const title = callbacks.title.apply(me, [context]);
        const afterTitle = callbacks.afterTitle.apply(me, [context]);
        let lines = [];
        lines = pushOrConcat(lines, splitNewlines(beforeTitle));
        lines = pushOrConcat(lines, splitNewlines(title));
        lines = pushOrConcat(lines, splitNewlines(afterTitle));
        return lines;
      }
      getBeforeBody(tooltipItems, options) {
        return getBeforeAfterBodyLines(options.callbacks.beforeBody.apply(this, [tooltipItems]));
      }
      getBody(tooltipItems, options) {
        const me = this;
        const {callbacks} = options;
        const bodyItems = [];
        each(tooltipItems, (context) => {
          const bodyItem = {
            before: [],
            lines: [],
            after: []
          };
          const scoped = overrideCallbacks(callbacks, context);
          pushOrConcat(bodyItem.before, splitNewlines(scoped.beforeLabel.call(me, context)));
          pushOrConcat(bodyItem.lines, scoped.label.call(me, context));
          pushOrConcat(bodyItem.after, splitNewlines(scoped.afterLabel.call(me, context)));
          bodyItems.push(bodyItem);
        });
        return bodyItems;
      }
      getAfterBody(tooltipItems, options) {
        return getBeforeAfterBodyLines(options.callbacks.afterBody.apply(this, [tooltipItems]));
      }
      getFooter(tooltipItems, options) {
        const me = this;
        const {callbacks} = options;
        const beforeFooter = callbacks.beforeFooter.apply(me, [tooltipItems]);
        const footer = callbacks.footer.apply(me, [tooltipItems]);
        const afterFooter = callbacks.afterFooter.apply(me, [tooltipItems]);
        let lines = [];
        lines = pushOrConcat(lines, splitNewlines(beforeFooter));
        lines = pushOrConcat(lines, splitNewlines(footer));
        lines = pushOrConcat(lines, splitNewlines(afterFooter));
        return lines;
      }
      _createItems(options) {
        const me = this;
        const active = me._active;
        const data = me._chart.data;
        const labelColors = [];
        const labelPointStyles = [];
        const labelTextColors = [];
        let tooltipItems = [];
        let i, len;
        for (i = 0, len = active.length; i < len; ++i) {
          tooltipItems.push(createTooltipItem(me._chart, active[i]));
        }
        if (options.filter) {
          tooltipItems = tooltipItems.filter((element, index, array) => options.filter(element, index, array, data));
        }
        if (options.itemSort) {
          tooltipItems = tooltipItems.sort((a, b) => options.itemSort(a, b, data));
        }
        each(tooltipItems, (context) => {
          const scoped = overrideCallbacks(options.callbacks, context);
          labelColors.push(scoped.labelColor.call(me, context));
          labelPointStyles.push(scoped.labelPointStyle.call(me, context));
          labelTextColors.push(scoped.labelTextColor.call(me, context));
        });
        me.labelColors = labelColors;
        me.labelPointStyles = labelPointStyles;
        me.labelTextColors = labelTextColors;
        me.dataPoints = tooltipItems;
        return tooltipItems;
      }
      update(changed, replay) {
        const me = this;
        const options = me.options.setContext(me.getContext());
        const active = me._active;
        let properties;
        let tooltipItems = [];
        if (!active.length) {
          if (me.opacity !== 0) {
            properties = {
              opacity: 0
            };
          }
        } else {
          const position = positioners[options.position].call(me, active, me._eventPosition);
          tooltipItems = me._createItems(options);
          me.title = me.getTitle(tooltipItems, options);
          me.beforeBody = me.getBeforeBody(tooltipItems, options);
          me.body = me.getBody(tooltipItems, options);
          me.afterBody = me.getAfterBody(tooltipItems, options);
          me.footer = me.getFooter(tooltipItems, options);
          const size = me._size = getTooltipSize(me, options);
          const positionAndSize = Object.assign({}, position, size);
          const alignment = determineAlignment(me._chart, options, positionAndSize);
          const backgroundPoint = getBackgroundPoint(options, positionAndSize, alignment, me._chart);
          me.xAlign = alignment.xAlign;
          me.yAlign = alignment.yAlign;
          properties = {
            opacity: 1,
            x: backgroundPoint.x,
            y: backgroundPoint.y,
            width: size.width,
            height: size.height,
            caretX: position.x,
            caretY: position.y
          };
        }
        me._tooltipItems = tooltipItems;
        me.$context = undefined;
        if (properties) {
          me._resolveAnimations().update(me, properties);
        }
        if (changed && options.external) {
          options.external.call(me, {chart: me._chart, tooltip: me, replay});
        }
      }
      drawCaret(tooltipPoint, ctx, size, options) {
        const caretPosition = this.getCaretPosition(tooltipPoint, size, options);
        ctx.lineTo(caretPosition.x1, caretPosition.y1);
        ctx.lineTo(caretPosition.x2, caretPosition.y2);
        ctx.lineTo(caretPosition.x3, caretPosition.y3);
      }
      getCaretPosition(tooltipPoint, size, options) {
        const {xAlign, yAlign} = this;
        const {cornerRadius, caretSize} = options;
        const {x: ptX, y: ptY} = tooltipPoint;
        const {width, height} = size;
        let x1, x2, x3, y1, y2, y3;
        if (yAlign === 'center') {
          y2 = ptY + (height / 2);
          if (xAlign === 'left') {
            x1 = ptX;
            x2 = x1 - caretSize;
            y1 = y2 + caretSize;
            y3 = y2 - caretSize;
          } else {
            x1 = ptX + width;
            x2 = x1 + caretSize;
            y1 = y2 - caretSize;
            y3 = y2 + caretSize;
          }
          x3 = x1;
        } else {
          if (xAlign === 'left') {
            x2 = ptX + cornerRadius + (caretSize);
          } else if (xAlign === 'right') {
            x2 = ptX + width - cornerRadius - caretSize;
          } else {
            x2 = this.caretX;
          }
          if (yAlign === 'top') {
            y1 = ptY;
            y2 = y1 - caretSize;
            x1 = x2 - caretSize;
            x3 = x2 + caretSize;
          } else {
            y1 = ptY + height;
            y2 = y1 + caretSize;
            x1 = x2 + caretSize;
            x3 = x2 - caretSize;
          }
          y3 = y1;
        }
        return {x1, x2, x3, y1, y2, y3};
      }
      drawTitle(pt, ctx, options) {
        const me = this;
        const title = me.title;
        const length = title.length;
        let titleFont, titleSpacing, i;
        if (length) {
          const rtlHelper = getRtlAdapter(options.rtl, me.x, me.width);
          pt.x = getAlignedX(me, options.titleAlign, options);
          ctx.textAlign = rtlHelper.textAlign(options.titleAlign);
          ctx.textBaseline = 'middle';
          titleFont = toFont(options.titleFont);
          titleSpacing = options.titleSpacing;
          ctx.fillStyle = options.titleColor;
          ctx.font = titleFont.string;
          for (i = 0; i < length; ++i) {
            ctx.fillText(title[i], rtlHelper.x(pt.x), pt.y + titleFont.lineHeight / 2);
            pt.y += titleFont.lineHeight + titleSpacing;
            if (i + 1 === length) {
              pt.y += options.titleMarginBottom - titleSpacing;
            }
          }
        }
      }
      _drawColorBox(ctx, pt, i, rtlHelper, options) {
        const me = this;
        const labelColors = me.labelColors[i];
        const labelPointStyle = me.labelPointStyles[i];
        const {boxHeight, boxWidth} = options;
        const bodyFont = toFont(options.bodyFont);
        const colorX = getAlignedX(me, 'left', options);
        const rtlColorX = rtlHelper.x(colorX);
        const yOffSet = boxHeight < bodyFont.lineHeight ? (bodyFont.lineHeight - boxHeight) / 2 : 0;
        const colorY = pt.y + yOffSet;
        if (options.usePointStyle) {
          const drawOptions = {
            radius: Math.min(boxWidth, boxHeight) / 2,
            pointStyle: labelPointStyle.pointStyle,
            rotation: labelPointStyle.rotation,
            borderWidth: 1
          };
          const centerX = rtlHelper.leftForLtr(rtlColorX, boxWidth) + boxWidth / 2;
          const centerY = colorY + boxHeight / 2;
          ctx.strokeStyle = options.multiKeyBackground;
          ctx.fillStyle = options.multiKeyBackground;
          drawPoint(ctx, drawOptions, centerX, centerY);
          ctx.strokeStyle = labelColors.borderColor;
          ctx.fillStyle = labelColors.backgroundColor;
          drawPoint(ctx, drawOptions, centerX, centerY);
        } else {
          ctx.lineWidth = labelColors.borderWidth || 1;
          ctx.strokeStyle = labelColors.borderColor;
          ctx.setLineDash(labelColors.borderDash || []);
          ctx.lineDashOffset = labelColors.borderDashOffset || 0;
          const outerX = rtlHelper.leftForLtr(rtlColorX, boxWidth);
          const innerX = rtlHelper.leftForLtr(rtlHelper.xPlus(rtlColorX, 1), boxWidth - 2);
          const borderRadius = toTRBLCorners(labelColors.borderRadius);
          if (Object.values(borderRadius).some(v => v !== 0)) {
            ctx.beginPath();
            ctx.fillStyle = options.multiKeyBackground;
            addRoundedRectPath(ctx, {
              x: outerX,
              y: colorY,
              w: boxWidth,
              h: boxHeight,
              radius: borderRadius,
            });
            ctx.fill();
            ctx.stroke();
            ctx.fillStyle = labelColors.backgroundColor;
            ctx.beginPath();
            addRoundedRectPath(ctx, {
              x: innerX,
              y: colorY + 1,
              w: boxWidth - 2,
              h: boxHeight - 2,
              radius: borderRadius,
            });
            ctx.fill();
          } else {
            ctx.fillStyle = options.multiKeyBackground;
            ctx.fillRect(outerX, colorY, boxWidth, boxHeight);
            ctx.strokeRect(outerX, colorY, boxWidth, boxHeight);
            ctx.fillStyle = labelColors.backgroundColor;
            ctx.fillRect(innerX, colorY + 1, boxWidth - 2, boxHeight - 2);
          }
        }
        ctx.fillStyle = me.labelTextColors[i];
      }
      drawBody(pt, ctx, options) {
        const me = this;
        const {body} = me;
        const {bodySpacing, bodyAlign, displayColors, boxHeight, boxWidth} = options;
        const bodyFont = toFont(options.bodyFont);
        let bodyLineHeight = bodyFont.lineHeight;
        let xLinePadding = 0;
        const rtlHelper = getRtlAdapter(options.rtl, me.x, me.width);
        const fillLineOfText = function(line) {
          ctx.fillText(line, rtlHelper.x(pt.x + xLinePadding), pt.y + bodyLineHeight / 2);
          pt.y += bodyLineHeight + bodySpacing;
        };
        const bodyAlignForCalculation = rtlHelper.textAlign(bodyAlign);
        let bodyItem, textColor, lines, i, j, ilen, jlen;
        ctx.textAlign = bodyAlign;
        ctx.textBaseline = 'middle';
        ctx.font = bodyFont.string;
        pt.x = getAlignedX(me, bodyAlignForCalculation, options);
        ctx.fillStyle = options.bodyColor;
        each(me.beforeBody, fillLineOfText);
        xLinePadding = displayColors && bodyAlignForCalculation !== 'right'
          ? bodyAlign === 'center' ? (boxWidth / 2 + 1) : (boxWidth + 2)
          : 0;
        for (i = 0, ilen = body.length; i < ilen; ++i) {
          bodyItem = body[i];
          textColor = me.labelTextColors[i];
          ctx.fillStyle = textColor;
          each(bodyItem.before, fillLineOfText);
          lines = bodyItem.lines;
          if (displayColors && lines.length) {
            me._drawColorBox(ctx, pt, i, rtlHelper, options);
            bodyLineHeight = Math.max(bodyFont.lineHeight, boxHeight);
          }
          for (j = 0, jlen = lines.length; j < jlen; ++j) {
            fillLineOfText(lines[j]);
            bodyLineHeight = bodyFont.lineHeight;
          }
          each(bodyItem.after, fillLineOfText);
        }
        xLinePadding = 0;
        bodyLineHeight = bodyFont.lineHeight;
        each(me.afterBody, fillLineOfText);
        pt.y -= bodySpacing;
      }
      drawFooter(pt, ctx, options) {
        const me = this;
        const footer = me.footer;
        const length = footer.length;
        let footerFont, i;
        if (length) {
          const rtlHelper = getRtlAdapter(options.rtl, me.x, me.width);
          pt.x = getAlignedX(me, options.footerAlign, options);
          pt.y += options.footerMarginTop;
          ctx.textAlign = rtlHelper.textAlign(options.footerAlign);
          ctx.textBaseline = 'middle';
          footerFont = toFont(options.footerFont);
          ctx.fillStyle = options.footerColor;
          ctx.font = footerFont.string;
          for (i = 0; i < length; ++i) {
            ctx.fillText(footer[i], rtlHelper.x(pt.x), pt.y + footerFont.lineHeight / 2);
            pt.y += footerFont.lineHeight + options.footerSpacing;
          }
        }
      }
      drawBackground(pt, ctx, tooltipSize, options) {
        const {xAlign, yAlign} = this;
        const {x, y} = pt;
        const {width, height} = tooltipSize;
        const radius = options.cornerRadius;
        ctx.fillStyle = options.backgroundColor;
        ctx.strokeStyle = options.borderColor;
        ctx.lineWidth = options.borderWidth;
        ctx.beginPath();
        ctx.moveTo(x + radius, y);
        if (yAlign === 'top') {
          this.drawCaret(pt, ctx, tooltipSize, options);
        }
        ctx.lineTo(x + width - radius, y);
        ctx.quadraticCurveTo(x + width, y, x + width, y + radius);
        if (yAlign === 'center' && xAlign === 'right') {
          this.drawCaret(pt, ctx, tooltipSize, options);
        }
        ctx.lineTo(x + width, y + height - radius);
        ctx.quadraticCurveTo(x + width, y + height, x + width - radius, y + height);
        if (yAlign === 'bottom') {
          this.drawCaret(pt, ctx, tooltipSize, options);
        }
        ctx.lineTo(x + radius, y + height);
        ctx.quadraticCurveTo(x, y + height, x, y + height - radius);
        if (yAlign === 'center' && xAlign === 'left') {
          this.drawCaret(pt, ctx, tooltipSize, options);
        }
        ctx.lineTo(x, y + radius);
        ctx.quadraticCurveTo(x, y, x + radius, y);
        ctx.closePath();
        ctx.fill();
        if (options.borderWidth > 0) {
          ctx.stroke();
        }
      }
      _updateAnimationTarget(options) {
        const me = this;
        const chart = me._chart;
        const anims = me.$animations;
        const animX = anims && anims.x;
        const animY = anims && anims.y;
        if (animX || animY) {
          const position = positioners[options.position].call(me, me._active, me._eventPosition);
          if (!position) {
            return;
          }
          const size = me._size = getTooltipSize(me, options);
          const positionAndSize = Object.assign({}, position, me._size);
          const alignment = determineAlignment(chart, options, positionAndSize);
          const point = getBackgroundPoint(options, positionAndSize, alignment, chart);
          if (animX._to !== point.x || animY._to !== point.y) {
            me.xAlign = alignment.xAlign;
            me.yAlign = alignment.yAlign;
            me.width = size.width;
            me.height = size.height;
            me.caretX = position.x;
            me.caretY = position.y;
            me._resolveAnimations().update(me, point);
          }
        }
      }
      draw(ctx) {
        const me = this;
        const options = me.options.setContext(me.getContext());
        let opacity = me.opacity;
        if (!opacity) {
          return;
        }
        me._updateAnimationTarget(options);
        const tooltipSize = {
          width: me.width,
          height: me.height
        };
        const pt = {
          x: me.x,
          y: me.y
        };
        opacity = Math.abs(opacity) < 1e-3 ? 0 : opacity;
        const padding = toPadding(options.padding);
        const hasTooltipContent = me.title.length || me.beforeBody.length || me.body.length || me.afterBody.length || me.footer.length;
        if (options.enabled && hasTooltipContent) {
          ctx.save();
          ctx.globalAlpha = opacity;
          me.drawBackground(pt, ctx, tooltipSize, options);
          overrideTextDirection(ctx, options.textDirection);
          pt.y += padding.top;
          me.drawTitle(pt, ctx, options);
          me.drawBody(pt, ctx, options);
          me.drawFooter(pt, ctx, options);
          restoreTextDirection(ctx, options.textDirection);
          ctx.restore();
        }
      }
      getActiveElements() {
        return this._active || [];
      }
      setActiveElements(activeElements, eventPosition) {
        const me = this;
        const lastActive = me._active;
        const active = activeElements.map(({datasetIndex, index}) => {
          const meta = me._chart.getDatasetMeta(datasetIndex);
          if (!meta) {
            throw new Error('Cannot find a dataset at index ' + datasetIndex);
          }
          return {
            datasetIndex,
            element: meta.data[index],
            index,
          };
        });
        const changed = !_elementsEqual(lastActive, active);
        const positionChanged = me._positionChanged(active, eventPosition);
        if (changed || positionChanged) {
          me._active = active;
          me._eventPosition = eventPosition;
          me.update(true);
        }
      }
      handleEvent(e, replay) {
        const me = this;
        const options = me.options;
        const lastActive = me._active || [];
        let changed = false;
        let active = [];
        if (e.type !== 'mouseout') {
          active = me._chart.getElementsAtEventForMode(e, options.mode, options, replay);
          if (options.reverse) {
            active.reverse();
          }
        }
        const positionChanged = me._positionChanged(active, e);
        changed = replay || !_elementsEqual(active, lastActive) || positionChanged;
        if (changed) {
          me._active = active;
          if (options.enabled || options.external) {
            me._eventPosition = {
              x: e.x,
              y: e.y
            };
            me.update(true, replay);
          }
        }
        return changed;
      }
      _positionChanged(active, e) {
        const {caretX, caretY, options} = this;
        const position = positioners[options.position].call(this, active, e);
        return position !== false && (caretX !== position.x || caretY !== position.y);
      }
    }
    Tooltip.positioners = positioners;
    var plugin_tooltip = {
      id: 'tooltip',
      _element: Tooltip,
      positioners,
      afterInit(chart, _args, options) {
        if (options) {
          chart.tooltip = new Tooltip({_chart: chart, options});
        }
      },
      beforeUpdate(chart, _args, options) {
        if (chart.tooltip) {
          chart.tooltip.initialize(options);
        }
      },
      reset(chart, _args, options) {
        if (chart.tooltip) {
          chart.tooltip.initialize(options);
        }
      },
      afterDraw(chart) {
        const tooltip = chart.tooltip;
        const args = {
          tooltip
        };
        if (chart.notifyPlugins('beforeTooltipDraw', args) === false) {
          return;
        }
        if (tooltip) {
          tooltip.draw(chart.ctx);
        }
        chart.notifyPlugins('afterTooltipDraw', args);
      },
      afterEvent(chart, args) {
        if (chart.tooltip) {
          const useFinalPosition = args.replay;
          if (chart.tooltip.handleEvent(args.event, useFinalPosition)) {
            args.changed = true;
          }
        }
      },
      defaults: {
        enabled: true,
        external: null,
        position: 'average',
        backgroundColor: 'rgba(0,0,0,0.8)',
        titleColor: '#fff',
        titleFont: {
          weight: 'bold',
        },
        titleSpacing: 2,
        titleMarginBottom: 6,
        titleAlign: 'left',
        bodyColor: '#fff',
        bodySpacing: 2,
        bodyFont: {
        },
        bodyAlign: 'left',
        footerColor: '#fff',
        footerSpacing: 2,
        footerMarginTop: 6,
        footerFont: {
          weight: 'bold',
        },
        footerAlign: 'left',
        padding: 6,
        caretPadding: 2,
        caretSize: 5,
        cornerRadius: 6,
        boxHeight: (ctx, opts) => opts.bodyFont.size,
        boxWidth: (ctx, opts) => opts.bodyFont.size,
        multiKeyBackground: '#fff',
        displayColors: true,
        borderColor: 'rgba(0,0,0,0)',
        borderWidth: 0,
        animation: {
          duration: 400,
          easing: 'easeOutQuart',
        },
        animations: {
          numbers: {
            type: 'number',
            properties: ['x', 'y', 'width', 'height', 'caretX', 'caretY'],
          },
          opacity: {
            easing: 'linear',
            duration: 200
          }
        },
        callbacks: {
          beforeTitle: noop,
          title(tooltipItems) {
            if (tooltipItems.length > 0) {
              const item = tooltipItems[0];
              const labels = item.chart.data.labels;
              const labelCount = labels ? labels.length : 0;
              if (this && this.options && this.options.mode === 'dataset') {
                return item.dataset.label || '';
              } else if (item.label) {
                return item.label;
              } else if (labelCount > 0 && item.dataIndex < labelCount) {
                return labels[item.dataIndex];
              }
            }
            return '';
          },
          afterTitle: noop,
          beforeBody: noop,
          beforeLabel: noop,
          label(tooltipItem) {
            if (this && this.options && this.options.mode === 'dataset') {
              return tooltipItem.label + ': ' + tooltipItem.formattedValue || tooltipItem.formattedValue;
            }
            let label = tooltipItem.dataset.label || '';
            if (label) {
              label += ': ';
            }
            const value = tooltipItem.formattedValue;
            if (!isNullOrUndef(value)) {
              label += value;
            }
            return label;
          },
          labelColor(tooltipItem) {
            const meta = tooltipItem.chart.getDatasetMeta(tooltipItem.datasetIndex);
            const options = meta.controller.getStyle(tooltipItem.dataIndex);
            return {
              borderColor: options.borderColor,
              backgroundColor: options.backgroundColor,
              borderWidth: options.borderWidth,
              borderDash: options.borderDash,
              borderDashOffset: options.borderDashOffset,
              borderRadius: 0,
            };
          },
          labelTextColor() {
            return this.options.bodyColor;
          },
          labelPointStyle(tooltipItem) {
            const meta = tooltipItem.chart.getDatasetMeta(tooltipItem.datasetIndex);
            const options = meta.controller.getStyle(tooltipItem.dataIndex);
            return {
              pointStyle: options.pointStyle,
              rotation: options.rotation,
            };
          },
          afterLabel: noop,
          afterBody: noop,
          beforeFooter: noop,
          footer: noop,
          afterFooter: noop
        }
      },
      defaultRoutes: {
        bodyFont: 'font',
        footerFont: 'font',
        titleFont: 'font'
      },
      descriptors: {
        _scriptable: (name) => name !== 'filter' && name !== 'itemSort' && name !== 'external',
        _indexable: false,
        callbacks: {
          _scriptable: false,
          _indexable: false,
        },
        animation: {
          _fallback: false
        },
        animations: {
          _fallback: 'animation'
        }
      },
      additionalOptionScopes: ['interaction']
    };
    
    var plugins = /*#__PURE__*/Object.freeze({
    __proto__: null,
    Decimation: plugin_decimation,
    Filler: plugin_filler,
    Legend: plugin_legend,
    SubTitle: plugin_subtitle,
    Title: plugin_title,
    Tooltip: plugin_tooltip
    });
    
    const addIfString = (labels, raw, index) => typeof raw === 'string'
      ? labels.push(raw) - 1
      : isNaN(raw) ? null : index;
    function findOrAddLabel(labels, raw, index) {
      const first = labels.indexOf(raw);
      if (first === -1) {
        return addIfString(labels, raw, index);
      }
      const last = labels.lastIndexOf(raw);
      return first !== last ? index : first;
    }
    const validIndex = (index, max) => index === null ? null : _limitValue(Math.round(index), 0, max);
    class CategoryScale extends Scale {
      constructor(cfg) {
        super(cfg);
        this._startValue = undefined;
        this._valueRange = 0;
      }
      parse(raw, index) {
        if (isNullOrUndef(raw)) {
          return null;
        }
        const labels = this.getLabels();
        index = isFinite(index) && labels[index] === raw ? index
          : findOrAddLabel(labels, raw, valueOrDefault(index, raw));
        return validIndex(index, labels.length - 1);
      }
      determineDataLimits() {
        const me = this;
        const {minDefined, maxDefined} = me.getUserBounds();
        let {min, max} = me.getMinMax(true);
        if (me.options.bounds === 'ticks') {
          if (!minDefined) {
            min = 0;
          }
          if (!maxDefined) {
            max = me.getLabels().length - 1;
          }
        }
        me.min = min;
        me.max = max;
      }
      buildTicks() {
        const me = this;
        const min = me.min;
        const max = me.max;
        const offset = me.options.offset;
        const ticks = [];
        let labels = me.getLabels();
        labels = (min === 0 && max === labels.length - 1) ? labels : labels.slice(min, max + 1);
        me._valueRange = Math.max(labels.length - (offset ? 0 : 1), 1);
        me._startValue = me.min - (offset ? 0.5 : 0);
        for (let value = min; value <= max; value++) {
          ticks.push({value});
        }
        return ticks;
      }
      getLabelForValue(value) {
        const me = this;
        const labels = me.getLabels();
        if (value >= 0 && value < labels.length) {
          return labels[value];
        }
        return value;
      }
      configure() {
        const me = this;
        super.configure();
        if (!me.isHorizontal()) {
          me._reversePixels = !me._reversePixels;
        }
      }
      getPixelForValue(value) {
        const me = this;
        if (typeof value !== 'number') {
          value = me.parse(value);
        }
        return value === null ? NaN : me.getPixelForDecimal((value - me._startValue) / me._valueRange);
      }
      getPixelForTick(index) {
        const me = this;
        const ticks = me.ticks;
        if (index < 0 || index > ticks.length - 1) {
          return null;
        }
        return me.getPixelForValue(ticks[index].value);
      }
      getValueForPixel(pixel) {
        const me = this;
        return Math.round(me._startValue + me.getDecimalForPixel(pixel) * me._valueRange);
      }
      getBasePixel() {
        return this.bottom;
      }
    }
    CategoryScale.id = 'category';
    CategoryScale.defaults = {
      ticks: {
        callback: CategoryScale.prototype.getLabelForValue
      }
    };
    
    function generateTicks$1(generationOptions, dataRange) {
      const ticks = [];
      const MIN_SPACING = 1e-14;
      const {bounds, step, min, max, precision, count, maxTicks, maxDigits, includeBounds} = generationOptions;
      const unit = step || 1;
      const maxSpaces = maxTicks - 1;
      const {min: rmin, max: rmax} = dataRange;
      const minDefined = !isNullOrUndef(min);
      const maxDefined = !isNullOrUndef(max);
      const countDefined = !isNullOrUndef(count);
      const minSpacing = (rmax - rmin) / (maxDigits + 1);
      let spacing = niceNum((rmax - rmin) / maxSpaces / unit) * unit;
      let factor, niceMin, niceMax, numSpaces;
      if (spacing < MIN_SPACING && !minDefined && !maxDefined) {
        return [{value: rmin}, {value: rmax}];
      }
      numSpaces = Math.ceil(rmax / spacing) - Math.floor(rmin / spacing);
      if (numSpaces > maxSpaces) {
        spacing = niceNum(numSpaces * spacing / maxSpaces / unit) * unit;
      }
      if (!isNullOrUndef(precision)) {
        factor = Math.pow(10, precision);
        spacing = Math.ceil(spacing * factor) / factor;
      }
      if (bounds === 'ticks') {
        niceMin = Math.floor(rmin / spacing) * spacing;
        niceMax = Math.ceil(rmax / spacing) * spacing;
      } else {
        niceMin = rmin;
        niceMax = rmax;
      }
      if (minDefined && maxDefined && step && almostWhole((max - min) / step, spacing / 1000)) {
        numSpaces = Math.round(Math.min((max - min) / spacing, maxTicks));
        spacing = (max - min) / numSpaces;
        niceMin = min;
        niceMax = max;
      } else if (countDefined) {
        niceMin = minDefined ? min : niceMin;
        niceMax = maxDefined ? max : niceMax;
        numSpaces = count - 1;
        spacing = (niceMax - niceMin) / numSpaces;
      } else {
        numSpaces = (niceMax - niceMin) / spacing;
        if (almostEquals(numSpaces, Math.round(numSpaces), spacing / 1000)) {
          numSpaces = Math.round(numSpaces);
        } else {
          numSpaces = Math.ceil(numSpaces);
        }
      }
      const decimalPlaces = Math.max(
        _decimalPlaces(spacing),
        _decimalPlaces(niceMin)
      );
      factor = Math.pow(10, isNullOrUndef(precision) ? decimalPlaces : precision);
      niceMin = Math.round(niceMin * factor) / factor;
      niceMax = Math.round(niceMax * factor) / factor;
      let j = 0;
      if (minDefined) {
        if (includeBounds && niceMin !== min) {
          ticks.push({value: min});
          if (niceMin < min) {
            j++;
          }
          if (almostEquals(Math.round((niceMin + j * spacing) * factor) / factor, min, relativeLabelSize(min, minSpacing, generationOptions))) {
            j++;
          }
        } else if (niceMin < min) {
          j++;
        }
      }
      for (; j < numSpaces; ++j) {
        ticks.push({value: Math.round((niceMin + j * spacing) * factor) / factor});
      }
      if (maxDefined && includeBounds && niceMax !== max) {
        if (almostEquals(ticks[ticks.length - 1].value, max, relativeLabelSize(max, minSpacing, generationOptions))) {
          ticks[ticks.length - 1].value = max;
        } else {
          ticks.push({value: max});
        }
      } else if (!maxDefined || niceMax === max) {
        ticks.push({value: niceMax});
      }
      return ticks;
    }
    function relativeLabelSize(value, minSpacing, {horizontal, minRotation}) {
      const rad = toRadians(minRotation);
      const ratio = (horizontal ? Math.sin(rad) : Math.cos(rad)) || 0.001;
      const length = 0.75 * minSpacing * ('' + value).length;
      return Math.min(minSpacing / ratio, length);
    }
    class LinearScaleBase extends Scale {
      constructor(cfg) {
        super(cfg);
        this.start = undefined;
        this.end = undefined;
        this._startValue = undefined;
        this._endValue = undefined;
        this._valueRange = 0;
      }
      parse(raw, index) {
        if (isNullOrUndef(raw)) {
          return null;
        }
        if ((typeof raw === 'number' || raw instanceof Number) && !isFinite(+raw)) {
          return null;
        }
        return +raw;
      }
      handleTickRangeOptions() {
        const me = this;
        const {beginAtZero} = me.options;
        const {minDefined, maxDefined} = me.getUserBounds();
        let {min, max} = me;
        const setMin = v => (min = minDefined ? min : v);
        const setMax = v => (max = maxDefined ? max : v);
        if (beginAtZero) {
          const minSign = sign(min);
          const maxSign = sign(max);
          if (minSign < 0 && maxSign < 0) {
            setMax(0);
          } else if (minSign > 0 && maxSign > 0) {
            setMin(0);
          }
        }
        if (min === max) {
          let offset = 1;
          if (max >= Number.MAX_SAFE_INTEGER || min <= Number.MIN_SAFE_INTEGER) {
            offset = Math.abs(max * 0.05);
          }
          setMax(max + offset);
          if (!beginAtZero) {
            setMin(min - offset);
          }
        }
        me.min = min;
        me.max = max;
      }
      getTickLimit() {
        const me = this;
        const tickOpts = me.options.ticks;
        let {maxTicksLimit, stepSize} = tickOpts;
        let maxTicks;
        if (stepSize) {
          maxTicks = Math.ceil(me.max / stepSize) - Math.floor(me.min / stepSize) + 1;
        } else {
          maxTicks = me.computeTickLimit();
          maxTicksLimit = maxTicksLimit || 11;
        }
        if (maxTicksLimit) {
          maxTicks = Math.min(maxTicksLimit, maxTicks);
        }
        return maxTicks;
      }
      computeTickLimit() {
        return Number.POSITIVE_INFINITY;
      }
      buildTicks() {
        const me = this;
        const opts = me.options;
        const tickOpts = opts.ticks;
        let maxTicks = me.getTickLimit();
        maxTicks = Math.max(2, maxTicks);
        const numericGeneratorOptions = {
          maxTicks,
          bounds: opts.bounds,
          min: opts.min,
          max: opts.max,
          precision: tickOpts.precision,
          step: tickOpts.stepSize,
          count: tickOpts.count,
          maxDigits: me._maxDigits(),
          horizontal: me.isHorizontal(),
          minRotation: tickOpts.minRotation || 0,
          includeBounds: tickOpts.includeBounds !== false
        };
        const dataRange = me._range || me;
        const ticks = generateTicks$1(numericGeneratorOptions, dataRange);
        if (opts.bounds === 'ticks') {
          _setMinAndMaxByKey(ticks, me, 'value');
        }
        if (opts.reverse) {
          ticks.reverse();
          me.start = me.max;
          me.end = me.min;
        } else {
          me.start = me.min;
          me.end = me.max;
        }
        return ticks;
      }
      configure() {
        const me = this;
        const ticks = me.ticks;
        let start = me.min;
        let end = me.max;
        super.configure();
        if (me.options.offset && ticks.length) {
          const offset = (end - start) / Math.max(ticks.length - 1, 1) / 2;
          start -= offset;
          end += offset;
        }
        me._startValue = start;
        me._endValue = end;
        me._valueRange = end - start;
      }
      getLabelForValue(value) {
        return formatNumber(value, this.chart.options.locale);
      }
    }
    
    class LinearScale extends LinearScaleBase {
      determineDataLimits() {
        const me = this;
        const {min, max} = me.getMinMax(true);
        me.min = isNumberFinite(min) ? min : 0;
        me.max = isNumberFinite(max) ? max : 1;
        me.handleTickRangeOptions();
      }
      computeTickLimit() {
        const me = this;
        const horizontal = me.isHorizontal();
        const length = horizontal ? me.width : me.height;
        const minRotation = toRadians(me.options.ticks.minRotation);
        const ratio = (horizontal ? Math.sin(minRotation) : Math.cos(minRotation)) || 0.001;
        const tickFont = me._resolveTickFontOptions(0);
        return Math.ceil(length / Math.min(40, tickFont.lineHeight / ratio));
      }
      getPixelForValue(value) {
        return value === null ? NaN : this.getPixelForDecimal((value - this._startValue) / this._valueRange);
      }
      getValueForPixel(pixel) {
        return this._startValue + this.getDecimalForPixel(pixel) * this._valueRange;
      }
    }
    LinearScale.id = 'linear';
    LinearScale.defaults = {
      ticks: {
        callback: Ticks.formatters.numeric
      }
    };
    
    function isMajor(tickVal) {
      const remain = tickVal / (Math.pow(10, Math.floor(log10(tickVal))));
      return remain === 1;
    }
    function generateTicks(generationOptions, dataRange) {
      const endExp = Math.floor(log10(dataRange.max));
      const endSignificand = Math.ceil(dataRange.max / Math.pow(10, endExp));
      const ticks = [];
      let tickVal = finiteOrDefault(generationOptions.min, Math.pow(10, Math.floor(log10(dataRange.min))));
      let exp = Math.floor(log10(tickVal));
      let significand = Math.floor(tickVal / Math.pow(10, exp));
      let precision = exp < 0 ? Math.pow(10, Math.abs(exp)) : 1;
      do {
        ticks.push({value: tickVal, major: isMajor(tickVal)});
        ++significand;
        if (significand === 10) {
          significand = 1;
          ++exp;
          precision = exp >= 0 ? 1 : precision;
        }
        tickVal = Math.round(significand * Math.pow(10, exp) * precision) / precision;
      } while (exp < endExp || (exp === endExp && significand < endSignificand));
      const lastTick = finiteOrDefault(generationOptions.max, tickVal);
      ticks.push({value: lastTick, major: isMajor(tickVal)});
      return ticks;
    }
    class LogarithmicScale extends Scale {
      constructor(cfg) {
        super(cfg);
        this.start = undefined;
        this.end = undefined;
        this._startValue = undefined;
        this._valueRange = 0;
      }
      parse(raw, index) {
        const value = LinearScaleBase.prototype.parse.apply(this, [raw, index]);
        if (value === 0) {
          this._zero = true;
          return undefined;
        }
        return isNumberFinite(value) && value > 0 ? value : null;
      }
      determineDataLimits() {
        const me = this;
        const {min, max} = me.getMinMax(true);
        me.min = isNumberFinite(min) ? Math.max(0, min) : null;
        me.max = isNumberFinite(max) ? Math.max(0, max) : null;
        if (me.options.beginAtZero) {
          me._zero = true;
        }
        me.handleTickRangeOptions();
      }
      handleTickRangeOptions() {
        const me = this;
        const {minDefined, maxDefined} = me.getUserBounds();
        let min = me.min;
        let max = me.max;
        const setMin = v => (min = minDefined ? min : v);
        const setMax = v => (max = maxDefined ? max : v);
        const exp = (v, m) => Math.pow(10, Math.floor(log10(v)) + m);
        if (min === max) {
          if (min <= 0) {
            setMin(1);
            setMax(10);
          } else {
            setMin(exp(min, -1));
            setMax(exp(max, +1));
          }
        }
        if (min <= 0) {
          setMin(exp(max, -1));
        }
        if (max <= 0) {
          setMax(exp(min, +1));
        }
        if (me._zero && me.min !== me._suggestedMin && min === exp(me.min, 0)) {
          setMin(exp(min, -1));
        }
        me.min = min;
        me.max = max;
      }
      buildTicks() {
        const me = this;
        const opts = me.options;
        const generationOptions = {
          min: me._userMin,
          max: me._userMax
        };
        const ticks = generateTicks(generationOptions, me);
        if (opts.bounds === 'ticks') {
          _setMinAndMaxByKey(ticks, me, 'value');
        }
        if (opts.reverse) {
          ticks.reverse();
          me.start = me.max;
          me.end = me.min;
        } else {
          me.start = me.min;
          me.end = me.max;
        }
        return ticks;
      }
      getLabelForValue(value) {
        return value === undefined ? '0' : formatNumber(value, this.chart.options.locale);
      }
      configure() {
        const me = this;
        const start = me.min;
        super.configure();
        me._startValue = log10(start);
        me._valueRange = log10(me.max) - log10(start);
      }
      getPixelForValue(value) {
        const me = this;
        if (value === undefined || value === 0) {
          value = me.min;
        }
        if (value === null || isNaN(value)) {
          return NaN;
        }
        return me.getPixelForDecimal(value === me.min
          ? 0
          : (log10(value) - me._startValue) / me._valueRange);
      }
      getValueForPixel(pixel) {
        const me = this;
        const decimal = me.getDecimalForPixel(pixel);
        return Math.pow(10, me._startValue + decimal * me._valueRange);
      }
    }
    LogarithmicScale.id = 'logarithmic';
    LogarithmicScale.defaults = {
      ticks: {
        callback: Ticks.formatters.logarithmic,
        major: {
          enabled: true
        }
      }
    };
    
    function getTickBackdropHeight(opts) {
      const tickOpts = opts.ticks;
      if (tickOpts.display && opts.display) {
        const padding = toPadding(tickOpts.backdropPadding);
        return valueOrDefault(tickOpts.font && tickOpts.font.size, defaults.font.size) + padding.height;
      }
      return 0;
    }
    function measureLabelSize(ctx, font, label) {
      label = isArray(label) ? label : [label];
      return {
        w: _longestText(ctx, font.string, label),
        h: label.length * font.lineHeight
      };
    }
    function determineLimits(angle, pos, size, min, max) {
      if (angle === min || angle === max) {
        return {
          start: pos - (size / 2),
          end: pos + (size / 2)
        };
      } else if (angle < min || angle > max) {
        return {
          start: pos - size,
          end: pos
        };
      }
      return {
        start: pos,
        end: pos + size
      };
    }
    function fitWithPointLabels(scale) {
      const furthestLimits = {
        l: 0,
        r: scale.width,
        t: 0,
        b: scale.height - scale.paddingTop
      };
      const furthestAngles = {};
      const labelSizes = [];
      const padding = [];
      const valueCount = scale.getLabels().length;
      for (let i = 0; i < valueCount; i++) {
        const opts = scale.options.pointLabels.setContext(scale.getPointLabelContext(i));
        padding[i] = opts.padding;
        const pointPosition = scale.getPointPosition(i, scale.drawingArea + padding[i]);
        const plFont = toFont(opts.font);
        const textSize = measureLabelSize(scale.ctx, plFont, scale._pointLabels[i]);
        labelSizes[i] = textSize;
        const angleRadians = scale.getIndexAngle(i);
        const angle = toDegrees(angleRadians);
        const hLimits = determineLimits(angle, pointPosition.x, textSize.w, 0, 180);
        const vLimits = determineLimits(angle, pointPosition.y, textSize.h, 90, 270);
        if (hLimits.start < furthestLimits.l) {
          furthestLimits.l = hLimits.start;
          furthestAngles.l = angleRadians;
        }
        if (hLimits.end > furthestLimits.r) {
          furthestLimits.r = hLimits.end;
          furthestAngles.r = angleRadians;
        }
        if (vLimits.start < furthestLimits.t) {
          furthestLimits.t = vLimits.start;
          furthestAngles.t = angleRadians;
        }
        if (vLimits.end > furthestLimits.b) {
          furthestLimits.b = vLimits.end;
          furthestAngles.b = angleRadians;
        }
      }
      scale._setReductions(scale.drawingArea, furthestLimits, furthestAngles);
      scale._pointLabelItems = buildPointLabelItems(scale, labelSizes, padding);
    }
    function buildPointLabelItems(scale, labelSizes, padding) {
      const items = [];
      const valueCount = scale.getLabels().length;
      const opts = scale.options;
      const tickBackdropHeight = getTickBackdropHeight(opts);
      const outerDistance = scale.getDistanceFromCenterForValue(opts.ticks.reverse ? scale.min : scale.max);
      for (let i = 0; i < valueCount; i++) {
        const extra = (i === 0 ? tickBackdropHeight / 2 : 0);
        const pointLabelPosition = scale.getPointPosition(i, outerDistance + extra + padding[i]);
        const angle = toDegrees(scale.getIndexAngle(i));
        const size = labelSizes[i];
        const y = yForAngle(pointLabelPosition.y, size.h, angle);
        const textAlign = getTextAlignForAngle(angle);
        const left = leftForTextAlign(pointLabelPosition.x, size.w, textAlign);
        items.push({
          x: pointLabelPosition.x,
          y,
          textAlign,
          left,
          top: y,
          right: left + size.w,
          bottom: y + size.h
        });
      }
      return items;
    }
    function getTextAlignForAngle(angle) {
      if (angle === 0 || angle === 180) {
        return 'center';
      } else if (angle < 180) {
        return 'left';
      }
      return 'right';
    }
    function leftForTextAlign(x, w, align) {
      if (align === 'right') {
        x -= w;
      } else if (align === 'center') {
        x -= (w / 2);
      }
      return x;
    }
    function yForAngle(y, h, angle) {
      if (angle === 90 || angle === 270) {
        y -= (h / 2);
      } else if (angle > 270 || angle < 90) {
        y -= h;
      }
      return y;
    }
    function drawPointLabels(scale, labelCount) {
      const {ctx, options: {pointLabels}} = scale;
      for (let i = labelCount - 1; i >= 0; i--) {
        const optsAtIndex = pointLabels.setContext(scale.getPointLabelContext(i));
        const plFont = toFont(optsAtIndex.font);
        const {x, y, textAlign, left, top, right, bottom} = scale._pointLabelItems[i];
        const {backdropColor} = optsAtIndex;
        if (!isNullOrUndef(backdropColor)) {
          const padding = toPadding(optsAtIndex.backdropPadding);
          ctx.fillStyle = backdropColor;
          ctx.fillRect(left - padding.left, top - padding.top, right - left + padding.width, bottom - top + padding.height);
        }
        renderText(
          ctx,
          scale._pointLabels[i],
          x,
          y + (plFont.lineHeight / 2),
          plFont,
          {
            color: optsAtIndex.color,
            textAlign: textAlign,
            textBaseline: 'middle'
          }
        );
      }
    }
    function pathRadiusLine(scale, radius, circular, labelCount) {
      const {ctx} = scale;
      if (circular) {
        ctx.arc(scale.xCenter, scale.yCenter, radius, 0, TAU);
      } else {
        let pointPosition = scale.getPointPosition(0, radius);
        ctx.moveTo(pointPosition.x, pointPosition.y);
        for (let i = 1; i < labelCount; i++) {
          pointPosition = scale.getPointPosition(i, radius);
          ctx.lineTo(pointPosition.x, pointPosition.y);
        }
      }
    }
    function drawRadiusLine(scale, gridLineOpts, radius, labelCount) {
      const ctx = scale.ctx;
      const circular = gridLineOpts.circular;
      const {color, lineWidth} = gridLineOpts;
      if ((!circular && !labelCount) || !color || !lineWidth || radius < 0) {
        return;
      }
      ctx.save();
      ctx.strokeStyle = color;
      ctx.lineWidth = lineWidth;
      ctx.setLineDash(gridLineOpts.borderDash);
      ctx.lineDashOffset = gridLineOpts.borderDashOffset;
      ctx.beginPath();
      pathRadiusLine(scale, radius, circular, labelCount);
      ctx.closePath();
      ctx.stroke();
      ctx.restore();
    }
    function numberOrZero(param) {
      return isNumber(param) ? param : 0;
    }
    function createPointLabelContext(parent, index, label) {
      return Object.assign(Object.create(parent), {
        label,
        index,
        type: 'pointLabel'
      });
    }
    class RadialLinearScale extends LinearScaleBase {
      constructor(cfg) {
        super(cfg);
        this.xCenter = undefined;
        this.yCenter = undefined;
        this.drawingArea = undefined;
        this._pointLabels = [];
        this._pointLabelItems = [];
      }
      setDimensions() {
        const me = this;
        me.width = me.maxWidth;
        me.height = me.maxHeight;
        me.paddingTop = getTickBackdropHeight(me.options) / 2;
        me.xCenter = Math.floor(me.width / 2);
        me.yCenter = Math.floor((me.height - me.paddingTop) / 2);
        me.drawingArea = Math.min(me.height - me.paddingTop, me.width) / 2;
      }
      determineDataLimits() {
        const me = this;
        const {min, max} = me.getMinMax(false);
        me.min = isNumberFinite(min) && !isNaN(min) ? min : 0;
        me.max = isNumberFinite(max) && !isNaN(max) ? max : 0;
        me.handleTickRangeOptions();
      }
      computeTickLimit() {
        return Math.ceil(this.drawingArea / getTickBackdropHeight(this.options));
      }
      generateTickLabels(ticks) {
        const me = this;
        LinearScaleBase.prototype.generateTickLabels.call(me, ticks);
        me._pointLabels = me.getLabels().map((value, index) => {
          const label = callback(me.options.pointLabels.callback, [value, index], me);
          return label || label === 0 ? label : '';
        });
      }
      fit() {
        const me = this;
        const opts = me.options;
        if (opts.display && opts.pointLabels.display) {
          fitWithPointLabels(me);
        } else {
          me.setCenterPoint(0, 0, 0, 0);
        }
      }
      _setReductions(largestPossibleRadius, furthestLimits, furthestAngles) {
        const me = this;
        let radiusReductionLeft = furthestLimits.l / Math.sin(furthestAngles.l);
        let radiusReductionRight = Math.max(furthestLimits.r - me.width, 0) / Math.sin(furthestAngles.r);
        let radiusReductionTop = -furthestLimits.t / Math.cos(furthestAngles.t);
        let radiusReductionBottom = -Math.max(furthestLimits.b - (me.height - me.paddingTop), 0) / Math.cos(furthestAngles.b);
        radiusReductionLeft = numberOrZero(radiusReductionLeft);
        radiusReductionRight = numberOrZero(radiusReductionRight);
        radiusReductionTop = numberOrZero(radiusReductionTop);
        radiusReductionBottom = numberOrZero(radiusReductionBottom);
        me.drawingArea = Math.max(largestPossibleRadius / 2, Math.min(
          Math.floor(largestPossibleRadius - (radiusReductionLeft + radiusReductionRight) / 2),
          Math.floor(largestPossibleRadius - (radiusReductionTop + radiusReductionBottom) / 2)));
        me.setCenterPoint(radiusReductionLeft, radiusReductionRight, radiusReductionTop, radiusReductionBottom);
      }
      setCenterPoint(leftMovement, rightMovement, topMovement, bottomMovement) {
        const me = this;
        const maxRight = me.width - rightMovement - me.drawingArea;
        const maxLeft = leftMovement + me.drawingArea;
        const maxTop = topMovement + me.drawingArea;
        const maxBottom = (me.height - me.paddingTop) - bottomMovement - me.drawingArea;
        me.xCenter = Math.floor(((maxLeft + maxRight) / 2) + me.left);
        me.yCenter = Math.floor(((maxTop + maxBottom) / 2) + me.top + me.paddingTop);
      }
      getIndexAngle(index) {
        const angleMultiplier = TAU / this.getLabels().length;
        const startAngle = this.options.startAngle || 0;
        return _normalizeAngle(index * angleMultiplier + toRadians(startAngle));
      }
      getDistanceFromCenterForValue(value) {
        const me = this;
        if (isNullOrUndef(value)) {
          return NaN;
        }
        const scalingFactor = me.drawingArea / (me.max - me.min);
        if (me.options.reverse) {
          return (me.max - value) * scalingFactor;
        }
        return (value - me.min) * scalingFactor;
      }
      getValueForDistanceFromCenter(distance) {
        if (isNullOrUndef(distance)) {
          return NaN;
        }
        const me = this;
        const scaledDistance = distance / (me.drawingArea / (me.max - me.min));
        return me.options.reverse ? me.max - scaledDistance : me.min + scaledDistance;
      }
      getPointLabelContext(index) {
        const me = this;
        const pointLabels = me._pointLabels || [];
        if (index >= 0 && index < pointLabels.length) {
          const pointLabel = pointLabels[index];
          return createPointLabelContext(me.getContext(), index, pointLabel);
        }
      }
      getPointPosition(index, distanceFromCenter) {
        const me = this;
        const angle = me.getIndexAngle(index) - HALF_PI;
        return {
          x: Math.cos(angle) * distanceFromCenter + me.xCenter,
          y: Math.sin(angle) * distanceFromCenter + me.yCenter,
          angle
        };
      }
      getPointPositionForValue(index, value) {
        return this.getPointPosition(index, this.getDistanceFromCenterForValue(value));
      }
      getBasePosition(index) {
        return this.getPointPositionForValue(index || 0, this.getBaseValue());
      }
      getPointLabelPosition(index) {
        const {left, top, right, bottom} = this._pointLabelItems[index];
        return {
          left,
          top,
          right,
          bottom,
        };
      }
      drawBackground() {
        const me = this;
        const {backgroundColor, grid: {circular}} = me.options;
        if (backgroundColor) {
          const ctx = me.ctx;
          ctx.save();
          ctx.beginPath();
          pathRadiusLine(me, me.getDistanceFromCenterForValue(me._endValue), circular, me.getLabels().length);
          ctx.closePath();
          ctx.fillStyle = backgroundColor;
          ctx.fill();
          ctx.restore();
        }
      }
      drawGrid() {
        const me = this;
        const ctx = me.ctx;
        const opts = me.options;
        const {angleLines, grid} = opts;
        const labelCount = me.getLabels().length;
        let i, offset, position;
        if (opts.pointLabels.display) {
          drawPointLabels(me, labelCount);
        }
        if (grid.display) {
          me.ticks.forEach((tick, index) => {
            if (index !== 0) {
              offset = me.getDistanceFromCenterForValue(tick.value);
              const optsAtIndex = grid.setContext(me.getContext(index - 1));
              drawRadiusLine(me, optsAtIndex, offset, labelCount);
            }
          });
        }
        if (angleLines.display) {
          ctx.save();
          for (i = me.getLabels().length - 1; i >= 0; i--) {
            const optsAtIndex = angleLines.setContext(me.getPointLabelContext(i));
            const {color, lineWidth} = optsAtIndex;
            if (!lineWidth || !color) {
              continue;
            }
            ctx.lineWidth = lineWidth;
            ctx.strokeStyle = color;
            ctx.setLineDash(optsAtIndex.borderDash);
            ctx.lineDashOffset = optsAtIndex.borderDashOffset;
            offset = me.getDistanceFromCenterForValue(opts.ticks.reverse ? me.min : me.max);
            position = me.getPointPosition(i, offset);
            ctx.beginPath();
            ctx.moveTo(me.xCenter, me.yCenter);
            ctx.lineTo(position.x, position.y);
            ctx.stroke();
          }
          ctx.restore();
        }
      }
      drawBorder() {}
      drawLabels() {
        const me = this;
        const ctx = me.ctx;
        const opts = me.options;
        const tickOpts = opts.ticks;
        if (!tickOpts.display) {
          return;
        }
        const startAngle = me.getIndexAngle(0);
        let offset, width;
        ctx.save();
        ctx.translate(me.xCenter, me.yCenter);
        ctx.rotate(startAngle);
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        me.ticks.forEach((tick, index) => {
          if (index === 0 && !opts.reverse) {
            return;
          }
          const optsAtIndex = tickOpts.setContext(me.getContext(index));
          const tickFont = toFont(optsAtIndex.font);
          offset = me.getDistanceFromCenterForValue(me.ticks[index].value);
          if (optsAtIndex.showLabelBackdrop) {
            ctx.font = tickFont.string;
            width = ctx.measureText(tick.label).width;
            ctx.fillStyle = optsAtIndex.backdropColor;
            const padding = toPadding(optsAtIndex.backdropPadding);
            ctx.fillRect(
              -width / 2 - padding.left,
              -offset - tickFont.size / 2 - padding.top,
              width + padding.width,
              tickFont.size + padding.height
            );
          }
          renderText(ctx, tick.label, 0, -offset, tickFont, {
            color: optsAtIndex.color,
          });
        });
        ctx.restore();
      }
      drawTitle() {}
    }
    RadialLinearScale.id = 'radialLinear';
    RadialLinearScale.defaults = {
      display: true,
      animate: true,
      position: 'chartArea',
      angleLines: {
        display: true,
        lineWidth: 1,
        borderDash: [],
        borderDashOffset: 0.0
      },
      grid: {
        circular: false
      },
      startAngle: 0,
      ticks: {
        showLabelBackdrop: true,
        callback: Ticks.formatters.numeric
      },
      pointLabels: {
        backdropColor: undefined,
        backdropPadding: 2,
        display: true,
        font: {
          size: 10
        },
        callback(label) {
          return label;
        },
        padding: 5
      }
    };
    RadialLinearScale.defaultRoutes = {
      'angleLines.color': 'borderColor',
      'pointLabels.color': 'color',
      'ticks.color': 'color'
    };
    RadialLinearScale.descriptors = {
      angleLines: {
        _fallback: 'grid'
      }
    };
    
    const INTERVALS = {
      millisecond: {common: true, size: 1, steps: 1000},
      second: {common: true, size: 1000, steps: 60},
      minute: {common: true, size: 60000, steps: 60},
      hour: {common: true, size: 3600000, steps: 24},
      day: {common: true, size: 86400000, steps: 30},
      week: {common: false, size: 604800000, steps: 4},
      month: {common: true, size: 2.628e9, steps: 12},
      quarter: {common: false, size: 7.884e9, steps: 4},
      year: {common: true, size: 3.154e10}
    };
    const UNITS = (Object.keys(INTERVALS));
    function sorter(a, b) {
      return a - b;
    }
    function parse(scale, input) {
      if (isNullOrUndef(input)) {
        return null;
      }
      const adapter = scale._adapter;
      const {parser, round, isoWeekday} = scale._parseOpts;
      let value = input;
      if (typeof parser === 'function') {
        value = parser(value);
      }
      if (!isNumberFinite(value)) {
        value = typeof parser === 'string'
          ? adapter.parse(value, parser)
          : adapter.parse(value);
      }
      if (value === null) {
        return null;
      }
      if (round) {
        value = round === 'week' && (isNumber(isoWeekday) || isoWeekday === true)
          ? adapter.startOf(value, 'isoWeek', isoWeekday)
          : adapter.startOf(value, round);
      }
      return +value;
    }
    function determineUnitForAutoTicks(minUnit, min, max, capacity) {
      const ilen = UNITS.length;
      for (let i = UNITS.indexOf(minUnit); i < ilen - 1; ++i) {
        const interval = INTERVALS[UNITS[i]];
        const factor = interval.steps ? interval.steps : Number.MAX_SAFE_INTEGER;
        if (interval.common && Math.ceil((max - min) / (factor * interval.size)) <= capacity) {
          return UNITS[i];
        }
      }
      return UNITS[ilen - 1];
    }
    function determineUnitForFormatting(scale, numTicks, minUnit, min, max) {
      for (let i = UNITS.length - 1; i >= UNITS.indexOf(minUnit); i--) {
        const unit = UNITS[i];
        if (INTERVALS[unit].common && scale._adapter.diff(max, min, unit) >= numTicks - 1) {
          return unit;
        }
      }
      return UNITS[minUnit ? UNITS.indexOf(minUnit) : 0];
    }
    function determineMajorUnit(unit) {
      for (let i = UNITS.indexOf(unit) + 1, ilen = UNITS.length; i < ilen; ++i) {
        if (INTERVALS[UNITS[i]].common) {
          return UNITS[i];
        }
      }
    }
    function addTick(ticks, time, timestamps) {
      if (!timestamps) {
        ticks[time] = true;
      } else if (timestamps.length) {
        const {lo, hi} = _lookup(timestamps, time);
        const timestamp = timestamps[lo] >= time ? timestamps[lo] : timestamps[hi];
        ticks[timestamp] = true;
      }
    }
    function setMajorTicks(scale, ticks, map, majorUnit) {
      const adapter = scale._adapter;
      const first = +adapter.startOf(ticks[0].value, majorUnit);
      const last = ticks[ticks.length - 1].value;
      let major, index;
      for (major = first; major <= last; major = +adapter.add(major, 1, majorUnit)) {
        index = map[major];
        if (index >= 0) {
          ticks[index].major = true;
        }
      }
      return ticks;
    }
    function ticksFromTimestamps(scale, values, majorUnit) {
      const ticks = [];
      const map = {};
      const ilen = values.length;
      let i, value;
      for (i = 0; i < ilen; ++i) {
        value = values[i];
        map[value] = i;
        ticks.push({
          value,
          major: false
        });
      }
      return (ilen === 0 || !majorUnit) ? ticks : setMajorTicks(scale, ticks, map, majorUnit);
    }
    class TimeScale extends Scale {
      constructor(props) {
        super(props);
        this._cache = {
          data: [],
          labels: [],
          all: []
        };
        this._unit = 'day';
        this._majorUnit = undefined;
        this._offsets = {};
        this._normalized = false;
        this._parseOpts = undefined;
      }
      init(scaleOpts, opts) {
        const time = scaleOpts.time || (scaleOpts.time = {});
        const adapter = this._adapter = new _adapters._date(scaleOpts.adapters.date);
        mergeIf(time.displayFormats, adapter.formats());
        this._parseOpts = {
          parser: time.parser,
          round: time.round,
          isoWeekday: time.isoWeekday
        };
        super.init(scaleOpts);
        this._normalized = opts.normalized;
      }
      parse(raw, index) {
        if (raw === undefined) {
          return null;
        }
        return parse(this, raw);
      }
      beforeLayout() {
        super.beforeLayout();
        this._cache = {
          data: [],
          labels: [],
          all: []
        };
      }
      determineDataLimits() {
        const me = this;
        const options = me.options;
        const adapter = me._adapter;
        const unit = options.time.unit || 'day';
        let {min, max, minDefined, maxDefined} = me.getUserBounds();
        function _applyBounds(bounds) {
          if (!minDefined && !isNaN(bounds.min)) {
            min = Math.min(min, bounds.min);
          }
          if (!maxDefined && !isNaN(bounds.max)) {
            max = Math.max(max, bounds.max);
          }
        }
        if (!minDefined || !maxDefined) {
          _applyBounds(me._getLabelBounds());
          if (options.bounds !== 'ticks' || options.ticks.source !== 'labels') {
            _applyBounds(me.getMinMax(false));
          }
        }
        min = isNumberFinite(min) && !isNaN(min) ? min : +adapter.startOf(Date.now(), unit);
        max = isNumberFinite(max) && !isNaN(max) ? max : +adapter.endOf(Date.now(), unit) + 1;
        me.min = Math.min(min, max - 1);
        me.max = Math.max(min + 1, max);
      }
      _getLabelBounds() {
        const arr = this.getLabelTimestamps();
        let min = Number.POSITIVE_INFINITY;
        let max = Number.NEGATIVE_INFINITY;
        if (arr.length) {
          min = arr[0];
          max = arr[arr.length - 1];
        }
        return {min, max};
      }
      buildTicks() {
        const me = this;
        const options = me.options;
        const timeOpts = options.time;
        const tickOpts = options.ticks;
        const timestamps = tickOpts.source === 'labels' ? me.getLabelTimestamps() : me._generate();
        if (options.bounds === 'ticks' && timestamps.length) {
          me.min = me._userMin || timestamps[0];
          me.max = me._userMax || timestamps[timestamps.length - 1];
        }
        const min = me.min;
        const max = me.max;
        const ticks = _filterBetween(timestamps, min, max);
        me._unit = timeOpts.unit || (tickOpts.autoSkip
          ? determineUnitForAutoTicks(timeOpts.minUnit, me.min, me.max, me._getLabelCapacity(min))
          : determineUnitForFormatting(me, ticks.length, timeOpts.minUnit, me.min, me.max));
        me._majorUnit = !tickOpts.major.enabled || me._unit === 'year' ? undefined
          : determineMajorUnit(me._unit);
        me.initOffsets(timestamps);
        if (options.reverse) {
          ticks.reverse();
        }
        return ticksFromTimestamps(me, ticks, me._majorUnit);
      }
      initOffsets(timestamps) {
        const me = this;
        let start = 0;
        let end = 0;
        let first, last;
        if (me.options.offset && timestamps.length) {
          first = me.getDecimalForValue(timestamps[0]);
          if (timestamps.length === 1) {
            start = 1 - first;
          } else {
            start = (me.getDecimalForValue(timestamps[1]) - first) / 2;
          }
          last = me.getDecimalForValue(timestamps[timestamps.length - 1]);
          if (timestamps.length === 1) {
            end = last;
          } else {
            end = (last - me.getDecimalForValue(timestamps[timestamps.length - 2])) / 2;
          }
        }
        const limit = timestamps.length < 3 ? 0.5 : 0.25;
        start = _limitValue(start, 0, limit);
        end = _limitValue(end, 0, limit);
        me._offsets = {start, end, factor: 1 / (start + 1 + end)};
      }
      _generate() {
        const me = this;
        const adapter = me._adapter;
        const min = me.min;
        const max = me.max;
        const options = me.options;
        const timeOpts = options.time;
        const minor = timeOpts.unit || determineUnitForAutoTicks(timeOpts.minUnit, min, max, me._getLabelCapacity(min));
        const stepSize = valueOrDefault(timeOpts.stepSize, 1);
        const weekday = minor === 'week' ? timeOpts.isoWeekday : false;
        const hasWeekday = isNumber(weekday) || weekday === true;
        const ticks = {};
        let first = min;
        let time, count;
        if (hasWeekday) {
          first = +adapter.startOf(first, 'isoWeek', weekday);
        }
        first = +adapter.startOf(first, hasWeekday ? 'day' : minor);
        if (adapter.diff(max, min, minor) > 100000 * stepSize) {
          throw new Error(min + ' and ' + max + ' are too far apart with stepSize of ' + stepSize + ' ' + minor);
        }
        const timestamps = options.ticks.source === 'data' && me.getDataTimestamps();
        for (time = first, count = 0; time < max; time = +adapter.add(time, stepSize, minor), count++) {
          addTick(ticks, time, timestamps);
        }
        if (time === max || options.bounds === 'ticks' || count === 1) {
          addTick(ticks, time, timestamps);
        }
        return Object.keys(ticks).sort((a, b) => a - b).map(x => +x);
      }
      getLabelForValue(value) {
        const me = this;
        const adapter = me._adapter;
        const timeOpts = me.options.time;
        if (timeOpts.tooltipFormat) {
          return adapter.format(value, timeOpts.tooltipFormat);
        }
        return adapter.format(value, timeOpts.displayFormats.datetime);
      }
      _tickFormatFunction(time, index, ticks, format) {
        const me = this;
        const options = me.options;
        const formats = options.time.displayFormats;
        const unit = me._unit;
        const majorUnit = me._majorUnit;
        const minorFormat = unit && formats[unit];
        const majorFormat = majorUnit && formats[majorUnit];
        const tick = ticks[index];
        const major = majorUnit && majorFormat && tick && tick.major;
        const label = me._adapter.format(time, format || (major ? majorFormat : minorFormat));
        const formatter = options.ticks.callback;
        return formatter ? callback(formatter, [label, index, ticks], me) : label;
      }
      generateTickLabels(ticks) {
        let i, ilen, tick;
        for (i = 0, ilen = ticks.length; i < ilen; ++i) {
          tick = ticks[i];
          tick.label = this._tickFormatFunction(tick.value, i, ticks);
        }
      }
      getDecimalForValue(value) {
        const me = this;
        return value === null ? NaN : (value - me.min) / (me.max - me.min);
      }
      getPixelForValue(value) {
        const me = this;
        const offsets = me._offsets;
        const pos = me.getDecimalForValue(value);
        return me.getPixelForDecimal((offsets.start + pos) * offsets.factor);
      }
      getValueForPixel(pixel) {
        const me = this;
        const offsets = me._offsets;
        const pos = me.getDecimalForPixel(pixel) / offsets.factor - offsets.end;
        return me.min + pos * (me.max - me.min);
      }
      _getLabelSize(label) {
        const me = this;
        const ticksOpts = me.options.ticks;
        const tickLabelWidth = me.ctx.measureText(label).width;
        const angle = toRadians(me.isHorizontal() ? ticksOpts.maxRotation : ticksOpts.minRotation);
        const cosRotation = Math.cos(angle);
        const sinRotation = Math.sin(angle);
        const tickFontSize = me._resolveTickFontOptions(0).size;
        return {
          w: (tickLabelWidth * cosRotation) + (tickFontSize * sinRotation),
          h: (tickLabelWidth * sinRotation) + (tickFontSize * cosRotation)
        };
      }
      _getLabelCapacity(exampleTime) {
        const me = this;
        const timeOpts = me.options.time;
        const displayFormats = timeOpts.displayFormats;
        const format = displayFormats[timeOpts.unit] || displayFormats.millisecond;
        const exampleLabel = me._tickFormatFunction(exampleTime, 0, ticksFromTimestamps(me, [exampleTime], me._majorUnit), format);
        const size = me._getLabelSize(exampleLabel);
        const capacity = Math.floor(me.isHorizontal() ? me.width / size.w : me.height / size.h) - 1;
        return capacity > 0 ? capacity : 1;
      }
      getDataTimestamps() {
        const me = this;
        let timestamps = me._cache.data || [];
        let i, ilen;
        if (timestamps.length) {
          return timestamps;
        }
        const metas = me.getMatchingVisibleMetas();
        if (me._normalized && metas.length) {
          return (me._cache.data = metas[0].controller.getAllParsedValues(me));
        }
        for (i = 0, ilen = metas.length; i < ilen; ++i) {
          timestamps = timestamps.concat(metas[i].controller.getAllParsedValues(me));
        }
        return (me._cache.data = me.normalize(timestamps));
      }
      getLabelTimestamps() {
        const me = this;
        const timestamps = me._cache.labels || [];
        let i, ilen;
        if (timestamps.length) {
          return timestamps;
        }
        const labels = me.getLabels();
        for (i = 0, ilen = labels.length; i < ilen; ++i) {
          timestamps.push(parse(me, labels[i]));
        }
        return (me._cache.labels = me._normalized ? timestamps : me.normalize(timestamps));
      }
      normalize(values) {
        return _arrayUnique(values.sort(sorter));
      }
    }
    TimeScale.id = 'time';
    TimeScale.defaults = {
      bounds: 'data',
      adapters: {},
      time: {
        parser: false,
        unit: false,
        round: false,
        isoWeekday: false,
        minUnit: 'millisecond',
        displayFormats: {}
      },
      ticks: {
        source: 'auto',
        major: {
          enabled: false
        }
      }
    };
    
    function interpolate(table, val, reverse) {
      let lo = 0;
      let hi = table.length - 1;
      let prevSource, nextSource, prevTarget, nextTarget;
      if (reverse) {
        if (val >= table[lo].pos && val <= table[hi].pos) {
          ({lo, hi} = _lookupByKey(table, 'pos', val));
        }
        ({pos: prevSource, time: prevTarget} = table[lo]);
        ({pos: nextSource, time: nextTarget} = table[hi]);
      } else {
        if (val >= table[lo].time && val <= table[hi].time) {
          ({lo, hi} = _lookupByKey(table, 'time', val));
        }
        ({time: prevSource, pos: prevTarget} = table[lo]);
        ({time: nextSource, pos: nextTarget} = table[hi]);
      }
      const span = nextSource - prevSource;
      return span ? prevTarget + (nextTarget - prevTarget) * (val - prevSource) / span : prevTarget;
    }
    class TimeSeriesScale extends TimeScale {
      constructor(props) {
        super(props);
        this._table = [];
        this._minPos = undefined;
        this._tableRange = undefined;
      }
      initOffsets() {
        const me = this;
        const timestamps = me._getTimestampsForTable();
        const table = me._table = me.buildLookupTable(timestamps);
        me._minPos = interpolate(table, me.min);
        me._tableRange = interpolate(table, me.max) - me._minPos;
        super.initOffsets(timestamps);
      }
      buildLookupTable(timestamps) {
        const {min, max} = this;
        const items = [];
        const table = [];
        let i, ilen, prev, curr, next;
        for (i = 0, ilen = timestamps.length; i < ilen; ++i) {
          curr = timestamps[i];
          if (curr >= min && curr <= max) {
            items.push(curr);
          }
        }
        if (items.length < 2) {
          return [
            {time: min, pos: 0},
            {time: max, pos: 1}
          ];
        }
        for (i = 0, ilen = items.length; i < ilen; ++i) {
          next = items[i + 1];
          prev = items[i - 1];
          curr = items[i];
          if (Math.round((next + prev) / 2) !== curr) {
            table.push({time: curr, pos: i / (ilen - 1)});
          }
        }
        return table;
      }
      _getTimestampsForTable() {
        const me = this;
        let timestamps = me._cache.all || [];
        if (timestamps.length) {
          return timestamps;
        }
        const data = me.getDataTimestamps();
        const label = me.getLabelTimestamps();
        if (data.length && label.length) {
          timestamps = me.normalize(data.concat(label));
        } else {
          timestamps = data.length ? data : label;
        }
        timestamps = me._cache.all = timestamps;
        return timestamps;
      }
      getDecimalForValue(value) {
        return (interpolate(this._table, value) - this._minPos) / this._tableRange;
      }
      getValueForPixel(pixel) {
        const me = this;
        const offsets = me._offsets;
        const decimal = me.getDecimalForPixel(pixel) / offsets.factor - offsets.end;
        return interpolate(me._table, decimal * me._tableRange + me._minPos, true);
      }
    }
    TimeSeriesScale.id = 'timeseries';
    TimeSeriesScale.defaults = TimeScale.defaults;
    
    var scales = /*#__PURE__*/Object.freeze({
    __proto__: null,
    CategoryScale: CategoryScale,
    LinearScale: LinearScale,
    LogarithmicScale: LogarithmicScale,
    RadialLinearScale: RadialLinearScale,
    TimeScale: TimeScale,
    TimeSeriesScale: TimeSeriesScale
    });
    
    Chart.register(controllers, scales, elements, plugins);
    Chart.helpers = {...helpers};
    Chart._adapters = _adapters;
    Chart.Animation = Animation;
    Chart.Animations = Animations;
    Chart.animator = animator;
    Chart.controllers = registry.controllers.items;
    Chart.DatasetController = DatasetController;
    Chart.Element = Element;
    Chart.elements = elements;
    Chart.Interaction = Interaction;
    Chart.layouts = layouts;
    Chart.platforms = platforms;
    Chart.Scale = Scale;
    Chart.Ticks = Ticks;
    Object.assign(Chart, controllers, scales, elements, plugins, platforms);
    Chart.Chart = Chart;
    if (typeof window !== 'undefined') {
      window.Chart = Chart;
    }
    
    return Chart;
    
    })));