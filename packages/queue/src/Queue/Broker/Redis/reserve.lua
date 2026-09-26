-- KEYS: ready, reservations, reservation, processing, total, processing counter.
-- Validate cluster placement for finalization before moving anything.
local types = {'list', 'zset', 'list', 'list', 'string', 'string'}
for i, kind in ipairs(types) do
    local actual = redis.call('TYPE', KEYS[i]).ok
    if actual ~= 'none' and actual ~= kind then return redis.error_reply('WRONGTYPE queue reservation') end
end
local now = redis.call('TIME')
local deadline = redis.call('ZSCORE', KEYS[2], KEYS[3])
if ARGV[4] == '1' and (not deadline or tonumber(deadline) <= tonumber(now[1])) then
    return redis.error_reply('Queue reservation expired')
end
local result = {}
for i = 1, tonumber(ARGV[1]) do
    local raw = redis.call('RPOPLPUSH', KEYS[1], KEYS[3])
    if not raw then break end
    result[#result + 1] = raw
end
if #result > 0 or tonumber(ARGV[3]) > 0 then
    redis.call('ZADD', KEYS[2], tonumber(now[1]) + tonumber(ARGV[2]) + tonumber(ARGV[3]), KEYS[3])
end
return result
