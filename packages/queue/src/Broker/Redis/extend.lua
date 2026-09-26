-- Claim keys and owner keys, with matching tokens. Expired liveness does not surrender ownership.
for i = 1, #KEYS, 2 do
    local token = ARGV[(i + 1) / 2 + 1]
    if redis.call('GET', KEYS[i + 1]) == token then
        redis.call('SET', KEYS[i], token, 'EX', ARGV[1])
    end
end
return 1
