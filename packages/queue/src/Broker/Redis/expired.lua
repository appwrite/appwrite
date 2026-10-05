-- Discover candidates only; recovery must recheck their deadlines atomically.
local now = redis.call('TIME')
return redis.call('ZRANGEBYSCORE', KEYS[1], '-inf', now[1], 'LIMIT', 0, ARGV[1])
