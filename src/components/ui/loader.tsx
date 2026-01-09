import { motion, AnimatePresence } from "motion/react";
import { useEffect, useState } from "react";
import { useTheme } from "next-themes";

interface FullscreenLoaderProps {
  isVisible: boolean;
  onComplete?: () => void;
}

const inspiringQuotes = [
  "The best code is no code at all.",
  "Simplicity is the ultimate sophistication.",
  "The best error message is the one that never shows up.",
  "There are only two kinds of languages. The ones people complain about and the ones nobody uses.",
  "The computer is incredibly fast, accurate, and stupid. Man is incredibly slow, inaccurate, and brilliant.",
  "The hardest bugs are the ones where the code does exactly what you told it to do.",
  "Every line of code is a liability until proven otherwise.",
  "The best architecture is the one that makes the system easy to change.",
  "Performance is a feature, not an afterthought.",
  "The most dangerous code is the code you don't understand.",
  "Abstraction is the enemy of performance, but the friend of maintainability.",
  "The best systems are built by people who understand the problem deeply.",
  "Complexity is the enemy of reliability.",
  "The best solutions emerge from constraints, not from freedom.",
  "A good architecture makes the right thing easy and the wrong thing hard.",
  "The most expensive code is the code you have to maintain forever.",
  "The best engineers solve problems they don't yet know exist.",
  "Elegance is not optional. It's a requirement for systems that scale.",
  "The best code is written by people who understand the domain, not just the syntax.",
  "Technical debt is not a loan. It's a mortgage with compound interest.",
  "The most scalable systems are the ones that can be understood by a single person.",
  "The best abstractions leak just enough to be useful.",
  "The hardest part of software is not writing code. It's understanding what code to write.",
  "The best systems are built by teams that value simplicity over cleverness.",
  "The most maintainable code is the code that doesn't need to be maintained.",
  "The best engineers know when not to code.",
];

const QUOTE_STORAGE_KEY = "appwrite:loader:quote";

function getTodayKey() {
  return new Date().toISOString().slice(0, 10); // YYYY-MM-DD
}

function getStoredQuote(): string | null {
  if (typeof window === "undefined") return null;
  try {
    const raw = localStorage.getItem(QUOTE_STORAGE_KEY);
    if (!raw) return null;
    const parsed = JSON.parse(raw) as { quote: string; date: string };
    if (parsed?.date === getTodayKey() && parsed.quote) {
      return parsed.quote;
    }
  } catch {
    // ignore corrupt storage
  }
  return null;
}

function storeQuote(quote: string) {
  if (typeof window === "undefined") return;
  try {
    localStorage.setItem(
      QUOTE_STORAGE_KEY,
      JSON.stringify({ quote, date: getTodayKey() }),
    );
  } catch {
    // ignore storage failures
  }
}

function pickDailyQuote(): string {
  const stored = getStoredQuote();
  if (stored) return stored;

  const random =
    inspiringQuotes[Math.floor(Math.random() * inspiringQuotes.length)];
  storeQuote(random);
  return random;
}

export function FullscreenLoader({ isVisible, onComplete }: FullscreenLoaderProps) {
  const [shouldRender, setShouldRender] = useState(isVisible);
  const [mounted, setMounted] = useState(false);
  const { theme, resolvedTheme } = useTheme();
  const [quote, setQuote] = useState<string>('');

  // Determine which logo to use based on theme
  // appwrite-light.svg has dark fill (#19191C) - use on light backgrounds
  // appwrite-dark.svg has light fill (#EDEDF0) - use on dark backgrounds
  // Wait for theme to be mounted to avoid hydration mismatch
  useEffect(() => {
    setMounted(true);
  }, []);

  // Use resolvedTheme when available (handles system theme), otherwise fall back to theme
  // Default to dark mode if theme is not yet resolved (matches defaultTheme="dark" in ThemeProvider)
  const isDark = mounted 
    ? (resolvedTheme ?? theme) === 'dark'
    : true; // Default to dark during SSR/initial render
  const logoSrc = isDark ? '/appwrite-dark.svg' : '/appwrite-light.svg';

  // Pick the daily quote once on mount (avoids post-load text swaps)
  useEffect(() => {
    const todayQuote = pickDailyQuote();
    setQuote(todayQuote);
  }, []);

  useEffect(() => {
    if (isVisible) {
      setShouldRender(true);
    } else {
      // Delay unmounting to allow fade-out animation to complete
      const timer = setTimeout(() => {
        setShouldRender(false);
        onComplete?.();
      }, 500); // Match the exit animation duration
      return () => clearTimeout(timer);
    }
  }, [isVisible, onComplete]);

  return (
    <AnimatePresence>
      {shouldRender && (
        <motion.div
          initial={{ opacity: 1 }}
          exit={{ opacity: 0 }}
          transition={{ duration: 0.5, ease: "easeInOut" }}
          className="fixed inset-0 z-[9999] bg-background"
        >
          {/* Loader content */}
          <div className="absolute inset-0 flex flex-col items-center justify-center">
            <div className="flex flex-col items-center gap-4 px-6 text-center min-h-[140px]">
              <div className="flex items-center justify-center">
                <div className="w-4 h-4 border-2 border-muted-foreground/30 border-t-muted-foreground rounded-full animate-spin"></div>
              </div>
              <motion.div
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
                transition={{ duration: 0.4, ease: "easeInOut", delay: 0.1 }}
                className="text-sm font-normal leading-relaxed text-muted-foreground/70 max-w-md min-h-[48px]"
              >
                {quote}
              </motion.div>
            </div>
            {/* Appwrite logo at the bottom */}
            <div className="absolute bottom-8">
              <img
                src={logoSrc}
                alt="Appwrite"
                className="h-6 w-auto"
              />
            </div>
          </div>
        </motion.div>
      )}
    </AnimatePresence>
  );
}

