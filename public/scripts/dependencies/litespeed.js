window.ls = window.ls || {}
window.ls.container = (function () {
  const stock = {}
  const listeners = {}
  let namespaces = {}
  const set = function (name, object, singleton, watch = true) {
    if (typeof name !== 'string') {
      throw new Error('var name must be of type string')
    }
    if (typeof singleton !== 'boolean') {
      throw new Error(
        'var singleton "' +
          singleton +
          '" of service "' +
          name +
          '" must be of type boolean'
      )
    }
    stock[name] = {
      name: name,
      object: object,
      singleton: singleton,
      instance: null,
      watch: watch
    }
    if (!watch) {
      return this
    }
    const binds = listeners[name] || {}
    for (const key in binds) {
      if (binds.hasOwnProperty(key)) {
        document.dispatchEvent(new CustomEvent(key))
      }
    }
    return this
  }
  const get = function (name) {
    const service = undefined !== stock[name] ? stock[name] : null
    if (service === null) {
      return null
    }
    if (service.instance) {
      return service.instance
    }
    let instance =
      typeof service.object === 'function'
        ? this.resolve(service.object)
        : service.object
    let skip = false
    if (
      service.watch &&
      name !== 'window' &&
      name !== 'document' &&
      name !== 'element' &&
      typeof instance === 'object' &&
      instance !== null
    ) {
      const handler = {
        name: service.name,
        watch: function () {},
        get: function (target, key) {
          if (key === '__name') {
            return this.name
          }
          if (key === '__watch') {
            return this.watch
          }
          if (key === '__proxy') {
            return true
          }
          if (
            key !== 'constructor' &&
            typeof target[key] === 'function' &&
            [Map, Set, WeakMap, WeakSet].includes(target.constructor)
          ) {
            return target[key].bind(target)
          }
          if (
            typeof target[key] === 'object' &&
            target[key] !== null &&
            !target[key].__proxy
          ) {
            const handler = Object.assign({}, this)
            handler.name = handler.name + '.' + key
            return new Proxy(target[key], handler)
          } else {
            return target[key]
          }
        },
        set: function (target, key, value, receiver) {
          if (key === '__name') {
            return (this.name = value)
          }
          if (key === '__watch') {
            return (this.watch = value)
          }
          target[key] = value
          const path = receiver.__name + '.' + key
          document.dispatchEvent(new CustomEvent(path + '.changed'))
          if (skip) {
            return true
          }
          skip = true
          container.set('$prop', key, true)
          container.set('$value', value, true)
          container.resolve(this.watch)
          container.set('$key', null, true)
          container.set('$value', null, true)
          skip = false
          return true
        }
      }
      instance = new Proxy(instance, handler)
    }
    if (service.singleton) {
      service.instance = instance
    }
    return instance
  }
  const resolve = function (target) {
    if (!target) {
      return () => {}
    }
    const self = this
    const REGEX_COMMENTS = /((\/\/.*$)|(\/\*[\s\S]*?\*\/))/gm
    const REGEX_FUNCTION_PARAMS =
      /(?:\s*(?:function\s*[^(]*)?\s*)((?:[^'"]|(?:(?:(['"])(?:(?:.*?[^\\]\2)|\2))))*?)\s*(?=(?:=>)|{)/m
    const REGEX_PARAMETERS_VALUES =
      /\s*([\w\\$]+)\s*(?:=\s*((?:(?:(['"])(?:\3|(?:.*?[^\\]\3)))((\s*\+\s*)(?:(?:(['"])(?:\6|(?:.*?[^\\]\6)))|(?:[\w$]*)))*)|.*?))?\s*(?:,|$)/gm
    function getParams (func) {
      let functionAsString = func.toString()
      const params = []
      let match
      functionAsString = functionAsString.replace(REGEX_COMMENTS, '')
      functionAsString = functionAsString.match(REGEX_FUNCTION_PARAMS)[1]
      if (functionAsString.charAt(0) === '(') {
        functionAsString = functionAsString.slice(1, -1)
      }
      while ((match = REGEX_PARAMETERS_VALUES.exec(functionAsString))) {
        params.push(match[1])
      }
      return params
    }
    const args = getParams(target)
    return target.apply(
      target,
      args.map(function (value) {
        return self.get(value.trim())
      })
    )
  }
  const path = function (path, value, type) {
    type = type || 'assign'
    path = container.scope(path).split('.')
    const name = path.shift()
    let object = container.get(name)
    let result = null
    while (path.length > 1) {
      if (!object) {
        return null
      }
      object = object[path.shift()]
    }
    const shift = path.shift()
    if (
      value !== null &&
      value !== undefined &&
      object &&
      shift &&
      (object[shift] !== undefined || object[shift] !== null)
    ) {
      switch (type) {
        case 'append':
          if (!Array.isArray(object[shift])) {
            object[shift] = []
          }
          object[shift].push(value)
          break
        case 'prepend':
          if (!Array.isArray(object[shift])) {
            object[shift] = []
          }
          object[shift].unshift(value)
          break
        case 'splice':
          if (!Array.isArray(object[shift])) {
            object[shift] = []
          }
          object[shift].splice(value, 1)
          break
        default:
          object[shift] = value
      }
      return true
    }
    if (!object) {
      return null
    }
    if (!shift) {
      result = object
    } else {
      return object[shift]
    }
    return result
  }
  const bind = function (element, path, callback) {
    const event = container.scope(path) + '.changed'
    const service = event.split('.').slice(0, 1).pop()
    const debug = element.getAttribute('data-debug') || false
    listeners[service] = listeners[service] || {}
    listeners[service][event] = true
    const printer = (function (x) {
      return function () {
        if (!document.body.contains(element)) {
          element = null
          document.removeEventListener(event, printer, false)
          return false
        }
        const oldNamespaces = namespaces
        namespaces = x
        callback()
        namespaces = oldNamespaces
      }
    })(Object.assign({}, namespaces))
    document.addEventListener(event, printer)
  }
  const addNamespace = function (key, scope) {
    namespaces[key] = scope
    return this
  }
  const removeNamespace = function (key) {
    delete namespaces[key]
    return this
  }
  const scope = function (path) {
    for (const [key, value] of Object.entries(namespaces)) {
      path =
        path.indexOf('.') > -1
          ? path.replace(key + '.', value + '.')
          : path.replace(key, value)
    }
    return path
  }
  const container = {
    set: set,
    get: get,
    resolve: resolve,
    path: path,
    bind: bind,
    scope: scope,
    addNamespace: addNamespace,
    removeNamespace: removeNamespace,
    stock: stock,
    listeners: listeners,
    namespaces: namespaces
  }
  set('container', container, true, false)
  return container
})()
window.ls.container.set(
  'http',
  function (document) {
    const globalParams = []
    const globalHeaders = []
    const addParam = function (url, param, value) {
      param = encodeURIComponent(param)
      const a = document.createElement('a')
      param += value ? '=' + encodeURIComponent(value) : ''
      a.href = url
      a.search += (a.search ? '&' : '') + param
      return a.href
    }
    const request = function (method, url, headers, payload, progress) {
      let i
      if (
        [
          'GET',
          'POST',
          'PUT',
          'DELETE',
          'TRACE',
          'HEAD',
          'OPTIONS',
          'CONNECT',
          'PATCH'
        ].indexOf(method) === -1
      ) {
        throw new Error('var method must contain a valid HTTP method name')
      }
      if (typeof url !== 'string') {
        throw new Error('var url must be of type string')
      }
      if (typeof headers !== 'object') {
        throw new Error('var headers must be of type object')
      }
      if (typeof url !== 'string') {
        throw new Error('var url must be of type string')
      }
      for (i = 0; i < globalParams.length; i++) {
        url = addParam(url, globalParams[i].key, globalParams[i].value)
      }
      return new Promise(function (resolve, reject) {
        const xmlhttp = new XMLHttpRequest()
        xmlhttp.open(method, url, true)
        for (i = 0; i < globalHeaders.length; i++) {
          xmlhttp.setRequestHeader(
            globalHeaders[i].key,
            globalHeaders[i].value
          )
        }
        for (const key in headers) {
          if (headers.hasOwnProperty(key)) {
            xmlhttp.setRequestHeader(key, headers[key])
          }
        }
        xmlhttp.onload = function () {
          if (xmlhttp.readyState === 4 && xmlhttp.status === 200) {
            resolve(xmlhttp.response)
          } else {
            document.dispatchEvent(
              new CustomEvent(
                'http-' + method.toLowerCase() + '-' + xmlhttp.status
              )
            )
            reject(new Error(xmlhttp.statusText))
          }
        }
        if (progress) {
          xmlhttp.addEventListener('progress', progress)
          xmlhttp.upload.addEventListener('progress', progress, false)
        }
        xmlhttp.onerror = function () {
          reject(new Error('Network Error'))
        }
        xmlhttp.send(payload)
      })
    }
    return {
      get: function (url) {
        return request('GET', url, {}, '')
      },
      post: function (url, headers, payload) {
        return request('POST', url, headers, payload)
      },
      put: function (url, headers, payload) {
        return request('PUT', url, headers, payload)
      },
      patch: function (url, headers, payload) {
        return request('PATCH', url, headers, payload)
      },
      delete: function (url) {
        return request('DELETE', url, {}, '')
      },
      addGlobalParam: function (key, value) {
        globalParams.push({ key: key, value: value })
      },
      addGlobalHeader: function (key, value) {
        globalHeaders.push({ key: key, value: value })
      }
    }
  },
  true,
  false
)
window.ls.container.set(
  'cookie',
  function (document) {
    function get (name) {
      const value = '; ' + document.cookie
      const parts = value.split('; ' + name + '=')
      if (parts.length === 2) {
        return parts.pop().split(';').shift()
      }
      return null
    }
    function set (name, value, days) {
      const date = new Date()
      date.setTime(date.getTime() + days * 24 * 60 * 60 * 1000)
      const expires = days > 0 ? 'expires=' + date.toUTCString() : 'expires=0'
      document.cookie = name + '=' + value + ';' + expires + ';path=/'
      return this
    }
    return { get: get, set: set }
  },
  true,
  false
)
window.ls.container.set(
  'view',
  function (http, container) {
    const stock = {}
    const execute = function (view, node, container) {
      container.set('element', node, true, false)
      container.resolve(view.controller)
      if (view.repeat !== true) {
        node.removeAttribute(view.selector)
      }
    }
    const parse = function (node, skip, callback) {
      if (node.tagName === 'SCRIPT') {
        return
      }
      if (node.attributes && skip !== true) {
        const attrs = []
        const attrsLen = node.attributes.length
        for (let x = 0; x < attrsLen; x++) {
          attrs.push(node.attributes[x].nodeName)
        }
        if (node.nodeType !== 1) {
          return
        }
        if (attrs && attrsLen) {
          for (let x = 0; x < attrsLen; x++) {
            if (node.$lsSkip === true) {
              break
            }
            const pointer = !/Edge/.test(navigator.userAgent)
              ? x
              : attrsLen - 1 - x
            const length = attrsLen
            const attr = attrs[pointer]
            if (!stock[attr]) {
              continue
            }
            const comp = stock[attr]
            if (typeof comp.template === 'function') {
              comp.template = container.resolve(comp.template)
            }
            if (!comp.template) {
              (function (comp, node, container) {
                execute(comp, node, container)
              })(comp, node, container)
              if (length !== attrsLen) {
                x--
              }
              if (callback) {
                callback()
              }
              continue
            }
            node.classList.remove('load-end')
            node.classList.add('load-start')
            node.$lsSkip = true
            http.get(comp.template).then(
              (function (node, comp) {
                return function (data) {
                  node.$lsSkip = false
                  node.innerHTML = data
                  node.classList.remove('load-start')
                  node.classList.add('load-end');
                  (function (comp, node, container) {
                    execute(comp, node, container)
                  })(comp, node, container)
                  parse(node, true)
                  if (callback) {
                    callback()
                  }
                }
              })(node, comp),
              function (error) {
                throw new Error(
                  'Failed to load comp template: ' + error.message
                )
              }
            )
          }
        }
      }
      if (node.$lsSkip === true) {
        return
      }
      let list = node ? node.childNodes : []
      if (node.$lsSkip === true) {
        list = []
      }
      for (let i = 0; i < list.length; i++) {
        const child = list[i]
        parse(child)
      }
    }
    return {
      stock: stock,
      add: function (object) {
        if (typeof object !== 'object') {
          throw new Error('object must be of type object')
        }
        const defaults = {
          selector: '',
          controller: function () {},
          template: '',
          repeat: false,
          protected: false
        }
        for (const prop in defaults) {
          if (!defaults.hasOwnProperty(prop)) {
            continue
          }
          if (prop in object) {
            continue
          }
          object[prop] = defaults[prop]
        }
        if (!object.selector) {
          throw new Error('View component is missing a selector attribute')
        }
        stock[object.selector] = object
        return this
      },
      render: function (element, callback) {
        parse(element, false, callback)
        element.dispatchEvent(new window.Event('rendered', { bubbles: false }))
      }
    }
  },
  true,
  false
)
window.ls.container.set(
  'router',
  function (window) {
    const getJsonFromUrl = function (URL) {
      let query
      if (URL) {
        const pos = location.search.indexOf('?')
        if (pos === -1) return []
        query = location.search.substr(pos + 1)
      } else {
        query = location.search.substr(1)
      }
      const result = {}
      query.split('&').forEach(function (part) {
        if (!part) {
          return
        }
        part = part.split('+').join(' ')
        const eq = part.indexOf('=')
        let key = eq > -1 ? part.substr(0, eq) : part
        const val = eq > -1 ? decodeURIComponent(part.substr(eq + 1)) : ''
        const from = key.indexOf('[')
        if (from === -1) {
          result[decodeURIComponent(key)] = val
        } else {
          const to = key.indexOf(']')
          const index = decodeURIComponent(key.substring(from + 1, to))
          key = decodeURIComponent(key.substring(0, from))
          if (!result[key]) {
            result[key] = []
          }
          if (!index) {
            result[key].push(val)
          } else {
            result[key][index] = val
          }
        }
      })
      return result
    }
    const states = []
    const params = getJsonFromUrl(window.location.search)
    const hash = window.location.hash
    let current = null
    let previous = null
    const getPrevious = () => previous
    const getCurrent = () => current
    const setPrevious = (value) => {
      previous = value
      return this
    }
    const setCurrent = (value) => {
      current = value
      return this
    }
    const setParam = function (key, value) {
      params[key] = value
      return this
    }
    const getParam = function (key, def) {
      if (key in params) {
        return params[key]
      }
      return def
    }
    const getParams = function () {
      return params
    }
    const getURL = function () {
      return window.location.href
    }
    const add = function (path, view) {
      if (typeof path !== 'string') {
        throw new Error('path must be of type string')
      }
      if (typeof view !== 'object') {
        throw new Error('view must be of type object')
      }
      states[states.length++] = { path: path, view: view }
      return this
    }
    const match = function (location) {
      let url = location.pathname
      if (url.endsWith('/')) {
        url = url.slice(0, -1)
      }
      states.sort(function (a, b) {
        return b.path.length - a.path.length
      })
      states.sort(function (a, b) {
        const n = b.path.split('/').length - a.path.split('/').length
        if (n !== 0) {
          return n
        }
        return b.path.length - a.path.length
      })
      for (let i = 0; i < states.length; i++) {
        const value = states[i]
        value.path =
          value.path.substring(0, 1) !== '/'
            ? location.pathname + value.path
            : value.path
        const match = new RegExp(
          '^' + value.path.replace(/:[^\s/]+/g, '([\\w-]+)') + '$'
        )
        const found = url.match(match)
        if (found) {
          previous = current
          current = value
          return value
        }
      }
      return null
    }
    const change = function (URL, replace) {
      if (!replace) {
        window.history.pushState({}, '', URL)
      } else {
        window.history.replaceState({}, '', URL)
      }
      window.dispatchEvent(new PopStateEvent('popstate', {}))
      return this
    }
    const reload = function () {
      return change(window.location.href)
    }
    return {
      setParam: setParam,
      getParam: getParam,
      getParams: getParams,
      getURL: getURL,
      add: add,
      change: change,
      reload: reload,
      match: match,
      getCurrent: getCurrent,
      setCurrent: setCurrent,
      getPrevious: getPrevious,
      setPrevious: setPrevious,
      params: params,
      hash: hash,
      reset: function () {
        this.params = getJsonFromUrl(window.location.search)
        this.hash = window.location.hash
      }
    }
  },
  true,
  true
)
window.ls.container.set(
  'expression',
  function (container, filter) {
    let paths = []
    return {
      regex: /(\{{.*?\}})/gi,
      parse: function (string, def, cast = false) {
        def = def || ''
        paths = []
        return string
          .replace(this.regex, (match) => {
            let reference = match
              .substring(2, match.length - 2)
              .replace("['", '.')
              .replace("']", '')
              .trim()
            reference = reference.split('|')
            let path = container.scope(reference[0] || '')
            let result = container.path(path)
            path = container.scope(path)
            if (!paths.includes(path)) {
              paths.push(path)
            }
            if (reference.length >= 2) {
              for (let i = 1; i < reference.length; i++) {
                result = filter.apply(reference[i], result)
              }
            }
            if (result === null || undefined === result) {
              result = def
            } else if (typeof result === 'object') {
              result = JSON.stringify(result, null, 4)
            } else if (
              (typeof result === 'object' || typeof result === 'string') &&
              cast
            ) {
              result = "'" + result + "'"
            }
            return result
          })
          .replace(/\\{/g, '{')
          .replace(/\\}/g, '}')
      },
      getPaths: () => paths
    }
  },
  true,
  false
)
window.ls.container.set(
  'filter',
  function (container) {
    const filters = {}
    const add = function (name, callback) {
      filters[name] = callback
      return this
    }
    const apply = function (name, value) {
      container.set('$value', value, true, false)
      return container.resolve(filters[name])
    }
    add('uppercase', ($value) => {
      if (typeof $value !== 'string') {
        return $value
      }
      return $value.toUpperCase()
    })
    add('lowercase', ($value) => {
      if (typeof $value !== 'string') {
        return $value
      }
      return $value.toLowerCase()
    })
    return { add: add, apply: apply }
  },
  true,
  false
)
window.ls.container.get('filter').add('escape', ($value) => {
  if (typeof $value !== 'string') {
    return $value
  }
  return $value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/\"/g, '&quot;')
    .replace(/\'/g, '&#39;')
    .replace(/\//g, '&#x2F;')
})
window.ls = window.ls || {}
window.ls.container
  .set('window', window, true, false)
  .set('document', window.document, true, false)
  .set('element', window.document, true, false)
window.ls.run = function (window) {
  try {
    this.view.render(window.document)
  } catch (error) {
    const handler = window.ls.container.resolve(this.error)
    handler(error)
  }
}
window.ls.error = () => {
  return (error) => {
    console.error('ls-error', error.message, error.stack, error.toString())
  }
}
window.ls.router = window.ls.container.get('router')
window.ls.view = window.ls.container.get('view')
window.ls.filter = window.ls.container.get('filter')
window.ls.container.get('view').add({
  selector: 'data-ls-router',
  controller: function (element, window, document, view, router) {
    const firstFromServer =
      element.getAttribute('data-first-from-server') === 'true'
    const scope = {
      selector: 'data-ls-scope',
      template: false,
      repeat: true,
      controller: function () {}
    }
    const init = function (route) {
      const count = parseInt(element.getAttribute('data-ls-scope-count') || 0)
      element.setAttribute('data-ls-scope-count', count + 1)
      window.scrollTo(0, 0)
      if (window.document.body.scrollTo) {
        window.document.body.scrollTo(0, 0)
      }
      router.reset()
      if (route === null) {
        return
      }
      scope.template =
        undefined !== route.view.template ? route.view.template : null
      scope.controller =
        undefined !== route.view.controller
          ? route.view.controller
          : function () {}
      document.dispatchEvent(new CustomEvent('state-change'))
      if (firstFromServer && router.getPrevious() === null) {
        scope.template = ''
        document.dispatchEvent(new CustomEvent('state-changed'))
      } else if (count === 1) {
        view.render(element, function () {
          document.dispatchEvent(new CustomEvent('state-changed'))
        })
      } else if (router.getPrevious() !== null) {
        view.render(element, function () {
          document.dispatchEvent(new CustomEvent('state-changed'))
        })
      }
    }
    const findParent = function (tagName, el) {
      if ((el.nodeName || el.tagName).toLowerCase() === tagName.toLowerCase()) {
        return el
      }
      while ((el = el.parentNode)) {
        if (
          (el.nodeName || el.tagName).toLowerCase() === tagName.toLowerCase()
        ) {
          return el
        }
      }
      return null
    }
    element.removeAttribute('data-ls-router')
    element.setAttribute('data-ls-scope', '')
    element.setAttribute('data-ls-scope-count', 1)
    view.add(scope)
    document.addEventListener('click', function (event) {
      const target = findParent('a', event.target)
      if (!target) {
        return false
      }
      if (!target.href) {
        return false
      }
      if (event.metaKey) {
        return false
      }
      if (
        target.hasAttribute('target') &&
        target.getAttribute('target') === '_blank'
      ) {
        return false
      }
      if (target.hostname !== window.location.hostname) {
        return false
      }
      const route = router.match(target)
      if (route === null) {
        return false
      }
      event.preventDefault()
      if (window.location === target.href) {
        return false
      }
      route.view.state =
        undefined === route.view.state ? true : route.view.state
      if (route.view.state === true) {
        if (
          router.getPrevious() &&
          router.getPrevious().view &&
          router.getPrevious().view.scope !== route.view.scope
        ) {
          window.location.href = target.href
          return false
        }
        window.history.pushState({}, 'Unknown', target.href)
      }
      init(route)
      return true
    })
    window.addEventListener('popstate', function () {
      init(router.match(window.location))
    })
    window.addEventListener('hashchange', function () {
      init(router.match(window.location))
    })
    init(router.match(window.location))
  }
})
window.ls.container.get('view').add({
  selector: 'data-ls-attrs',
  controller: function (element, expression, container) {
    const attrs = element.getAttribute('data-ls-attrs').trim().split(',')
    let paths = []
    const debug = element.getAttribute('data-debug') || false
    const check = () => {
      container.set('element', element, true, false)
      if (debug) {
        console.info('debug-ls-attrs attributes:', attrs)
      }
      for (let i = 0; i < attrs.length; i++) {
        const attr = attrs[i]
        const key = expression.parse(
          attr.substring(0, attr.indexOf('=')) || attr
        )
        paths = paths.concat(expression.getPaths())
        let value = ''
        if (attr.indexOf('=') > -1) {
          value = expression.parse(attr.substring(attr.indexOf('=') + 1)) || ''
          paths = paths.concat(expression.getPaths())
        }
        if (!key) {
          return null
        }
        element.setAttribute(key, value)
      }
    }
    check()
    for (let i = 0; i < paths.length; i++) {
      const path = paths[i].split('.')
      while (path.length) {
        container.bind(element, path.join('.'), check)
        path.pop()
      }
    }
  }
})
window.ls.container.get('view').add({
  selector: 'data-ls-bind',
  controller: function (element, expression, container) {
    const debug = element.getAttribute('data-debug') || false
    const echo = function (value, bind = true) {
      if (
        element.tagName === 'INPUT' ||
        element.tagName === 'SELECT' ||
        element.tagName === 'BUTTON' ||
        element.tagName === 'TEXTAREA'
      ) {
        const type = element.getAttribute('type')
        if (type === 'radio') {
          if (value.toString() === element.value) {
            element.setAttribute('checked', 'checked')
          } else {
            element.removeAttribute('checked')
          }
          if (bind) {
            element.addEventListener('change', () => {
              for (let i = 0; i < paths.length; i++) {
                if (element.checked) {
                  value = element.value
                }
                container.path(paths[i], value)
              }
            })
          }
          return
        }
        if (type === 'checkbox') {
          if (
            typeof value === 'boolean' ||
            value === 'true' ||
            value === 'false'
          ) {
            if (value === true || value === 'true') {
              element.setAttribute('checked', 'checked')
              element.checked = true
            } else {
              element.removeAttribute('checked')
              element.checked = false
            }
          } else {
            try {
              value = JSON.parse(value)
              element.checked =
                Array.isArray(value) && value.indexOf(element.value) > -1
              value = element.value
            } catch {
              return null
            }
          }
          if (bind) {
            element.addEventListener('change', () => {
              for (let i = 0; i < paths.length; i++) {
                const value = container.path(paths[i])
                const index = value.indexOf(element.value)
                if (element.checked && index < 0) {
                  value.push(element.value)
                }
                if (!element.checked && index > -1) {
                  value.splice(index, 1)
                }
                container.path(paths[i], value)
              }
            })
          }
          return
        }
        if (element.value !== value) {
          element.value = value
          element.dispatchEvent(new Event('change'))
        }
        if (bind) {
          element.addEventListener('input', sync)
          element.addEventListener('change', sync)
        }
      } else {
        if (element.textContent != value) {
          element.textContent = value
        }
      }
    }
    const sync = (() => {
      return () => {
        if (debug) {
          console.info('debug-ls-bind', 'sync-path', paths)
          console.info('debug-ls-bind', 'sync-syntax', syntax)
          console.info('debug-ls-bind', 'sync-syntax-parsed', parsedSyntax)
          console.info('debug-ls-bind', 'sync-value', element.value)
        }
        for (let i = 0; i < paths.length; i++) {
          if ('{{' + paths[i] + '}}' !== parsedSyntax) {
            if (debug) {
              console.info('debug-ls-bind', 'sync-skipped-path', paths[i])
              console.info('debug-ls-bind', 'sync-skipped-syntax', syntax)
              console.info(
                'debug-ls-bind',
                'sync-skipped-syntax-parsed',
                parsedSyntax
              )
            }
            continue
          }
          if (debug) {
            console.info('debug-ls-bind', 'sync-loop-path', paths[i])
            console.info('debug-ls-bind', 'sync-loop-syntax', parsedSyntax)
          }
          container.path(paths[i], element.value)
        }
      }
    })()
    const syntax = element.getAttribute('data-ls-bind')
    const parsedSyntax = container.scope(syntax)
    const unsync = !!element.getAttribute('data-unsync') || false
    const result = expression.parse(syntax)
    const paths = expression.getPaths()
    echo(result, !unsync)
    element.addEventListener('looped', function () {
      echo(expression.parse(parsedSyntax), false)
    })
    for (let i = 0; i < paths.length; i++) {
      const path = paths[i].split('.')
      if (debug) {
        console.info('debug-ls-bind', 'bind-path', path)
        console.info('debug-ls-bind', 'bind-syntax', syntax)
      }
      while (path.length) {
        container.bind(element, path.join('.'), () => {
          echo(expression.parse(parsedSyntax), false)
        })
        path.pop()
      }
    }
  }
})
window.ls.container.get('view').add({
  selector: 'data-ls-if',
  controller: function (element, expression, container, view) {
    let result = ''
    const syntax = element.getAttribute('data-ls-if') || ''
    const debug = element.getAttribute('data-debug') || false
    let paths = []
    const check = () => {
      if (debug) {
        console.info(
          'debug-ls-if',
          expression.parse(
            syntax.replace(/(\r\n|\n|\r)/gm, ' '),
            'undefined',
            true
          )
        )
      }
      try {
        result = eval(
          expression.parse(
            syntax.replace(/(\r\n|\n|\r)/gm, ' '),
            'undefined',
            true
          )
        )
      } catch (error) {
        throw new Error(
          'Failed to evaluate expression "' +
            syntax +
            ' (resulted with: "' +
            result +
            '")": ' +
            error
        )
      }
      if (debug) {
        console.info('debug-ls-if result:', result)
      }
      paths = expression.getPaths()
      const prv = element.$lsSkip
      element.$lsSkip = !result
      if (!result) {
        element.style.visibility = 'hidden'
        element.style.display = 'none'
      } else {
        element.style.removeProperty('display')
        element.style.removeProperty('visibility')
      }
      if (prv === true && element.$lsSkip === false) {
        view.render(element)
      }
    }
    check()
    for (let i = 0; i < paths.length; i++) {
      const path = paths[i].split('.')
      while (path.length) {
        container.bind(element, path.join('.'), check)
        path.pop()
      }
    }
  }
})
window.ls.container.get('view').add({
  selector: 'data-ls-loop',
  template: false,
  nested: false,
  controller: function (element, view, container, window, expression) {
    const expr = expression.parse(element.getAttribute('data-ls-loop'))
    const as = element.getAttribute('data-ls-as')
    const key = element.getAttribute('data-ls-key') || '$index'
    const limit = parseInt(
      expression.parse(element.getAttribute('data-limit') || '') || -1
    )
    const debug = element.getAttribute('data-debug') || false
    const echo = function () {
      let array = container.path(expr)
      let counter = 0
      array = !array ? [] : array
      const watch = !!(array && array.__proxy)
      while (element.hasChildNodes()) {
        element.removeChild(element.lastChild)
        element.lastChild = null
      }
      if (array instanceof Array && typeof array !== 'object') {
        throw new Error(
          'Reference value must be array or object. ' + typeof array + ' given'
        )
      }
      const children = []
      element.$lsSkip = true
      element.style.visibility =
        array.length === 0 && element.style.visibility == ''
          ? 'hidden'
          : 'visible'
      for (const prop in array) {
        if (counter == limit) {
          break
        }
        counter++
        if (!array.hasOwnProperty(prop)) {
          continue
        }
        children[prop] = template.cloneNode(true)
        element.appendChild(children[prop]);
        ((index) => {
          const context = expr + '.' + index
          container.addNamespace(as, context)
          if (debug) {
            console.info('debug-ls-loop', 'index', index)
            console.info('debug-ls-loop', 'context', context)
            console.info(
              'debug-ls-loop',
              'context-path',
              container.path(context).name
            )
            console.info('debug-ls-loop', 'namespaces', container.namespaces)
          }
          container.set(as, container.path(context), true, watch)
          container.set(key, index, true, false)
          view.render(children[prop])
          container.removeNamespace(as)
        })(prop)
      }
      element.dispatchEvent(new Event('looped'))
    }
    const template =
      element.children.length === 1
        ? element.children[0]
        : window.document.createElement('li')
    echo()
    container.bind(element, expr + '.length', echo)
    const path = (expr + '.length').split('.')
    while (path.length) {
      container.bind(element, path.join('.'), echo)
      path.pop()
    }
  }
})
window.ls.container.get('view').add({
  selector: 'data-ls-template',
  template: false,
  controller: function (element, view, http, expression, document, container) {
    const template = element.getAttribute('data-ls-template') || ''
    const type = element.getAttribute('data-type') || 'url'
    const debug = element.getAttribute('data-debug') || false
    let paths = []
    const check = function (init = false) {
      const source = expression.parse(template)
      paths = expression.getPaths()
      element.innerHTML = ''
      if (type === 'script') {
        const inlineTemplate = document.getElementById(source)
        if (inlineTemplate && inlineTemplate.innerHTML) {
          element.innerHTML = inlineTemplate.innerHTML
          element.dispatchEvent(
            new CustomEvent('template-loaded', {
              bubbles: true,
              cancelable: false
            })
          )
        } else {
          if (debug) {
            console.error('Missing template "' + source + '"')
          }
        }
        if (!init) {
          view.render(element)
        }
        return
      }
      http.get(source).then(
        (function (element) {
          return function (data) {
            element.innerHTML = data
            view.render(element)
            element.dispatchEvent(
              new CustomEvent('template-loaded', {
                bubbles: true,
                cancelable: false
              })
            )
          }
        })(element),
        function () {
          throw new Error('Failed loading template')
        }
      )
    }
    check(true)
    for (let i = 0; i < paths.length; i++) {
      const path = paths[i].split('.')
      while (path.length) {
        container.bind(element, path.join('.'), check)
        path.pop()
      }
    }
  }
})
