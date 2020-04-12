window.ls.filter
  .add("gravatar", function($value, element) {
    if (!$value) {
      return "";
    }
    
    // MD5 (Message-Digest Algorithm) by WebToolkit
    let MD5 = function(s) {
      function L(k, d) {
        return (k << d) | (k >>> (32 - d));
      }
      function K(G, k) {
        let I, d, F, H, x;
        F = G & 2147483648;
        H = k & 2147483648;
        I = G & 1073741824;
        d = k & 1073741824;
        x = (G & 1073741823) + (k & 1073741823);
        if (I & d) {
          return x ^ 2147483648 ^ F ^ H;
        }
        if (I | d) {
          if (x & 1073741824) {
            return x ^ 3221225472 ^ F ^ H;
          } else {
            return x ^ 1073741824 ^ F ^ H;
          }
        } else {
          return x ^ F ^ H;
        }
      }
      function r(d, F, k) {
        return (d & F) | (~d & k);
      }
      function q(d, F, k) {
        return (d & k) | (F & ~k);
      }
      function p(d, F, k) {
        return d ^ F ^ k;
      }
      function n(d, F, k) {
        return F ^ (d | ~k);
      }
      function u(G, F, aa, Z, k, H, I) {
        G = K(G, K(K(r(F, aa, Z), k), I));
        return K(L(G, H), F);
      }
      function f(G, F, aa, Z, k, H, I) {
        G = K(G, K(K(q(F, aa, Z), k), I));
        return K(L(G, H), F);
      }
      function D(G, F, aa, Z, k, H, I) {
        G = K(G, K(K(p(F, aa, Z), k), I));
        return K(L(G, H), F);
      }
      function t(G, F, aa, Z, k, H, I) {
        G = K(G, K(K(n(F, aa, Z), k), I));
        return K(L(G, H), F);
      }
      function e(G) {
        let Z;
        let F = G.length;
        let x = F + 8;
        let k = (x - (x % 64)) / 64;
        let I = (k + 1) * 16;
        let aa = Array(I - 1);
        let d = 0;
        let H = 0;
        while (H < F) {
          Z = (H - (H % 4)) / 4;
          d = (H % 4) * 8;
          aa[Z] = aa[Z] | (G.charCodeAt(H) << d);
          H++;
        }
        Z = (H - (H % 4)) / 4;
        d = (H % 4) * 8;
        aa[Z] = aa[Z] | (128 << d);
        aa[I - 2] = F << 3;
        aa[I - 1] = F >>> 29;
        return aa;
      }
      function B(x) {
        let k = "",
          F = "",
          G,
          d;
        for (d = 0; d <= 3; d++) {
          G = (x >>> (d * 8)) & 255;
          F = "0" + G.toString(16);
          k = k + F.substr(F.length - 2, 2);
        }
        return k;
      }
      function J(k) {
        k = k.replace(/rn/g, "n");
        let d = "";
        for (let F = 0; F < k.length; F++) {
          let x = k.charCodeAt(F);
          if (x < 128) {
            d += String.fromCharCode(x);
          } else {
            if (x > 127 && x < 2048) {
              d += String.fromCharCode((x >> 6) | 192);
              d += String.fromCharCode((x & 63) | 128);
            } else {
              d += String.fromCharCode((x >> 12) | 224);
              d += String.fromCharCode(((x >> 6) & 63) | 128);
              d += String.fromCharCode((x & 63) | 128);
            }
          }
        }
        return d;
      }
      let C = Array();
      let P, h, E, v, g, Y, X, W, V;
      let S = 7,
        Q = 12,
        N = 17,
        M = 22;
      let A = 5,
        z = 9,
        y = 14,
        w = 20;
      let o = 4,
        m = 11,
        l = 16,
        j = 23;
      let U = 6,
        T = 10,
        R = 15,
        O = 21;
      s = J(s);
      C = e(s);
      Y = 1732584193;
      X = 4023233417;
      W = 2562383102;
      V = 271733878;
      for (P = 0; P < C.length; P += 16) {
        h = Y;
        E = X;
        v = W;
        g = V;
        Y = u(Y, X, W, V, C[P + 0], S, 3614090360);
        V = u(V, Y, X, W, C[P + 1], Q, 3905402710);
        W = u(W, V, Y, X, C[P + 2], N, 606105819);
        X = u(X, W, V, Y, C[P + 3], M, 3250441966);
        Y = u(Y, X, W, V, C[P + 4], S, 4118548399);
        V = u(V, Y, X, W, C[P + 5], Q, 1200080426);
        W = u(W, V, Y, X, C[P + 6], N, 2821735955);
        X = u(X, W, V, Y, C[P + 7], M, 4249261313);
        Y = u(Y, X, W, V, C[P + 8], S, 1770035416);
        V = u(V, Y, X, W, C[P + 9], Q, 2336552879);
        W = u(W, V, Y, X, C[P + 10], N, 4294925233);
        X = u(X, W, V, Y, C[P + 11], M, 2304563134);
        Y = u(Y, X, W, V, C[P + 12], S, 1804603682);
        V = u(V, Y, X, W, C[P + 13], Q, 4254626195);
        W = u(W, V, Y, X, C[P + 14], N, 2792965006);
        X = u(X, W, V, Y, C[P + 15], M, 1236535329);
        Y = f(Y, X, W, V, C[P + 1], A, 4129170786);
        V = f(V, Y, X, W, C[P + 6], z, 3225465664);
        W = f(W, V, Y, X, C[P + 11], y, 643717713);
        X = f(X, W, V, Y, C[P + 0], w, 3921069994);
        Y = f(Y, X, W, V, C[P + 5], A, 3593408605);
        V = f(V, Y, X, W, C[P + 10], z, 38016083);
        W = f(W, V, Y, X, C[P + 15], y, 3634488961);
        X = f(X, W, V, Y, C[P + 4], w, 3889429448);
        Y = f(Y, X, W, V, C[P + 9], A, 568446438);
        V = f(V, Y, X, W, C[P + 14], z, 3275163606);
        W = f(W, V, Y, X, C[P + 3], y, 4107603335);
        X = f(X, W, V, Y, C[P + 8], w, 1163531501);
        Y = f(Y, X, W, V, C[P + 13], A, 2850285829);
        V = f(V, Y, X, W, C[P + 2], z, 4243563512);
        W = f(W, V, Y, X, C[P + 7], y, 1735328473);
        X = f(X, W, V, Y, C[P + 12], w, 2368359562);
        Y = D(Y, X, W, V, C[P + 5], o, 4294588738);
        V = D(V, Y, X, W, C[P + 8], m, 2272392833);
        W = D(W, V, Y, X, C[P + 11], l, 1839030562);
        X = D(X, W, V, Y, C[P + 14], j, 4259657740);
        Y = D(Y, X, W, V, C[P + 1], o, 2763975236);
        V = D(V, Y, X, W, C[P + 4], m, 1272893353);
        W = D(W, V, Y, X, C[P + 7], l, 4139469664);
        X = D(X, W, V, Y, C[P + 10], j, 3200236656);
        Y = D(Y, X, W, V, C[P + 13], o, 681279174);
        V = D(V, Y, X, W, C[P + 0], m, 3936430074);
        W = D(W, V, Y, X, C[P + 3], l, 3572445317);
        X = D(X, W, V, Y, C[P + 6], j, 76029189);
        Y = D(Y, X, W, V, C[P + 9], o, 3654602809);
        V = D(V, Y, X, W, C[P + 12], m, 3873151461);
        W = D(W, V, Y, X, C[P + 15], l, 530742520);
        X = D(X, W, V, Y, C[P + 2], j, 3299628645);
        Y = t(Y, X, W, V, C[P + 0], U, 4096336452);
        V = t(V, Y, X, W, C[P + 7], T, 1126891415);
        W = t(W, V, Y, X, C[P + 14], R, 2878612391);
        X = t(X, W, V, Y, C[P + 5], O, 4237533241);
        Y = t(Y, X, W, V, C[P + 12], U, 1700485571);
        V = t(V, Y, X, W, C[P + 3], T, 2399980690);
        W = t(W, V, Y, X, C[P + 10], R, 4293915773);
        X = t(X, W, V, Y, C[P + 1], O, 2240044497);
        Y = t(Y, X, W, V, C[P + 8], U, 1873313359);
        V = t(V, Y, X, W, C[P + 15], T, 4264355552);
        W = t(W, V, Y, X, C[P + 6], R, 2734768916);
        X = t(X, W, V, Y, C[P + 13], O, 1309151649);
        Y = t(Y, X, W, V, C[P + 4], U, 4149444226);
        V = t(V, Y, X, W, C[P + 11], T, 3174756917);
        W = t(W, V, Y, X, C[P + 2], R, 718787259);
        X = t(X, W, V, Y, C[P + 9], O, 3951481745);
        Y = K(Y, h);
        X = K(X, E);
        W = K(W, v);
        V = K(V, g);
      }
      let i = B(Y) + B(X) + B(W) + B(V);
      return i.toLowerCase();
    };
    let size = element.dataset["size"] || 80;
    let email = $value.email || $value || "";
    let name = $value.name || $value || "";
    
    name = (typeof name !== 'string') ? '' : name;

    let theme = name
      .split("")
      .map(char => char.charCodeAt(0))
      .reduce((a, b) => a + b, 0)
      .toString();
    let themes = [
      { color: "27005e", background: "e1d2f6" }, // VIOLET
      { color: "5e2700", background: "f3d9c6" }, // ORANGE
      { color: "006128", background: "c9f3c6" }, // GREEN
      { color: "580061", background: "f2d1f5" }, // FUSCHIA
      { color: "00365d", background: "c6e1f3" }, // BLUE
      { color: "00075c", background: "d2d5f6" }, // INDIGO
      { color: "610038", background: "f5d1e6" }, // PINK
      { color: "386100", background: "dcf1bd" }, // LIME
      { color: "615800", background: "f1ecba" }, // YELLOW
      { color: "610008", background: "f6d2d5" } // RED
    ];

    name =
      name
        .split(" ")
        .map(function(n) {
          if (!isNaN(parseFloat(n)) && isFinite(n)) {
            return "";
          }

          return n[0];
        })
        .join("") || "--";

    let background = themes[theme[theme.length - 1]]["background"];
    let color = themes[theme[theme.length - 1]]["color"];

    let def =
      "https://ui-avatars.com/api/" +
      encodeURIComponent(name) +
      "/" +
      size +
      "/" +
      encodeURIComponent(background) +
      "/" +
      encodeURIComponent(color);

    return (
      "//www.gravatar.com/avatar/" +
      MD5(email) +
      ".jpg?s=" +
      size +
      "&d=" +
      encodeURIComponent(def)
    );
  })
  .add("selectedCollection", function($value, router) {
    return $value === router.params.collectionId ? "selected" : "";
  })
  .add("selectedDocument", function($value, router) {
    return $value === router.params.documentId ? "selected" : "";
  })
  .add("localeString", function($value) {
    $value = parseInt($value);
    return !Number.isNaN($value) ? $value.toLocaleString() : "";
  })
  .add("date", function($value, date) {
    return date.format("Y-m-d", $value);
  })
  .add("date-time", function($value, date) {
    return date.format("Y-m-d H:i", $value);
  })
  .add("date-text", function($value, date) {
    return date.format("d M Y", $value);
  })
  .add("ms2hum", function($value) {
    let temp = $value;
    const years = Math.floor(temp / 31536000),
      days = Math.floor((temp %= 31536000) / 86400),
      hours = Math.floor((temp %= 86400) / 3600),
      minutes = Math.floor((temp %= 3600) / 60),
      seconds = temp % 60;

    if (days || hours || seconds || minutes) {
      return (
        (years ? years + "y " : "") +
        (days ? days + "d " : "") +
        (hours ? hours + "h " : "") +
        (minutes ? minutes + "m " : "") +
        Number.parseFloat(seconds).toFixed(0) +
        "s"
      );
    }

    return "< 1s";
  })
  .add("markdown", function($value, markdown) {
    return markdown.render($value);
  })
  .add("pageCurrent", function($value, env) {
    return Math.ceil(parseInt($value || 0) / env.PAGING_LIMIT) + 1;
  })
  .add("pageTotal", function($value, env) {
    let total = Math.ceil(parseInt($value || 0) / env.PAGING_LIMIT);
    return total ? total : 1;
  })
  .add("humanFileSize", function($value) {
    if (!$value) {
      return 0;
    }

    let thresh = 1000;

    if (Math.abs($value) < thresh) {
      return $value + " B";
    }

    let units = ["kB", "MB", "GB", "TB", "PB", "EB", "ZB", "YB"];
    let u = -1;

    do {
      $value /= thresh;
      ++u;
    } while (Math.abs($value) >= thresh && u < units.length - 1);

    return (
      $value.toFixed(1) +
      '<span class="text-size-small unit">' +
      units[u] +
      "</span>"
    );
  })
  .add("statsTotal", function($value) {
    if (!$value) {
      return 0;
    }

    $value = abbreviate($value, 1, false, false);

    return $value === "0" ? "N/A" : $value;
  })
  .add("isEmptyObject", function($value) {
    return ((Object.keys($value).length === 0 && $value.constructor === Object) || $value.length === 0)
  })
  .add("activeDomainsCount", function($value) {
    let result = [];
    
    if(Array.isArray($value)) {
      result = $value.filter(function(node) {
        return (node.verification && node.certificateId);
      });
    }

    return result.length;
  })
  .add("platformsLimit", function($value) {

    console.log('test console');
    console.log($value);
    
    return $value;
  });

