//! Human-readable phrase proofs.

use std::sync::Arc;

use rand::seq::SliceRandom;

use crate::error::AuthError;
use crate::hash::Hash;
use crate::proof::{Proof, ProofBase};

const ADJECTIVES: &[&str] = &[
    "Abundant",
    "Adaptable",
    "Adventurous",
    "Affectionate",
    "Agile",
    "Amiable",
    "Amazing",
    "Ambitious",
    "Amicable",
    "Amusing",
    "Astonishing",
    "Attentive",
    "Authentic",
    "Awesome",
    "Balanced",
    "Beautiful",
    "Bold",
    "Brave",
    "Bright",
    "Bubbly",
    "Calm",
    "Capable",
    "Charismatic",
    "Charming",
    "Cheerful",
    "Clever",
    "Colorful",
    "Compassionate",
    "Confident",
    "Cooperative",
    "Courageous",
    "Courteous",
    "Creative",
    "Curious",
    "Dazzling",
    "Dedicated",
    "Delightful",
    "Determined",
    "Diligent",
    "Dynamic",
    "Easygoing",
    "Effervescent",
    "Efficient",
    "Elegant",
    "Empathetic",
    "Energetic",
    "Enthusiastic",
    "Exuberant",
    "Faithful",
    "Fantastic",
    "Fearless",
    "Flexible",
    "Friendly",
    "Fun-loving",
    "Generous",
    "Gentle",
    "Genuine",
    "Graceful",
    "Gracious",
    "Happy",
    "Hardworking",
    "Harmonious",
    "Helpful",
    "Honest",
    "Hopeful",
    "Humble",
    "Imaginative",
    "Impressive",
    "Incredible",
    "Inspiring",
    "Intelligent",
    "Joyful",
    "Kind",
    "Knowledgeable",
    "Lively",
    "Lovable",
    "Lovely",
    "Loyal",
    "Majestic",
    "Magnificent",
    "Mindful",
    "Modest",
    "Passionate",
    "Patient",
    "Peaceful",
    "Perseverant",
    "Playful",
    "Polite",
    "Positive",
    "Powerful",
    "Practical",
    "Precious",
    "Proactive",
    "Productive",
    "Punctual",
    "Quick-witted",
    "Radiant",
    "Reliable",
    "Resilient",
    "Resourceful",
    "Respectful",
    "Responsible",
    "Sensitive",
    "Serene",
    "Sincere",
    "Skillful",
    "Soothing",
    "Spirited",
    "Splendid",
    "Steadfast",
    "Strong",
    "Supportive",
    "Sweet",
    "Talented",
    "Thankful",
    "Thoughtful",
    "Thriving",
    "Tranquil",
    "Trustworthy",
    "Upbeat",
    "Versatile",
    "Vibrant",
    "Vigilant",
    "Warmhearted",
    "Welcoming",
    "Wholesome",
    "Witty",
    "Wonderful",
    "Zealous",
];

const NOUNS: &[&str] = &[
    "apple",
    "banana",
    "cat",
    "dog",
    "elephant",
    "fish",
    "guitar",
    "hat",
    "ice cream",
    "jacket",
    "kangaroo",
    "lemon",
    "moon",
    "notebook",
    "orange",
    "piano",
    "quilt",
    "rabbit",
    "sun",
    "tree",
    "umbrella",
    "violin",
    "watermelon",
    "xylophone",
    "yogurt",
    "zebra",
    "airplane",
    "ball",
    "cloud",
    "diamond",
    "eagle",
    "fire",
    "giraffe",
    "hammer",
    "island",
    "jellyfish",
    "kiwi",
    "lamp",
    "mango",
    "needle",
    "ocean",
    "pear",
    "quasar",
    "rose",
    "star",
    "turtle",
    "unicorn",
    "volcano",
    "whale",
    "xylograph",
    "yarn",
    "zephyr",
    "ant",
    "book",
    "candle",
    "door",
    "envelope",
    "feather",
    "globe",
    "harp",
    "insect",
    "jar",
    "kite",
    "lighthouse",
    "magnet",
    "necklace",
    "owl",
    "puzzle",
    "queen",
    "rainbow",
    "sailboat",
    "telescope",
    "vase",
    "wallet",
    "yacht",
    "zeppelin",
    "accordion",
    "brush",
    "chocolate",
    "dolphin",
    "easel",
    "fountain",
    "hairbrush",
    "iceberg",
    "jigsaw",
    "kettle",
    "leopard",
    "marble",
    "nutmeg",
    "obstacle",
    "penguin",
    "quiver",
    "raccoon",
    "sphinx",
    "trampoline",
    "utensil",
    "velvet",
    "wagon",
    "xerox",
    "yodel",
    "zipper",
];

/// Human-readable phrase proof (e.g. recovery phrases).
#[derive(Clone, Debug)]
pub struct Phrase {
    base: ProofBase,
}

impl Default for Phrase {
    fn default() -> Self {
        Self::new()
    }
}

impl Phrase {
    /// Create a phrase proof.
    #[must_use]
    pub fn new() -> Self {
        Self {
            base: ProofBase::default(),
        }
    }
}

impl Proof for Phrase {
    fn generate(&self) -> Result<String, AuthError> {
        let mut rng = rand::thread_rng();
        let adjective = ADJECTIVES
            .choose(&mut rng)
            .copied()
            .ok_or_else(|| AuthError::InvalidInput("adjective list is empty".into()))?;
        let noun = NOUNS
            .choose(&mut rng)
            .copied()
            .ok_or_else(|| AuthError::InvalidInput("noun list is empty".into()))?;
        Ok(format!("{adjective} {noun}"))
    }

    fn hash(&self, proof: &str) -> Result<String, AuthError> {
        self.base.hash_proof(proof)
    }

    fn verify(&self, proof: &str, hash: &str) -> bool {
        self.base.verify_proof(proof, hash)
    }

    fn hasher(&self) -> &dyn Hash {
        self.base.hasher()
    }

    fn set_hasher(&mut self, hasher: Arc<dyn Hash>) {
        self.base.set_hasher(hasher);
    }
}
