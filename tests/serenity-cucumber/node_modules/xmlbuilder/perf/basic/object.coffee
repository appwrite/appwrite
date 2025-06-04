perf 'Create simple object', 100000, (run) ->
  obj =
    ele: "simple element"
    person:
      name: "John"
      '@age': 35
      '?pi': 'mypi'
      '#comment': 'Good guy'
      '#cdata': 'well formed!'
      unescaped:
        '#raw': '&<>&'
      address:
        city: "Istanbul"
        street: "End of long and winding road"
      contact:
        phone: [ "555-1234", "555-1235" ]
      id: () -> return 42
      details:
        '#text': 'classified'

  run () -> xml(obj)
