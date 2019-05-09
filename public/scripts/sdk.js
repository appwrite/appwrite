// XDOMAIN
(function(a,b){(function(a,b){var c,d,e,f,g,h,i,j,k,l,m,n,o,p,q,r,s,t,u,v,w,x,y,z,A,B=[].indexOf||function(a){for(var b=0,c=this.length;c>b;b++)if(b in this&&this[b]===a)return b;return-1};t=a.document,d="before",c="after",m="readyState",l="addEventListener",k="removeEventListener",g="dispatchEvent",q="XMLHttpRequest",h="FormData",n=["load","loadend","loadstart"],e=["progress","abort","error","timeout"],w=parseInt((/msie (\d+)/.exec(navigator.userAgent.toLowerCase())||[])[1]),isNaN(w)&&(w=parseInt((/trident\/.*; rv:(\d+)/.exec(navigator.userAgent.toLowerCase())||[])[1])),(A=Array.prototype).indexOf||(A.indexOf=function(a){var b,c,d,e;for(b=d=0,e=this.length;e>d;b=++d)if(c=this[b],c===a)return b;return-1}),y=function(a,b){return Array.prototype.slice.call(a,b)},s=function(a){return"returnValue"===a||"totalSize"===a||"position"===a},v=function(a,b){var c,d;for(c in a)if(d=a[c],!s(c))try{b[c]=a[c]}catch(e){}return b},x=function(a,b,c){var d,e,f,h;for(e=function(a){return function(d){var e,f,h;e={};for(f in d)s(f)||(h=d[f],e[f]=h===b?c:h);return c[g](a,e)}},f=0,h=a.length;h>f;f++)d=a[f],c._has(d)&&(b["on"+d]=e(d))},u=function(a){var b;if(null!=t.createEventObject)return b=t.createEventObject(),b.type=a,b;try{return new Event(a)}catch(c){return{type:a}}},f=function(a){var c,d,e;return d={},e=function(a){return d[a]||[]},c={},c[l]=function(a,c,f){d[a]=e(a),d[a].indexOf(c)>=0||(f=f===b?d[a].length:f,d[a].splice(f,0,c))},c[k]=function(a,c){var f;return a===b?void(d={}):(c===b&&(d[a]=[]),f=e(a).indexOf(c),void(-1!==f&&e(a).splice(f,1)))},c[g]=function(){var b,d,f,g,h,i,j,k;for(b=y(arguments),d=b.shift(),a||(b[0]=v(b[0],u(d))),g=c["on"+d],g&&g.apply(c,b),k=e(d).concat(e("*")),f=i=0,j=k.length;j>i;f=++i)h=k[f],h.apply(c,b)},c._has=function(a){return!(!d[a]&&!c["on"+a])},a&&(c.listeners=function(a){return y(e(a))},c.on=c[l],c.off=c[k],c.fire=c[g],c.once=function(a,b){var d;return d=function(){return c.off(a,d),b.apply(null,arguments)},c.on(a,d)},c.destroy=function(){return d={}}),c},z=f(!0),z.EventEmitter=f,z[d]=function(a,b){if(a.length<1||a.length>2)throw"invalid hook";return z[l](d,a,b)},z[c]=function(a,b){if(a.length<2||a.length>3)throw"invalid hook";return z[l](c,a,b)},z.enable=function(){a[q]=p,i&&(a[h]=o)},z.disable=function(){a[q]=z[q],i&&(a[h]=i)},r=z.headers=function(a,b){var c,d,e,f,g,h,i,j,k;switch(null==b&&(b={}),typeof a){case"object":d=[];for(e in a)g=a[e],f=e.toLowerCase(),d.push(""+f+":	"+g);return d.join("\n");case"string":for(d=a.split("\n"),i=0,j=d.length;j>i;i++)c=d[i],/([^:]+):\s*(.+)/.test(c)&&(f=null!=(k=RegExp.$1)?k.toLowerCase():void 0,h=RegExp.$2,null==b[f]&&(b[f]=h));return b}},i=a[h],o=function(a){var b;this.fd=a?new i(a):new i,this.form=a,b=[],Object.defineProperty(this,"entries",{get:function(){var c;return c=a?y(a.querySelectorAll("input,select")).filter(function(a){var b;return"checkbox"!==(b=a.type)&&"radio"!==b||a.checked}).map(function(a){return[a.name,"file"===a.type?a.files:a.value]}):[],c.concat(b)}}),this.append=function(a){return function(){var c;return c=y(arguments),b.push(c),a.fd.append.apply(a.fd,c)}}(this)},i&&(z[h]=i,a[h]=o),j=a[q],z[q]=j,p=a[q]=function(){var a,b,h,i,j,k,p,s,t,u,y,A,C,D,E,F,G,H,I,J,K;a=-1,H=new z[q],y={},D=null,p=void 0,E=void 0,A=void 0,u=function(){var b,c,d,e;if(A.status=D||H.status,D===a&&10>w||(A.statusText=H.statusText),D!==a){e=r(H.getAllResponseHeaders());for(b in e)d=e[b],A.headers[b]||(c=b.toLowerCase(),A.headers[c]=d)}},t=function(){H.responseType&&"text"!==H.responseType?"document"===H.responseType?(A.xml=H.responseXML,A.data=H.responseXML):A.data=H.response:(A.text=H.responseText,A.data=H.responseText),"responseURL"in H&&(A.finalUrl=H.responseURL)},G=function(){k.status=A.status,k.statusText=A.statusText},F=function(){"text"in A&&(k.responseText=A.text),"xml"in A&&(k.responseXML=A.xml),"data"in A&&(k.response=A.data),"finalUrl"in A&&(k.responseURL=A.finalUrl)},i=function(a){for(;a>b&&4>b;)k[m]=++b,1===b&&k[g]("loadstart",{}),2===b&&G(),4===b&&(G(),F()),k[g]("readystatechange",{}),4===b&&setTimeout(h,0)},h=function(){p||k[g]("load",{}),k[g]("loadend",{}),p&&(k[m]=0)},b=0,C=function(a){var b,d;return 4!==a?void i(a):(b=z.listeners(c),d=function(){var a;return b.length?(a=b.shift(),2===a.length?(a(y,A),d()):3===a.length&&y.async?a(y,A,d):d()):i(4)},void d())},k=y.xhr=f(),H.onreadystatechange=function(){try{2===H[m]&&u()}catch(a){}4===H[m]&&(E=!1,u(),t()),C(H[m])},s=function(){p=!0},k[l]("error",s),k[l]("timeout",s),k[l]("abort",s),k[l]("progress",function(){3>b?C(3):k[g]("readystatechange",{})}),("withCredentials"in H||z.addWithCredentials)&&(k.withCredentials=!1),k.status=0,K=e.concat(n);for(I=0,J=K.length;J>I;I++)j=K[I],k["on"+j]=null;return k.open=function(a,c,d,e,f){b=0,p=!1,E=!1,y.headers={},y.headerNames={},y.status=0,A={},A.headers={},y.method=a,y.url=c,y.async=d!==!1,y.user=e,y.pass=f,C(1)},k.send=function(a){var b,c,f,g,h,i,j,l;for(l=["type","timeout","withCredentials"],i=0,j=l.length;j>i;i++)c=l[i],f="type"===c?"responseType":c,f in k&&(y[c]=k[f]);y.body=a,h=function(){var a,b,d,g,h,i;for(x(e,H,k),k.upload&&x(e.concat(n),H.upload,k.upload),E=!0,H.open(y.method,y.url,y.async,y.user,y.pass),h=["type","timeout","withCredentials"],d=0,g=h.length;g>d;d++)c=h[d],f="type"===c?"responseType":c,c in y&&(H[f]=y[c]);i=y.headers;for(a in i)b=i[a],a&&H.setRequestHeader(a,b);y.body instanceof o&&(y.body=y.body.fd),H.send(y.body)},b=z.listeners(d),(g=function(){var a,c;return b.length?(a=function(a){return"object"!=typeof a||"number"!=typeof a.status&&"number"!=typeof A.status?void g():(v(a,A),B.call(a,"data")<0&&(a.data=a.response||a.text),void C(4))},a.head=function(a){return v(a,A),C(2)},a.progress=function(a){return v(a,A),C(3)},c=b.shift(),1===c.length?a(c(y)):2===c.length&&y.async?c(y,a):a()):h()})()},k.abort=function(){D=a,E?H.abort():k[g]("abort",{})},k.setRequestHeader=function(a,b){var c,d;c=null!=a?a.toLowerCase():void 0,d=y.headerNames[c]=y.headerNames[c]||a,y.headers[d]&&(b=y.headers[d]+", "+b),y.headers[d]=b},k.getResponseHeader=function(a){var b;return b=null!=a?a.toLowerCase():void 0,A.headers[b]},k.getAllResponseHeaders=function(){return r(A.headers)},H.overrideMimeType&&(k.overrideMimeType=function(){return H.overrideMimeType.apply(H,arguments)}),H.upload&&(k.upload=y.upload=f()),k},"function"==typeof define&&define.amd?define("xhook",[],function(){return z}):(this.exports||this).xhook=z}).call(this,a);var c,d,e,f,g,h,i,j,k,l,m,n,o,p,q,r,s,t,u,v,w,x,y,z,A,B,C,D,E,F,G,H,I,J,K,L,M,N,O;s=!1,D=function(a){var b,c;s||q();for(b in a)c=a[b],x("adding slave: "+b),D[b]=c},m={},n=function(a,b){var c;return m[a]?m[a]:(c=j.createElement("iframe"),c.id=c.name=o(),x("creating iframe "+c.id),c.src=""+a+b,c.setAttribute("style","display:none;"),j.body.appendChild(c),m[a]=c.contentWindow)},q=function(){var a,b,c;return s=!0,b=function(a,b){var c,d,e,f,g;return e=a[0],f=a[1],c=u(f,"Blob"),d=u(f,"File"),c||d?(g=new FileReader,g.onload=function(){return a[1]=null,d&&(a[2]=f.name),b(["XD_BLOB",a,this.result,f.type])},g.readAsArrayBuffer(f),1):0},a=function(a,c){var d;a.forEach(function(b,c){var d,e,f,g,h;if(e=b[0],f=b[1],u(f,"FileList"))for(a.splice(c,1),g=0,h=f.length;h>g;g++)d=f[g],a.splice(c,0,[e,d])}),d=0,a.forEach(function(e,f){d+=b(e,function(b){a[f]=b,0===--d&&c()})}),0===d&&c()},c=function(b,c){var d,e,f;return c.on("xhr-event",function(){return b.xhr.dispatchEvent.apply(null,arguments)}),c.on("xhr-upload-event",function(){return b.xhr.upload.dispatchEvent.apply(null,arguments)}),e=H(b),e.headers=b.headers,b.withCredentials&&(g.master&&(e.headers[g.master]=j.cookie),e.slaveCookie=g.slave),f=function(){return c.emit("request",e)},b.body&&(e.body=b.body,u(e.body,"FormData"))?(d=e.body.entries,e.body=["XD_FD",d],void a(d,f)):void f()},"addWithCredentials"in L||(L.addWithCredentials=!0),L.before(function(a,b){var d,e,f;return e=B(a.url),e&&e.origin!==i?D[e.origin]?(x("proxying request to slave: '"+e.origin+"'"),a.async===!1?(J("sync not supported"),b()):(d=n(e.origin,D[e.origin]),f=h(o(),d),f.on("response",function(a){return b(a),f.close()}),a.xhr.addEventListener("abort",function(){return f.emit("abort")}),void(f.ready?c(a,f):f.once("ready",function(){return c(a,f)})))):(e&&x("no slave matching: '"+e.origin+"'"),b()):b()})},t=!1,z=function(a){var b,c;t||r();for(b in a)c=a[b],x("adding master: "+b),z[b]=c},p=null,r=function(){return t=!0,x("handling incoming sockets..."),p=function(a,b){var c,d,e,f;"null"===a&&(a="*"),e=null;for(c in z){f=z[c];try{if(d=I(c),d.test(a)){e=I(f);break}}catch(g){}}return e?(b.once("request",function(a){var c,d,f,g,h,i,k,l,m,n,o;if(x("request: "+a.method+" "+a.url),i=B(a.url),!i||!e.test(i.path))return J("blocked request to path: '"+i.path+"' by regex: "+e),void b.close();l=new XMLHttpRequest,l.open(a.method,a.url),l.addEventListener("*",function(a){return b.emit("xhr-event",a.type,H(a))}),l.upload&&l.upload.addEventListener("*",function(a){return b.emit("xhr-upload-event",a.type,H(a))}),b.once("abort",function(){return l.abort()}),l.onreadystatechange=function(){var a;if(4===l.readyState){a={status:l.status,statusText:l.statusText,data:l.response,headers:L.headers(l.getAllResponseHeaders())};try{a.text=l.responseText}catch(c){}return b.emit("response",a)}},a.withCredentials&&(l.withCredentials=!0,a.slaveCookie&&(a.headers[a.slaveCookie]=j.cookie)),a.timeout&&(l.timeout=a.timeout),a.type&&(l.responseType=a.type),o=a.headers;for(h in o)k=o[h],l.setRequestHeader(h,k);if(a.body instanceof Array&&"XD_FD"===a.body[0]){for(g=new L.FormData,f=a.body[1],m=0,n=f.length;n>m;m++)c=f[m],"XD_BLOB"===c[0]&&4===c.length&&(d=new Blob([c[2]],{type:c[3]}),c=c[1],c[1]=d),g.append.apply(g,c);a.body=g}l.send(a.body||null)}),void x("slave listening for requests on socket: "+b.id)):void J("blocked request from: '"+a+"'")},a===a.parent?J("slaves must be in an iframe"):a.parent.postMessage("XDPING_"+d,"*")},e="XD_CHECK",F={},v=!0,h=function(a,b){var d,f,g,h,i,j;return i=!1,j=F[a]=L.EventEmitter(!0),j.id=a,j.once("close",function(){return j.destroy(),j.close()}),h=[],j.emit=function(){var b,c;b=E(arguments),c="string"==typeof b[1]?" -> "+b[1]:"",x("send socket: "+a+": "+b[0]+c),b.unshift(a),i?g(b):h.push(b)},g=function(a){v&&(a=JSON.stringify(a)),b.postMessage(a,"*")},j.close=function(){j.emit("close"),x("close socket: "+a),F[a]=null},j.once(e,function(b){for(v="string"==typeof b,i=j.ready=!0,j.emit("ready"),x("ready socket: "+a+" (emit #"+h.length+" pending)");h.length;)g(h.shift())}),f=0,d=function(){return function(){b.postMessage([a,e,{}],"*"),i||(f++>=K.timeout/c?(J("Timeout waiting on iframe socket"),k.fire("timeout"),j.fire("abort")):setTimeout(d,c))}}(this),setTimeout(d),x("new socket: "+a),j},A=function(b){return j.addEventListener?a.addEventListener("message",b):a.attachEvent("onmessage",b)},G=function(){return A(function(a){var c,e,f,g;if(c=a.data,"string"==typeof c){if(/^XDPING(_(V\d+))?$/.test(c)&&RegExp.$2!==d)return J("your master is not compatible with your slave, check your xdomain.js version");if(/^xdomain-/.test(c))c=c.split(",");else if(v)try{c=JSON.parse(c)}catch(i){return}}if(c instanceof Array&&(f=c.shift(),/^xdomain-/.test(f)&&(g=F[f],null!==g))){if(g===b){if(!p)return;g=h(f,a.source),p(a.origin,g)}e="string"==typeof c[1]?" -> "+c[1]:"",x("receive socket: "+f+": "+c[0]+e),g.fire.apply(g,c)}})},L=(this.exports||this).xhook,K=function(a){a&&(a.masters&&z(a.masters),a.slaves&&D(a.slaves))},K.masters=z,K.slaves=D,K.debug=!1,K.timeout=15e3,c=100,g=K.cookies={master:"Master-Cookie",slave:"Slave-Cookie"},j=a.document,w=a.location,i=K.origin=w.protocol+"//"+w.host,o=function(){return"xdomain-"+Math.round(Math.random()*Math.pow(2,32)).toString(16)},E=function(a,b){return Array.prototype.slice.call(a,b)},f=a.console||{},k=null,C=function(){k=L.EventEmitter(!0),K.on=k.on,K.off=k.off},L&&C(),y=function(a){return function(b){b="xdomain ("+i+"): "+b,k.fire(a,b),("log"!==a||K.debug)&&(a in K?K[a](b):a in f?f[a](b):"warn"===a&&alert(b))}},x=y("log"),J=y("warn"),O=["postMessage","JSON"];for(M=0,N=O.length;N>M;M++)if(l=O[M],!a[l])return void J("requires '"+l+"' and this browser does not support it");u=function(b,c){return c in a?b instanceof a[c]:!1},d="V1",B=K.parseUrl=function(a){return/^((https?:)?\/\/[^\/\?]+)(\/.*)?/.test(a)?{origin:(RegExp.$2?"":w.protocol)+RegExp.$1,path:RegExp.$3}:(x("failed to parse absolute url: "+a),null)},I=function(a){var b;return a instanceof RegExp?a:(b=a.toString().replace(/\W/g,function(a){return"\\"+a}).replace(/\\\*/g,".*"),new RegExp("^"+b+"$"))},H=function(a){var b,c,d,e;b={};for(c in a)"returnValue"!==c&&(d=a[c],"function"!=(e=typeof d)&&"object"!==e&&(b[c]=d));return b},function(){var a,b,c,d,e,f,g,h,i,k,l;for(a={debug:function(a){return"string"==typeof a?K.debug="false"!==a:void 0},slave:function(a){var b,c;if(a&&(b=B(a)))return c={},c[b.origin]=b.path,D(c)},master:function(a){var b,c;if(a&&(c="*"===a?{origin:"*",path:"*"}:B(a)))return b={},b[c.origin]=c.path.replace(/^\//,"")?c.path:"*",z(b)}},k=j.getElementsByTagName("script"),f=0,h=k.length;h>f;f++)if(e=k[f],/xdomain/.test(e.src))for(l=["","data-"],g=0,i=l.length;i>g;g++){d=l[g];for(c in a)(b=a[c])(e.getAttribute(d+c))}}(),G(),"function"==typeof define&&define.amd?define("xdomain",["xhook"],function(a){return L=a,C(),K}):(this.exports||this).xdomain=K}).call(this,window);

xdomain.slaves({
    "https://appwrite.test":"/v1/proxy?v=1&project=" + APP_ENV.PROJECT,
    "https://appwrite.io":"/v1/proxy?v=1&project=" + APP_ENV.PROJECT
});

(function (window) {
    window.AppwriteSDK = function () {
        let config  = {
            domain: 'https://appwrite.io',
            version: 'v1',
            project: 0,
            locale: 'en',
            mode: 'default'
        };

        let http = function(document) {
            let globalParams    = [],
                globalHeaders   = [];

            let addParam = function(url, param, value) {
                let a = document.createElement('a'), regex = /(?:\?|&amp;|&)+([^=]+)(?:=([^&]*))*/g;
                let match, str = []; a.href = url; param = encodeURIComponent(param);

                while (match = regex.exec(a.search))
                    if (param !== match[1]) str.push(match[1]+(match[2]?"="+match[2]:""));

                str.push(param+(value?"="+ encodeURIComponent(value):""));

                a.search = str.join("&");

                return a.href;
            };

            /**
             * @param method string
             * @param url string
             * @param headers string
             * @param payload string
             * @param progress callback
             * @returns Promise
             */
            let request = function(method, url, headers, payload, progress) {
                let i;

                http.addGlobalHeader('X-Appwrite-Project', config.project);
                http.addGlobalHeader('X-Appwrite-Locale', config.locale);
                http.addGlobalHeader('X-Appwrite-Mode', config.mode);

                if(-1 === ['GET', 'POST', 'PUT', 'DELETE', 'TRACE', 'HEAD', 'OPTIONS', 'CONNECT', 'PATCH'].indexOf(method)) {
                    throw new Error('let method must contain a valid HTTP method name');
                }

                if(typeof url !== 'string') {
                    throw new Error('let url must be of type string');
                }

                if(typeof headers !== 'object') {
                    throw new Error('let headers must be of type object');
                }

                if(typeof url !== 'string') {
                    throw new Error('let url must be of type string');
                }

                for (i = 0; i < globalParams.length; i++) { // Add global params to URL
                    url = addParam(url, globalParams[i].key, globalParams[i].value);
                }

                /*
                let headersObj = new Headers({
                    'Ajax': '1'
                });

                for (let keyX in globalHeaders) {
                    if (globalHeaders.hasOwnProperty(keyX)) {
                        headersObj.append(globalHeaders[keyX].key, globalHeaders[keyX].value);
                    }
                }

                // Set Headers
                for (let keyY in headers) {
                    if (headers.hasOwnProperty(keyY)) {
                        headersObj.append(keyY, headers[keyY]);
                    }
                }

                let request = new Request(url, {
                    method: method,
                    mode: 'cors',
                    credentials: 'include',
                    redirect: 'follow',
                    headers: headersObj
                });

                return fetch(request).then(function(response) {
                    if(progress) {
                        progress();
                    }
                    return response.text();
                });
                */ // Fetch API

                let xmlhttp = new XMLHttpRequest(), key;

                let promise = new Promise(
                    function(resolve, reject) {
                        xmlhttp.open(method, url, true);

                        xmlhttp.setRequestHeader('Ajax', '1');

                        for (key in globalHeaders) {
                            if (globalHeaders.hasOwnProperty(key)) {
                                xmlhttp.setRequestHeader(globalHeaders[key].key, globalHeaders[key].value);
                            }
                        }

                        // Set Headers
                        for (key in headers) {
                            if (headers.hasOwnProperty(key)) {
                                xmlhttp.setRequestHeader(key, headers[key]);
                            }
                        }

                        xmlhttp.onload = function() {
                            if (4 === xmlhttp.readyState && 399 >= xmlhttp.status) {
                                resolve(xmlhttp.response);
                            }
                            else {
                                reject(new Error(xmlhttp.statusText));
                            }
                        };

                        if(progress) {
                            xmlhttp.addEventListener('progress', progress);
                            xmlhttp.upload.addEventListener('progress', progress, false);
                        }

                        // Handle network errors
                        xmlhttp.onerror = function() {
                            reject(new Error("Network Error"));
                        };

                        xmlhttp.send(payload);
                    }
                );

                promise.abort = function () {
                    xmlhttp.abort();
                };

                return promise;
            };

            return {
                'get': function(url) {
                    return request('GET', url, {}, '')
                },
                'post': function(url, headers, payload, progress) {
                    return request('POST', url, headers, payload, progress)
                },
                'put': function(url, headers, payload) {
                    return request('PUT', url, headers, payload)
                },
                'patch': function(url, headers, payload) {
                    return request('PATCH', url, headers, payload)
                },
                'delete': function(url) {
                    return request('DELETE', url, {}, '')
                },
                'addGlobalParam': function(key, value) {
                    globalParams.push({key: key, value: value});
                },
                'addGlobalHeader': function(key, value) {
                    globalHeaders[key] = {key: key, value: value};
                },
                'version': '1.0.0'
            }
        }(window.document);

        let iframe = function(method, url, params) {
            let form = document.createElement('form');

            form.setAttribute('method', method);
            form.setAttribute('action', url);

            for(let key in params) {
                if(params.hasOwnProperty(key)) {
                    let hiddenField = document.createElement("input");
                    hiddenField.setAttribute("type", "hidden");
                    hiddenField.setAttribute("name", key);
                    hiddenField.setAttribute("value", params[key]);

                    form.appendChild(hiddenField);
                }
            }

            document.body.appendChild(form);

            return form.submit();
        };

        let auth = {
            register: function(email, password, name, redirect, success, failure) {
                // Fix for 3rd party cookies issue on some browsers
                return iframe('post', config.domain + '/' + config.version +  '/auth/register', {project: config.project, 'email': email, 'password': password, 'name': name, 'redirect': redirect, success: success, failure: failure});

                //return http
                //    .post(config.domain + '/' + config.version + '/auth/register', {'Content-type': 'application/json'},
                //        JSON.stringify({'email': email, 'password': password, 'name': name, 'redirect': redirect}));
            },

            confirm: function(userId, token) {
                return http
                    .post(config.domain + '/' + config.version + '/auth/register/confirm', {'Content-type': 'application/json'},
                        JSON.stringify({'userId': userId, 'token': token}));
            },

            confirmResend: function(redirect) {
                return http
                    .post(config.domain + '/' + config.version + '/auth/register/confirm/resend', {'Content-type': 'application/json'},
                        JSON.stringify({'redirect': redirect}));
            },

            login: function(email, password, success, failure) {
                // Fix for 3rd party cookies issue on some browsers

                return iframe('post', config.domain + '/' + config.version +  '/auth/login', {project: config.project, email: email, password: password, success: success, failure: failure});

                //return http
                //    .post(config.domain + '/' + config.version +  '/auth/login?project=' + config.project, {'Content-type': 'application/json'},
                //        JSON.stringify({'email': email, 'password': password}));
            },

            invite: function(team, name, email, roles, redirect) {
                return http
                    .post(config.domain + '/' + config.version +  '/auth/invite', {'Content-type': 'application/json'},
                        JSON.stringify({'team': team, 'name': name, 'email': email, 'roles': roles, 'redirect': redirect}));
            },

            inviteResend: function(inviteId, redirect) {
                return http
                    .post(config.domain + '/' + config.version +  '/auth/invite/resend', {'Content-type': 'application/json'},
                        JSON.stringify({'inviteId': inviteId, 'redirect': redirect}));
            },

            join: function(inviteId, userId, secret, success, failure) {
                // Fix for 3rd party cookies issue on some browsers

                return iframe('post', config.domain + '/' + config.version +  '/auth/join', {project: config.project, inviteId: inviteId, userId: userId, secret: secret, success: success, failure: failure});

                //return http
                //    .post(config.domain + '/' + config.version +  '/auth/join', {'Content-type': 'application/json'},
                //        JSON.stringify({'inviteId': inviteId, 'userId': userId, 'secret': secret}));
            },

            leave: function(inviteId) {
                return http
                    .delete(config.domain + '/' + config.version +  '/auth/leave/' + inviteId);
            },

            loginWithFacebook: function(success, failure) {
                return config.domain + '/' + config.version + '/oauth/facebook?project=' + config.project + '&success=' + encodeURI(success) + '&failure=' + encodeURI(failure);
            },

            loginWithGithub: function(success, failure) {
                return config.domain + '/' + config.version + '/oauth/github?project=' + config.project + '&success=' + encodeURI(success) + '&failure=' + encodeURI(failure);
            },

            loginWithTwitter: function(success, failure) {
                return config.domain + '/' + config.version + '/oauth/twitter?project=' + config.project + '&success=' + encodeURI(success) + '&failure=' + encodeURI(failure);
            },

            loginWithLinkedIn: function(success, failure) {
                return config.domain + '/' + config.version + '/oauth/linkedin?project=' + config.project + '&success=' + encodeURI(success) + '&failure=' + encodeURI(failure);
            },

            logout: function() {
                return http
                    .delete(config.domain + '/' + config.version + '/auth/logout');
            },

            logoutById: function(userId) {
                return http
                    .delete(config.domain + '/' + config.version + '/auth/logout/' + userId);
            },

            recovery: function(email, redirect) {
                return http
                    .post(config.domain + '/' + config.version + '/auth/recovery', {'Content-type': 'application/json'},
                        JSON.stringify({'email': email, 'redirect': redirect}));
            },

            recoveryReset: function(userId, token, passwordA, passwordB) {
                return http
                    .put(config.domain + '/' + config.version + '/auth/recovery/reset', {'Content-type': 'application/json'},
                        JSON.stringify({'userId': userId, 'token': token, 'password-a': passwordA, 'password-b': passwordB}));
            }
        };

        let account = {
            get: function() {
                return http
                    .get(config.domain + '/' + config.version + '/account');
            },
            prefs: function() {
                return http
                    .get(config.domain + '/' + config.version + '/account/prefs');
            },
            sessions: function() {
                return http
                    .get(config.domain + '/' + config.version + '/account/sessions');
            },
            security: function() {
                return http
                    .get(config.domain + '/' + config.version + '/account/security');
            },
            updateName: function(name) {
                return http
                    .patch(config.domain + '/' + config.version + '/account/name', {'Content-type': 'application/json'}, JSON.stringify({'name': name}));
            },
            updateEmail: function(email, password) {
                return http
                    .patch(config.domain + '/' + config.version + '/account/email', {'Content-type': 'application/json'}, JSON.stringify({'email': email, 'password': password}));
            },
            updatePassword: function(password, oldPassword) {
                return http
                    .patch(config.domain + '/' + config.version + '/account/password', {'Content-type': 'application/json'}, JSON.stringify({'password': password, 'old-password': oldPassword}));
            },
            updatePrefs: function(prefs) {
                return http
                    .patch(config.domain + '/' + config.version + '/account/prefs', {'Content-type': 'application/json'}, JSON.stringify({'prefs': prefs}));
            },
            deactivate: function() {
                return http
                    .delete(config.domain + '/' + config.version + '/account');
            },
        };

        let users = {
            list: function(search, limit, offset) {
                return http
                    .get(config.domain + '/' + config.version + '/users?search=' + search + '&limit=' + limit + '&offset=' + offset);
            },
            get: function(userId) {
                return http
                    .get(config.domain + '/' + config.version + '/users/' + userId);
            },
            getPrefs: function(userId) {
                return http
                    .get(config.domain + '/' + config.version + '/users/' + userId + '/prefs');
            },
            getSessions: function(userId) {
                return http
                    .get(config.domain + '/' + config.version + '/users/' + userId + '/sessions');
            },
            getLogs: function(userId) {
                return http
                    .get(config.domain + '/' + config.version + '/users/' + userId + '/logs');
            },
            create: function(email, password, name) {
                return http
                    .post(config.domain + '/' + config.version + '/users', {'Content-type': 'application/json'}, JSON.stringify({email: email, password: password, name: name}));
            },
            updateStatus: function(userId, status) {
                return http
                    .patch(config.domain + '/' + config.version + '/users/' + userId + '/status', {'Content-type': 'application/json'}, JSON.stringify({'status': status}));
            },
            delete: function(userId) {
                return http
                    .delete(config.domain + '/' + config.version + '/teams/' + userId);
            },
            deleteSession: function(userId, sessionId) {
                return http
                    .delete(config.domain + '/' + config.version + '/users/' + userId + '/sessions/' + sessionId);
            },
            deleteSessions: function(userId) {
                return http
                    .delete(config.domain + '/' + config.version + '/users/' + userId + '/sessions');
            }
        };

        let teams = {
            list: function(search, limit, offset) {
                return http
                    .get(config.domain + '/' + config.version + '/teams?search=' + search + '&limit=' + limit + '&offset=' + offset);
            },
            get: function(teamId) {
                return http
                    .get(config.domain + '/' + config.version + '/teams/' + teamId);
            },
            getMembers: function(teamId) {
                return http
                    .get(config.domain + '/' + config.version + '/teams/' + teamId + '/members');
            },
            create: function(name, parent) {
                return http
                    .post(config.domain + '/' + config.version + '/teams', {'Content-type': 'application/json'}, JSON.stringify({name: name, parent: parent}));
            },
            update: function(teamId, name) {
                return http
                    .put(config.domain + '/' + config.version + '/teams/' + teamId, {'Content-type': 'application/json'}, JSON.stringify({'name': name}));
            },
            delete: function(teamId) {
                return http
                    .delete(config.domain + '/' + config.version + '/teams/' + teamId);
            }
        };

        let storage = {
            getDownload: function (fileId) {
                return config.domain + '/' + config.version + '/storage/files/' + fileId + '/download?project=' + config.project;
            },
            getPreview: function (fileId, token, width, height) {
                let params = [];

                if(token) {
                    params.push('token=' + token);
                }

                if(width) {
                    params.push('width=' + width);
                }

                if(height) {
                    params.push('height=' + height);
                }

                if(0 < params.length) {
                    params.unshift('');
                }

                return config.domain + '/' + config.version + '/storage/files/' + fileId + '/preview/?project=' + config.project + params.join('&');
            },
            create: function(data, progress) {
                if(!data instanceof FormData) {
                    throw new Error('Upload method is expecting a FormData payload');
                }

                return http
                    .post(config.domain + '/' + config.version + '/storage/files', {}, data, progress);
            },
            files: {
                list: function(search, limit, offset, orderType) {
                    return http
                        .get(config.domain + '/' + config.version + '/storage/files?search=' + search + '&limit=' + limit + '&offset=' + offset + '&orderType=' + orderType);
                },
                get: function(fileId) {
                    return http
                        .get(config.domain + '/' + config.version + '/storage/files/' + fileId);
                },
                delete: function(fileId) {
                    return http
                        .delete(config.domain + '/' + config.version + '/storage/files/' + fileId);
                }
            }
        };

        let database = {
            getCollection: function(collection, query) {
                return http
                    .get(config.domain + '/' + config.version + '/database/' + collection + ((query) ? '?' : '') + query);
            },
            getDocument: function(collection, document) {
                return http
                    .get(config.domain + '/' + config.version + '/database/' + collection + '/' + document);
            },
            createDocument: function(collection, data, parentDocument, parentProperty, parentPropertyType) {
                return http
                    .post(config.domain + '/' + config.version + '/database/' + collection, {'Content-type': 'application/json'},
                        JSON.stringify({
                            parentDocument: parentDocument,
                            parentProperty: parentProperty,
                            parentPropertyType: parentPropertyType,
                            data: data
                        }));
            },
            patchDocument: function(collection, document, data) {
                return http
                    .patch(config.domain + '/' + config.version + '/database/' + collection + '/' + document, {'Content-type': 'application/json'},
                        JSON.stringify({data: data}));
            },
            deleteDocument: function(collection, document) {
                return http
                    .delete(config.domain + '/' + config.version + '/database/' + collection + '/' + document);
            }
        };

        let projects = {
            list: function() {
                return http
                    .get(config.domain + '/' + config.version + '/projects');
            },
            get: function(projectId) {
                return http
                    .get(config.domain + '/' + config.version + '/projects/' + projectId);
            },
            getProjectUsage: function(projectId) {
                return http
                    .get(config.domain + '/' + config.version + '/projects/' + projectId + '/usage');
            },
            create: function(name, teamId, description, logo, url, clients, legalName, legalCountry, legalState, legalCity, legalAddress, legalTaxId) {
                return http
                    .post(config.domain + '/' + config.version + '/projects', {'Content-type': 'application/json'},
                        JSON.stringify({
                            name: name,
                            teamId: teamId,
                            description: description,
                            logo: logo,
                            url: url,
                            clients: clients,
                            legalName: legalName,
                            legalCountry: legalCountry,
                            legalState: legalState,
                            legalCity: legalCity,
                            legalAddress: legalAddress,
                            legalTaxId: legalTaxId
                        }));
            },
            update: function(projectId, name, description, logo, url, clients, legalName, legalCountry, legalState, legalCity, legalAddress, legalTaxId) {
                return http
                    .patch(config.domain + '/' + config.version + '/projects/' + projectId, {'Content-type': 'application/json'},
                        JSON.stringify({
                            name: name,
                            description: description,
                            logo: logo,
                            url: url,
                            clients: clients,
                            legalName: legalName,
                            legalCountry: legalCountry,
                            legalState: legalState,
                            legalCity: legalCity,
                            legalAddress: legalAddress,
                            legalTaxId: legalTaxId
                        }));
            },
            updateOauth: function(projectId, provider, appId, secret) {
                return http
                    .patch(config.domain + '/' + config.version + '/projects/' + projectId + '/oauth', {'Content-type': 'application/json'},
                        JSON.stringify({
                            provider: provider,
                            appId: appId,
                            secret: secret
                        }));
            },
            webhooks: {
                list: function(projectId) {
                    return http
                        .get(config.domain + '/' + config.version + '/projects/' + projectId + '/webhooks');
                },
                get: function(projectId, webhookId) {
                    return http
                        .get(config.domain + '/' + config.version + '/projects/' + projectId + '/webhooks/' + webhookId);
                },
                create: function (projectId, name, events, url, security, httpUser, httpPass) {
                    return http
                        .post(config.domain + '/' + config.version + '/projects/' + projectId + '/webhooks', {'Content-type': 'application/json'},
                            JSON.stringify({name: name, events: events, url: url, security: security, httpUser: httpUser, httpPass: httpPass}));
                },
                update: function (projectId, webhookId, name, events, url, security, httpUser, httpPass) {
                    return http
                        .put(config.domain + '/' + config.version + '/projects/' + projectId + '/webhooks/' + webhookId, {'Content-type': 'application/json'},
                            JSON.stringify({name: name, events: events, url: url, security: security, httpUser: httpUser, httpPass: httpPass}));
                },
                delete: function(projectId, webhookId) {
                    return http
                        .delete(config.domain + '/' + config.version + '/projects/' + projectId + '/webhooks/' + webhookId);
                }
            },
            tasks: {
                list: function(projectId) {
                    return http
                        .get(config.domain + '/' + config.version + '/projects/' + projectId + '/tasks');
                },
                get: function(projectId, taskId) {
                    return http
                        .get(config.domain + '/' + config.version + '/projects/' + projectId + '/tasks/' + taskId);
                },
                create: function (projectId, name, status, schedule, security, httpMethod, httpUrl, httpHeaders, httpUser, httpPass) {
                    return http
                        .post(config.domain + '/' + config.version + '/projects/' + projectId + '/tasks', {'Content-type': 'application/json'},
                            JSON.stringify({
                                name: name,
                                status: status,
                                schedule: schedule,
                                security: security,
                                httpMethod: httpMethod,
                                httpUrl: httpUrl,
                                httpHeaders: httpHeaders,
                                httpUser: httpUser,
                                httpPass: httpPass
                            }));
                },
                update: function (projectId, taskId, name, status, schedule, security, httpMethod, httpUrl, httpHeaders, httpUser, httpPass) {
                    return http
                        .put(config.domain + '/' + config.version + '/projects/' + projectId + '/tasks/' + taskId, {'Content-type': 'application/json'},
                            JSON.stringify({
                                name: name,
                                status: status,
                                security: security,
                                schedule: schedule,
                                httpMethod: httpMethod,
                                httpUrl: httpUrl,
                                httpHeaders: httpHeaders,
                                httpUser: httpUser,
                                httpPass: httpPass
                            }));
                },
                delete: function(projectId, taskId) {
                    return http
                        .delete(config.domain + '/' + config.version + '/projects/' + projectId + '/tasks/' + taskId);
                }
            },
            keys: {
                list: function(projectId) {
                    return http
                        .get(config.domain + '/' + config.version + '/projects/' + projectId + '/keys');
                },
                get: function(projectId, keyId) {
                    return http
                        .get(config.domain + '/' + config.version + '/projects/' + projectId + '/keys/' + keyId);
                },
                create: function (projectId, name, scopes) {
                    return http
                        .post(config.domain + '/' + config.version + '/projects/' + projectId + '/keys', {'Content-type': 'application/json'},
                            JSON.stringify({name: name, scopes: scopes}));
                },
                update: function (projectId, keyId, name, scopes) {
                    return http
                        .put(config.domain + '/' + config.version + '/projects/' + projectId + '/keys/' + keyId, {'Content-type': 'application/json'},
                            JSON.stringify({name: name, scopes: scopes}));
                },
                delete: function(projectId, keyId) {
                    return http
                        .delete(config.domain + '/' + config.version + '/projects/' + projectId + '/keys/' + keyId);
                }
            },
            platforms: {
                list: function(projectId) {
                    return http
                        .get(config.domain + '/' + config.version + '/projects/' + projectId + '/platforms');
                },
                get: function(projectId, platformId) {
                    return http
                        .get(config.domain + '/' + config.version + '/projects/' + projectId + '/platforms/' + platformId);
                },
                create: function (projectId, type, name, key, store, domains) {
                    return http
                        .post(config.domain + '/' + config.version + '/projects/' + projectId + '/platforms', {'Content-type': 'application/json'},
                            JSON.stringify({type: type, name: name, key: key, store: store, domains: domains}));
                },
                update: function (projectId, platformId, name, key, store, domains) {
                    return http
                        .put(config.domain + '/' + config.version + '/projects/' + projectId + '/platforms/' + platformId, {'Content-type': 'application/json'},
                            JSON.stringify({name: name, key: key, store: store, domains: domains}));
                },
                delete: function(projectId, platformId) {
                    return http
                        .delete(config.domain + '/' + config.version + '/projects/' + projectId + '/platforms/' + platformId);
                }
            }
        };

        let billing = {
            currencies: {
                list: function() {
                    return http
                        .get(config.domain + '/' + config.version + '/billing/currencies');
                },
            },
            vaults: {
                list: function() {
                    return http
                        .get(config.domain + '/' + config.version + '/billing/vaults');
                },
                get: function(vaultId) {
                    return http
                        .get(config.domain + '/' + config.version + '/billing/vaults/' + vaultId);
                },
                getToken: function() {
                    return http
                        .get(config.domain + '/' + config.version + '/billing/vaults/token');
                },
                create: function(token, number, type, expireMonth, expireYear, cvv2, firstName, lastName, addressLine1, addressCity, addressCountryCode, addressPostalCode, addressState, addressPhone) {
                    return http
                        .post(config.domain + '/' + config.version + '/billing/vaults', {'Content-type': 'application/json'},
                            JSON.stringify({
                                'token': token,
                                'number': number,
                                'type': type,
                                'expire-month': expireMonth,
                                'expire-year': expireYear,
                                'cvv2': cvv2,
                                'first-name': firstName,
                                'last-name': lastName,
                                'address-line1': addressLine1,
                                'address-city': addressCity,
                                'address-country-code': addressCountryCode,
                                'address-postal-code': addressPostalCode,
                                'address-state': addressState,
                                'address-phone': addressPhone
                            }));
                },
                delete: function(vaultId) {
                    return http
                        .delete(config.domain + '/' + config.version + '/billing/vaults/' + vaultId);
                }
            },
            plans: {
                list: function() {
                    return http
                        .get(config.domain + '/' + config.version + '/billing/plans');
                },
                get: function(planId) {
                    return http
                        .get(config.domain + '/' + config.version + '/billing/plans/' + planId);
                },
                create: function(name, description, amount, currency, frequency, interval) {
                    return http
                        .post(config.domain + '/' + config.version + '/billing/plans', {'Content-type': 'application/json'},
                            JSON.stringify({
                                'name': name,
                                'description': description,
                                'amount': amount,
                                'currency': currency,
                                'frequency': frequency,
                                'interval': interval
                            }));
                },
                delete: function(planId) {
                    return http
                        .delete(config.domain + '/' + config.version + '/billing/plans/' + planId);
                }
            },
            subscriptions: {
                list: function() {
                    return http
                        .get(config.domain + '/' + config.version + '/billing/subscriptions');
                },
                get: function(subscriptionId) {
                    return http
                        .get(config.domain + '/' + config.version + '/billing/subscriptions/' + subscriptionId);
                },
                create: function(resource, plan, vault, team) {
                    return http
                        .post(config.domain + '/' + config.version + '/billing/subscriptions', {'Content-type': 'application/json'},
                            JSON.stringify({
                                'resource': resource,
                                'plan': plan,
                                'vault': vault,
                                'team': team
                            }));
                },
                update: function(subscriptionId, plan, vault) {
                    return http
                        .put(config.domain + '/' + config.version + '/billing/subscriptions/' + subscriptionId, {'Content-type': 'application/json'},
                            JSON.stringify({'plan': plan, 'vault': vault}));
                },
                delete: function(subscriptionId) {
                    return http
                        .delete(config.domain + '/' + config.version + '/billing/subscriptions/' + subscriptionId);
                }
            },
            invoices: {
                list: function() {
                    return http
                        .get(config.domain + '/' + config.version + '/billing/invoices');
                },
                get: function(id) {
                    return http
                        .get(config.domain + '/' + config.version + '/billing/invoices/' + id);
                },
                getForPrint: function(invoiceId) {
                    return config.domain + '/' + config.version + '/billing/invoices/' + invoiceId + '/print?project=' + config.project;
                },
                getForDownload: function(invoiceId) {
                    return config.domain + '/' + config.version + '/billing/invoices/' + invoiceId + '/print?download=1&project=' + config.project;
                }
            }
        };

        let geo = {
            get: function() {
                return http
                    .get(config.domain + '/' + config.version + '/locale');
            },
            countries: {
                list: function() {
                    return http
                        .get(config.domain + '/' + config.version + '/locale/countries');
                },
                eu: function() {
                    return http
                        .get(config.domain + '/' + config.version + '/locale/countries/eu');
                },
                phones: function() {
                    return http
                        .get(config.domain + '/' + config.version + '/locale/countries/phones');
                }
            }
        };

        return {
            config: config,
            users: users,
            auth: auth,
            account: account,
            database: database,
            storage: storage,
            teams: teams,
            projects: projects,
            billing: billing,
            geo: geo
        };
    };

})(window);