FROM python:3.11-slim

WORKDIR /app

RUN apt-get update && apt-get install -y --no-install-recommends \
    libgl1 \
    libglib2.0-0 \
    && rm -rf /var/lib/apt/lists/*

COPY ai_service/requirements.txt /app/ai_service/requirements.txt
RUN pip install --no-cache-dir -r /app/ai_service/requirements.txt

COPY ai_service /app/ai_service
COPY data /app/data
COPY config /app/config

ENV MEDCONNECT_AI_HOST=0.0.0.0
ENV MEDCONNECT_AI_PORT=8765
ENV PYTHONPATH=/app/ai_service

WORKDIR /app/ai_service
EXPOSE 8765

CMD ["python", "-m", "uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8765"]
