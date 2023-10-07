# Sample database records
database_records = [
    {'id': 'xyz1', 'text': 'Placeholder text is text that temporarily holds a place in a document for typesetting and layout.'},
    {'id': 'xyz2', 'text': 'Get out of this setting.'},
]

# Function to search by emoji
def search_by_emoji(emoji_query):
    results = []
    for record in database_records:
        if emoji_query in record['text']:
            results.append(record)
    return results

# Function to perform partial text matching
def partial_text_match(user_query):
    results = []
    for record in database_records:
        text = record['text'].lower()  # Convert to lowercase for case-insensitive matching
        if user_query.lower() in text:
            results.append(record)
    return results

# Function to rank results by matching characters
def rank_by_matching_characters(user_query):
    results = partial_text_match(user_query)
    results.sort(key=lambda x: text_match_score(x['text'], user_query), reverse=True)
    return results

# Function to calculate the matching score based on character count
def text_match_score(text, query):
    return sum(1 for char in text if char in query)

# Example usage
emoji_results = search_by_emoji('😂')
partial_match_results = partial_text_match('holder')
ranking_results = rank_by_matching_characters('out')

print("Emoji Results:")
for result in emoji_results:
    print(result)

print("\nPartial Text Match Results:")
for result in partial_match_results:
    print(result)

print("\nRanking Results:")
for result in ranking_results:
    print(result)
