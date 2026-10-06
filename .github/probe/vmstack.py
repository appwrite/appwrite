import string

import gdb

INTERESTING = (
    'Swoole\\Coroutine\\Http\\Client',
    'Utopia\\Client\\Adapter\\SwooleCoroutine\\Client',
    'Utopia\\Client\\Client',
)
PRINTABLE = set(string.printable.encode())


def inferior():
    return gdb.selected_inferior()


def readable(address, size=8):
    if address < 0x10000:
        return False
    try:
        inferior().read_memory(address, size)
        return True
    except gdb.MemoryError:
        return False


def zend_string(pointer):
    address = int(pointer)
    if not readable(address, 32):
        return None
    length = int(gdb.Value(address + 16).cast(gdb.lookup_type('size_t').pointer()).dereference())
    if length <= 0 or length > 512 or not readable(address + 24, length):
        return None
    raw = bytes(inferior().read_memory(address + 24, length))
    if not all(byte in PRINTABLE for byte in raw):
        return None
    return raw.decode()


def describe_frame(address, execute_data_type, op_size):
    execute_data = gdb.Value(address).cast(execute_data_type).dereference()
    function_address = int(execute_data['func'])
    if not readable(function_address, 64):
        return None
    function = execute_data['func'].dereference()
    kind = int(function['type'])
    if kind not in (1, 2):
        return None
    name = '{main}'
    if int(function['common']['function_name']) != 0:
        name = zend_string(function['common']['function_name'])
        if name is None:
            return None
    scope = ''
    if int(function['common']['scope']) != 0:
        scope_address = int(function['common']['scope'])
        if not readable(scope_address, 16):
            return None
        scope_name = zend_string(function['common']['scope']['name'])
        if scope_name is None:
            return None
        scope = scope_name + '::'
    if kind == 1:
        this = describe_this(execute_data)
        return '%#x internal %s%s()%s' % (address, scope, name, this)
    filename = zend_string(function['op_array']['filename'])
    if filename is None:
        return None
    opcodes = int(function['op_array']['opcodes'])
    last = int(function['op_array']['last'])
    opline = int(execute_data['opline'])
    location = filename
    if opcodes <= opline < opcodes + last * op_size and (opline - opcodes) % op_size == 0:
        location = '%s:%d' % (filename, int(execute_data['opline'].dereference()['lineno']))
    elif opline != 0:
        location = filename + ' (opline outside function)'
    return '%#x user %s%s() %s%s' % (address, scope, name, location, describe_this(execute_data))


def describe_this(execute_data):
    try:
        type_info = int(execute_data['This']['u1']['type_info']) & 0xff
        if type_info != 8:
            return ''
        return ' this=' + describe_object(execute_data['This']['value']['obj'])
    except gdb.error:
        return ''


def class_name(object_value):
    if not readable(int(object_value), 24):
        return None
    class_entry = object_value.dereference()['ce']
    if not readable(int(class_entry), 16):
        return None
    return zend_string(class_entry.dereference()['name'])


def describe_object(object_value):
    address = int(object_value)
    if not readable(address, 40):
        return '%#x(unreadable)' % address
    zend_object = object_value.dereference()
    return '%#x(#%d %s rc=%d)' % (
        address,
        int(zend_object['handle']),
        class_name(object_value),
        int(zend_object['gc']['refcount']),
    )


def scan_vm_stack(executor_globals):
    execute_data_type = gdb.lookup_type('zend_execute_data').pointer()
    op_size = gdb.lookup_type('zend_op').sizeof
    zval_size = gdb.lookup_type('zval').sizeof
    stack = executor_globals['vm_stack']
    top = int(executor_globals['vm_stack_top'])
    page = 0
    print('--- VM stack scan (current coroutine), newest page first; frames listed bottom-up')
    while int(stack) != 0 and page < 64 and readable(int(stack), 24):
        start = int(stack) + 2 * zval_size
        end = top if page == 0 else int(stack['top'])
        print('page %d %#x..%#x' % (page, start, end))
        if end <= start or end - start > 64 * 1024 * 1024:
            end = int(stack['end'])
        address = start
        while address < end:
            try:
                line = describe_frame(address, execute_data_type, op_size)
            except gdb.error:
                line = None
            if line is not None:
                print('  ' + line)
            address += zval_size
        stack = stack['prev']
        page += 1


def scan_objects(executor_globals):
    store = executor_globals['objects_store']
    buckets = int(store['object_buckets'])
    top = int(store['top'])
    pointer_type = gdb.lookup_type('zend_object').pointer()
    zval_type = gdb.lookup_type('zval')
    print('--- live objects of HTTP client classes (objects_store top=%d)' % top)
    for handle in range(1, min(top, 2000000)):
        slot = buckets + handle * 8
        if not readable(slot):
            break
        raw = int(gdb.Value(slot).cast(pointer_type.pointer()).dereference())
        if raw == 0 or raw & 1:
            continue
        object_value = gdb.Value(raw).cast(pointer_type)
        name = class_name(object_value)
        if name not in INTERESTING:
            continue
        zend_object = object_value.dereference()
        count = int(zend_object['ce'].dereference()['default_properties_count'])
        table = raw + pointer_type.target().fields()[-1].bitpos // 8
        slots = []
        for index in range(min(count, 64)):
            value = gdb.Value(table + index * zval_type.sizeof).cast(zval_type.pointer()).dereference()
            kind = int(value['u1']['type_info']) & 0xff
            if kind == 8:
                slots.append('%d:obj#%d' % (index, int(value['value']['obj'].dereference()['handle'])) if readable(int(value['value']['obj']), 16) else '%d:obj?' % index)
            elif kind == 4:
                slots.append('%d:long=%d' % (index, int(value['value']['lval'])))
            else:
                slots.append('%d:t%d' % (index, kind))
        print('  #%d %s %#x rc=%d type_info=%#x props=[%s]' % (
            handle,
            name,
            raw,
            int(zend_object['gc']['refcount']),
            int(zend_object['gc']['u']['type_info']),
            ' '.join(slots),
        ))


class ProbeStack(gdb.Command):
    def __init__(self):
        super().__init__('probestack', gdb.COMMAND_USER)

    def invoke(self, argument, from_tty):
        executor_globals = gdb.parse_and_eval('(zend_executor_globals *) &executor_globals').dereference()
        print('EG(current_execute_data)=%#x vm_stack=%#x vm_stack_top=%#x' % (
            int(executor_globals['current_execute_data']),
            int(executor_globals['vm_stack']),
            int(executor_globals['vm_stack_top']),
        ))
        for step in (scan_vm_stack, scan_objects):
            try:
                step(executor_globals)
            except Exception as error:
                print('%s failed: %s' % (step.__name__, error))


ProbeStack()