function abbreviate(number, maxPlaces, forcePlaces, forceLetter) {
  number = Number(number);
  forceLetter = forceLetter || false;
  if (forceLetter !== false) {
    return annotate(number, maxPlaces, forcePlaces, forceLetter);
  }
  let abbr;
  if (number >= 1e12) {
    abbr = "T";
  } else if (number >= 1e9) {
    abbr = "B";
  } else if (number >= 1e6) {
    abbr = "M";
  } else if (number >= 1e3) {
    abbr = "K";
  } else {
    abbr = "";
  }
  return annotate(number, maxPlaces, forcePlaces, abbr);
}

function annotate(number, maxPlaces, forcePlaces, abbr) {
  // set places to false to not round
  let rounded = 0;
  switch (abbr) {
    case "T":
      rounded = number / 1e12;
      break;
    case "B":
      rounded = number / 1e9;
      break;
    case "M":
      rounded = number / 1e6;
      break;
    case "K":
      rounded = number / 1e3;
      break;
    case "":
      rounded = number;
      break;
  }
  if (maxPlaces !== false) {
    let test = new RegExp("\\.\\d{" + (maxPlaces + 1) + ",}$");
    if (test.test("" + rounded)) {
      rounded = rounded.toFixed(maxPlaces);
    }
  }
  if (forcePlaces !== false) {
    rounded = Number(rounded).toFixed(forcePlaces);
  }
  return rounded + abbr;
}
