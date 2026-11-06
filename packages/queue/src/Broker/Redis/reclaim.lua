-- KEYS: owner, heartbeat, job, processing, processing counter, ready/dead.
-- ARGV: expected owner, pid, replacement payload (empty means dead), retained payload ttl.
local types = {'string', 'string', 'string', 'list', 'string', 'list'}
for i, kind in ipairs(types) do
    local actual = redis.call('TYPE', KEYS[i]).ok
    if actual ~= 'none' and actual ~= kind then return redis.error_reply('WRONGTYPE queue reclaim') end
end
local counter = redis.call('GET', KEYS[5])
if counter and not string.match(counter, '^%-?%d+$') then return redis.error_reply('Invalid queue counter') end
if (redis.call('GET', KEYS[1]) or '') ~= ARGV[1] or redis.call('EXISTS', KEYS[2]) == 1 then return 0 end
if redis.call('EXISTS', KEYS[3]) == 0 then return 0 end
if redis.call('LREM', KEYS[4], 1, ARGV[2]) == 0 then return 0 end
redis.call('DEL', KEYS[1], KEYS[2])
redis.call('DECR', KEYS[5])
if ARGV[3] == '' then
    redis.call('LPUSH', KEYS[6], ARGV[2])
    if tonumber(ARGV[4]) > 0 then redis.call('EXPIRE', KEYS[3], ARGV[4]) end
else
    redis.call('LPUSH', KEYS[6], ARGV[3])
    redis.call('DEL', KEYS[3])
end
return 1
