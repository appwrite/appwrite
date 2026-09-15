FROM ollama/ollama:0.12.7

# Preload specific models
ARG MODELS="embeddinggemma"
ENV OLLAMA_KEEP_ALIVE=24h

# Pre-pull models at build time for Docker layer caching
RUN ollama serve & \
    sleep 5 && \
    for m in $MODELS; do \
        echo "Pulling model $m..."; \
        ollama pull $m || exit 1; \
    done && \
    pkill ollama

# Expose Ollama default port
EXPOSE 11434

# On container start, quickly ensure models exist (no re-download unless missing)
ENTRYPOINT ["/bin/bash", "-c", "(sleep 2; for m in $MODELS; do ollama list | grep -q $m || ollama pull $m; done) & exec ollama $0"]
CMD ["serve"]
