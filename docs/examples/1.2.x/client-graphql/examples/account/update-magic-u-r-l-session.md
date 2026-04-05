mutation {
    accountUpdateMagicURLSession(
        userId: "[USER_ID]",
        secret: "[SECRET]"
    ) {
        _id
        _createdAt
        userId
        expire
        provider
        providerUid
        providerAccessToken
        providerAccessTokenExpiry
        providerRefreshToken
        ip
        osCode
        osName
        osVersion
        clientType
        clientCode
        clientName
        clientVersion
        clientEngine
        clientEngineVersion
        deviceName
        deviceBrand
        deviceModel
        countryCode
        countryName
        current
    }
}
