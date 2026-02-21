query {
    healthGetCertificate(
        domain: ""
    ) {
        name
        subjectSN
        issuerOrganisation
        validFrom
        validTo
        signatureTypeSN
    }
}
