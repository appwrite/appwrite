appwrite databases createCollection \
        --databaseId [DATABASE_ID] \
        --collectionId [COLLECTION_ID] \
        --name [NAME] \
        --permission document \
        --read "role:all" \
        --write "role:all"
