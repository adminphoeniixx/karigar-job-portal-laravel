# The screening agent as a long-running worker.
#
# It is a worker, not a web service: it holds a websocket open to LiveKit and
# waits to be handed calls. Nothing ever connects *to* it, so it exposes no
# port and needs no health endpoint.

FROM python:3.12-slim

WORKDIR /app

# libgomp is onnxruntime's runtime dependency, pulled in by the turn detector.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libgomp1 ca-certificates \
    && rm -rf /var/lib/apt/lists/*

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY agent.py .

# Model weights for turn detection and VAD. Done at build time so a cold start
# is not spent downloading them — a worker that boots slowly means a worker
# that misses the first call after a deploy.
#
# The placeholders are only to satisfy the module-level env reads; downloading
# weights talks to no LiveKit project and no webhook.
RUN SCREENING_WEBHOOK_URL=http://build.invalid \
    SCREENING_WEBHOOK_SECRET=build \
    python agent.py download-files

CMD ["python", "agent.py", "start"]
