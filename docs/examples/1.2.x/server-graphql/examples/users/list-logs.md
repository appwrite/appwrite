query {
    usersListLogs(
        userId: "[USER_ID]"
    ) {
        total
        logs {
            event
            userId
            userEmail
            userName
            mode
            ip
            time
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
        }
    }
}
