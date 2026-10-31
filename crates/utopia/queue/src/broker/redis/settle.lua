local function settle(KEYS, ARGV)
    -- KEYS: claim, job, processing, processing counter, outcome counter, failed/dead, owner.
    -- ARGV: token, pid, operation (commit, reject, release), retained payload ttl.
    if ARGV[3] ~= 'commit' and ARGV[3] ~= 'reject' and ARGV[3] ~= 'release' then
        return redis.error_reply('Invalid queue operation')
    end
    local types = {'string', 'string', 'list', 'string', 'string', 'list', 'string'}
    for i, kind in ipairs(types) do
        local actual = redis.call('TYPE', KEYS[i]).ok
        if actual ~= 'none' and actual ~= kind then return redis.error_reply('WRONGTYPE queue settlement') end
    end
    for i = 4, 5 do
        local value = redis.call('GET', KEYS[i])
        if value and not string.match(value, '^%-?%d+$') then return redis.error_reply('Invalid queue counter') end
    end
    if redis.call('GET', KEYS[7]) ~= ARGV[1] then return 0 end
    local raw = redis.call('GET', KEYS[2])
    if ARGV[3] ~= 'commit' and not raw then return redis.error_reply('Queue delivery payload is missing') end
    local removed = redis.call('LREM', KEYS[3], 1, ARGV[2])
    if removed == 0 then return 0 end
    redis.call('DEL', KEYS[1], KEYS[7])
    if ARGV[3] == 'release' then
        redis.call('RPUSH', KEYS[6], raw)
        redis.call('DEL', KEYS[2])
    elseif ARGV[3] == 'commit' then
        redis.call('DEL', KEYS[2])
    else
        redis.call('LPUSH', KEYS[6], ARGV[2])
        if tonumber(ARGV[4]) > 0 then redis.call('EXPIRE', KEYS[2], ARGV[4]) end
    end
    redis.call('DECR', KEYS[4])
    if ARGV[3] ~= 'release' then redis.call('INCR', KEYS[5]) end
    return 1
end
local results = {}
for i = 1, #KEYS, 7 do
    local keys, args = {}, {}
    for j = 0, 6 do keys[j + 1] = KEYS[i + j] end
    local offset = ((i - 1) / 7) * 4
    for j = 1, 4 do args[j] = ARGV[offset + j] end
    local ok, result = pcall(settle, keys, args)
    results[#results + 1] = ok and result or redis.error_reply(result)
end
return results
