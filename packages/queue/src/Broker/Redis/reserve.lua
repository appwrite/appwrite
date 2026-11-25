-- KEYS: ready, reservations, reservation, processing, total, processing counter, incoming.
-- Validate cluster placement for finalization before moving anything.
local types = {'list', 'zset', 'list', 'list', 'string', 'string', 'list'}
for i, kind in ipairs(types) do
    local actual = redis.call('TYPE', KEYS[i]).ok
    if actual ~= 'none' and actual ~= kind then return redis.error_reply('WRONGTYPE queue reservation') end
end
local now = redis.call('TIME')
local result = {}
local source = KEYS[7]
for i = 1, tonumber(ARGV[1]) do
    local raw = redis.call('RPOPLPUSH', source, KEYS[3])
    if not raw and source == KEYS[7] then
        source = KEYS[1]
        raw = redis.call('RPOPLPUSH', source, KEYS[3])
    end
    if not raw then break end
    result[#result + 1] = raw
end
if #result > 0 then
    redis.call('ZADD', KEYS[2], tonumber(now[1]) + tonumber(ARGV[2]), KEYS[3])
end
return result
