FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    YOLO_CONFIG_DIR=/tmp/ultralytics

RUN apt-get update && apt-get install -y --no-install-recommends \
    build-essential \
    libgl1 \
    libglib2.0-0 \
    ca-certificates \
    curl \
    && rm -rf /var/lib/apt/lists/* \
    && useradd --create-home --uid 10001 appuser

WORKDIR /app

COPY requirements.txt .

RUN --mount=type=cache,target=/wheels \
    fetch() { \
      url="$1"; out="$2"; \
      exp=$(curl -sI -L "$url" | awk 'tolower($1)=="content-length:"{s=$2} END{gsub(/\r/,"",s); print s}'); \
      [ -z "$exp" ] && exp=9223372036854775807; \
      while :; do \
        got=$(stat -c%s "$out" 2>/dev/null || echo 0); \
        [ "$got" = "$exp" ] && echo "fetched $out" && return 0; \
        curl -fSL --retry 3 --retry-all-errors --max-time 300 -C - -o "$out" "$url" >/dev/null 2>&1 \
          || curl -fSL --max-time 300 -C - -o "$out" "$url" >/dev/null 2>&1; \
        sleep 1; \
      done; \
    } \
    && mkdir -p /wheels \
    && mv /wheels/torch.whl /wheels/torch-2.2.2+cpu-cp312-cp312-linux_x86_64.whl 2>/dev/null || true \
    && mv /wheels/torchvision.whl /wheels/torchvision-0.17.2+cpu-cp312-cp312-linux_x86_64.whl 2>/dev/null || true \
    && fetch "https://download.pytorch.org/whl/cpu/torch-2.2.2%2Bcpu-cp312-cp312-linux_x86_64.whl" /wheels/torch-2.2.2+cpu-cp312-cp312-linux_x86_64.whl \
    && fetch "https://download.pytorch.org/whl/cpu/torchvision-0.17.2%2Bcpu-cp312-cp312-linux_x86_64.whl" /wheels/torchvision-0.17.2+cpu-cp312-cp312-linux_x86_64.whl \
    && pip install --timeout 120 --retries 10 /wheels/torch-2.2.2+cpu-cp312-cp312-linux_x86_64.whl /wheels/torchvision-0.17.2+cpu-cp312-cp312-linux_x86_64.whl \
    && pip install --timeout 120 --retries 10 -r requirements.txt \
    && pip check \
    && apt-get purge -y --auto-remove build-essential curl

COPY --chown=appuser:appuser . .

RUN mkdir -p /app/models /tmp/ultralytics \
    && chown -R appuser:appuser /app /tmp/ultralytics

USER appuser

EXPOSE 8000

CMD ["uvicorn", "api.main:app", "--host", "0.0.0.0", "--port", "8000"]
