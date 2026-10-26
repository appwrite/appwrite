-- KEYS: owner, heartbeat, job, processing, processing counter.
-- ARGV: expected owner, pid.
-- Drops a claim whose payload is gone: nothing is left to requeue or park.
local types = {'string', 'string', 'string', 'list', 'string'}
for i, kind in ipairs(types) do
    local actual = redis.call('TYPE', KEYS[i]).ok
    if actual ~= 'none' and actual ~= kind then return redis.error_reply('WRONGTYPE queue abandon') end
end
local counter = redis.call('GET', KEYS[5])
if counter and not string.match(counter, '^%-?%d+$') then return redis.error_reply('Invalid queue counter') end
if (redis.call('GET', KEYS[1]) or '') ~= ARGV[1] or redis.call('EXISTS', KEYS[2]) == 1 then return 0 end
if redis.call('EXISTS', KEYS[3]) == 1 then return 0 end
if redis.call('LREM', KEYS[4], 1, ARGV[2]) == 0 then return 0 end
redis.call('DEL', KEYS[1], KEYS[2])
redis.call('DECR', KEYS[5])
return 1
