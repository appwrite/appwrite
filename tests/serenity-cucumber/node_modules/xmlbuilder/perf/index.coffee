builder = require('../src/index')
git = require('git-state')
fs = require('fs')
path = require('path')
{ performance, PerformanceObserver } = require('perf_hooks')

global.xml = builder.create
global.doc = builder.begin

global.perf = (description, count, func) ->

  totalTime = 0

  callback = (userFunction) ->
    startTime = performance.now()
    for i in [1..count]
      userFunction()
    endTime = performance.now()
    totalTime += endTime - startTime
  func(callback)

  averageTime = totalTime / count

  version = require('../package.json').version
  working = gitWorking(gitDir)
  if working then version = version + "*"
  if not perfObj[version] then perfObj[version] = { }

  perfObj[version][description] = averageTime.toFixed(4)

readPerf = (filename) ->
  if not fs.existsSync(filename) then fs.closeSync(fs.openSync(filename, 'w'))
  str = fs.readFileSync(filename, 'utf8')
  if str then JSON.parse(str) else { }

runPerf = (dirPath) ->
  for file from walkDir(dirPath)
    filename = path.basename(file)
    if filename is "index.coffee" or filename is "perf.list" then continue
    require(file)

walkDir = (dirPath) ->
  for file in fs.readdirSync(dirPath)
    filePath = path.join(dirPath, file)
    stat = fs.statSync(filePath)
    if stat.isFile() then yield filePath else if stat.isDirectory() then yield from walkDir(filePath)
  return undefined

gitWorking = (dirPath) ->
  return git.isGitSync(dirPath) and git.dirtySync(dirPath)

printPerf = (perfObj) ->
  sorted = sortByVersion(perfObj)

  for sortedItems in sorted
    version = sortedItems.version
    items = sortedItems.item
    sortedItem = sortByDesc(items)

    if parseVersion(version)[3]
      console.log "\x1b[4mv%s (Working Tree):\x1b[0m", version
    else
      console.log "\x1b[4mv%s:\x1b[0m", version

    longestDescription = 0
    for item in sortedItem
      descriptionLength = item.description.length
      if descriptionLength > longestDescription 
        longestDescription = descriptionLength

    for item in sortedItem
      description = item.description
      averageTime = item.averageTime
      prevItem = findPrevPerf(sorted, version, description)
      if prevItem
        if averageTime < prevItem.item[description]
          console.log "  - \x1b[36m%s\x1b[0m \x1b[1m\x1b[32m%s\x1b[0m ms (v%s was \x1b[1m%s\x1b[0m ms, -\x1b[1m%s\x1b[0m%)", padRight(description, longestDescription), averageTime, prevItem.version, prevItem.item[description], (-100*(averageTime - prevItem.item[description]) / prevItem.item[description]).toFixed(0)
        else if averageTime > prevItem.item[description]
          console.log "  - \x1b[36m%s\x1b[0m \x1b[1m\x1b[31m%s\x1b[0m ms (v%s was \x1b[1m%s\x1b[0m ms, +\x1b[1m%s\x1b[0m%)", padRight(description, longestDescription), averageTime, prevItem.version, prevItem.item[description], (100*(averageTime - prevItem.item[description]) / prevItem.item[description]).toFixed(0)
        else
          console.log "  - \x1b[36m%s\x1b[0m \x1b[1m%s\x1b[0m ms (v%s was \x1b[1m%s\x1b[0m ms,  \x1b[1m%s\x1b[0m%)", padRight(description, longestDescription), averageTime, prevItem.version, prevItem.item[description], (100*(averageTime - prevItem.item[description]) / prevItem.item[description]).toFixed(0)
      else
        console.log "  - \x1b[36m%s\x1b[0m \x1b[1m%s\x1b[0m ms (no previous result)", padRight(description, longestDescription), averageTime

padRight = (str, len) ->
  str + " ".repeat(len - str.length)

writePerf = (filename, perfObj) ->
  writePerfObj = { }
  for version, items of perfObj
    if not parseVersion(version)[3]
      writePerfObj[version] = items
  fs.writeFileSync(filename, JSON.stringify(writePerfObj, null, 2) , 'utf-8')

findPrevPerf = (sorted, version, description) ->
  prev = undefined
  for item in sorted
    if compareVersion(item.version, version) is -1
      if item.item[description]
        prev = item
  return prev

sortByVersion = (perfObj) ->
  sorted = []
  for version, items of perfObj
    sorted.push
      version: version
      item: items
  sorted.sort (item1, item2) ->
    compareVersion(item1.version, item2.version)

sortByDesc = (item) ->
  sorted = []
  for description, averageTime of item
    sorted.push
      description: description
      averageTime: averageTime
  sorted.sort (item1, item2) ->
    if item1.description < item2.description then -1 else 1

parseVersion = (version) ->
  isDirty = version[version.length - 1] is "*"
  if isDirty then version = version.substr(0, version.length - 1)
  v = version.split('.')
  v.push(isDirty)
  return v

compareVersion = (v1, v2) ->
  v1 = parseVersion(v1)
  v2 = parseVersion(v2)

  if v1[0] < v2[0]
    -1
  else if v1[0] > v2[0]
    1
  else # v1[0] = v2[0]
    if v1[1] < v2[1]
      -1
    else if v1[1] > v2[1]
      1
    else # v1[1] = v2[1]
      if v1[2] < v2[2]
        -1
      else if v1[2] > v2[2]
        1
      else # v1[2] = v2[2]
        if v1[3] and not v2[3]
          1
        else if v2[3] and not v1[3]
          -1
        else
          0


perfDir = __dirname
gitDir = path.resolve(__dirname, '..')
perfFile = path.join(perfDir, './perf.list')
perfObj = readPerf(perfFile)
runPerf(perfDir)
printPerf(perfObj)
writePerf(perfFile, perfObj)
