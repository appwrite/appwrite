-- KEYS: reservations, reservation, processing, total, processing counter, poison,
-- then triples of job, claim and owner keys. ARGV: lease, token, count, raw/pid pairs, poison bytes.
local now = redis.call('TIME')
local deadline = redis.call('ZSCORE', KEYS[1], KEYS[2])
if not deadline or tonumber(deadline) <= tonumber(now[1]) then return redis.error_reply('Queue reservation expired') end
local count = tonumber(ARGV[3])
for i = 1, #KEYS do
    local kind = i == 1 and 'zset' or ((i == 2 or i == 3 or i == 6) and 'list' or 'string')
    local actual = redis.call('TYPE', KEYS[i]).ok
    if actual ~= 'none' and actual ~= kind then return redis.error_reply('WRONGTYPE queue claim') end
end
for i = 4, 5 do
    local value = redis.call('GET', KEYS[i])
    if value and not string.match(value, '^%-?%d+$') then return redis.error_reply('Invalid queue counter') end
end
for i = 1, count do
    local job, claim, owner = KEYS[4 + 3*i], KEYS[5 + 3*i], KEYS[6 + 3*i]
    redis.call('SET', job, ARGV[2 + 2*i])
    redis.call('SET', owner, ARGV[2])
    redis.call('SET', claim, ARGV[2], 'EX', ARGV[1])
    redis.call('LPUSH', KEYS[3], ARGV[3 + 2*i])
end
for i = 4 + 2*count, #ARGV do redis.call('LPUSH', KEYS[6], ARGV[i]) end
redis.call('INCRBY', KEYS[4], count)
redis.call('INCRBY', KEYS[5], count)
redis.call('DEL', KEYS[2])
redis.call('ZREM', KEYS[1], KEYS[2])
return count
