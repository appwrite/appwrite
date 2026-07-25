<?php

namespace Appwrite\Auth;

class Phrase
{
    public static function generate(): string
    {
        $adjectives = ["Abundant", "Adaptable", "Adventurous", "Affectionate", "Agile", "Amiable", "Amazing", "Ambitious", "Amicable", "Amusing", "Astonishing", "Attentive", "Authentic", "Awesome", "Balanced", "Beautiful", "Bold", "Brave", "Bright", "Bubbly", "Calm", "Capable", "Charismatic", "Charming", "Cheerful", "Clever", "Colorful", "Compassionate", "Confident", "Cooperative", "Courageous", "Courteous", "Creative", "Curious", "Dazzling", "Dedicated", "Delightful", "Determined", "Diligent", "Dynamic", "Easygoing", "Effervescent", "Efficient", "Elegant", "Empathetic", "Energetic", "Enthusiastic", "Exuberant", "Faithful", "Fantastic", "Fearless", "Flexible", "Friendly", "Fun-loving", "Generous", "Gentle", "Genuine", "Graceful", "Gracious", "Happy", "Hardworking", "Harmonious", "Helpful", "Honest", "Hopeful", "Humble", "Imaginative", "Impressive", "Incredible", "Inspiring", "Intelligent", "Joyful", "Kind", "Knowledgeable", "Lively", "Lovable", "Lovely", "Loyal", "Majestic", "Magnificent", "Mindful", "Modest", "Passionate", "Patient", "Peaceful", "Perseverant", "Playful", "Polite", "Positive", "Powerful", "Practical", "Precious", "Proactive", "Productive", "Punctual", "Quick-witted", "Radiant", "Reliable", "Resilient", "Resourceful", "Respectful", "Responsible", "Sensitive", "Serene", "Sincere", "Skillful", "Soothing", "Spirited", "Splendid", "Steadfast", "Strong", "Supportive", "Sweet", "Talented", "Thankful", "Thoughtful", "Thriving", "Tranquil", "Trustworthy", "Upbeat", "Versatile", "Vibrant", "Vigilant", "Warmhearted", "Welcoming", "Wholesome", "Witty", "Wonderful", "Zealous"];

        $nouns = ["apple", "banana", "cat", "dog", "elephant", "fish", "guitar", "hat", "ice cream", "jacket", "kangaroo", "lemon", "moon", "notebook", "orange", "piano", "quilt", "rabbit", "sun", "tree", "umbrella", "violin", "watermelon", "xylophone", "yogurt", "zebra", "airplane", "ball", "cloud", "diamond", "eagle", "fire", "giraffe", "hammer", "island", "jellyfish", "kiwi", "lamp", "mango", "needle", "ocean", "pear", "quasar", "rose", "star", "turtle", "unicorn", "volcano", "whale", "xylograph", "yarn", "zephyr", "ant", "book", "candle", "door", "envelope", "feather", "globe", "harp", "insect", "jar", "kite", "lighthouse", "magnet", "necklace", "owl", "puzzle", "queen", "rainbow", "sailboat", "telescope", "umbrella", "vase", "wallet", "xylograph", "yacht", "zeppelin", "accordion", "brush", "chocolate", "dolphin", "easel", "fountain", "globe", "hairbrush", "iceberg", "jigsaw", "kettle", "leopard", "marble", "nutmeg", "obstacle", "penguin", "quiver", "raccoon", "sphinx", "trampoline", "utensil", "velvet", "wagon", "xerox", "yodel", "zipper"];

        $adjective = $adjectives[array_rand($adjectives)];
        $noun = $nouns[array_rand($nouns)];

        $phrase = "{$adjective} {$noun}";

        return $phrase;
    }
}
