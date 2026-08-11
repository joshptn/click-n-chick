# Base image
FROM python:3.12-slim

# Set working directory
WORKDIR /app

# Install system dependencies (if needed)
RUN apt-get update && apt-get install -y \
    build-essential \
    libsqlite3-dev \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Upgrade pip
RUN pip install --upgrade pip

# Copy requirements and install
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Copy app files
COPY . /app

# Expose port for FastAPI
EXPOSE 8001

# Start FastAPI server in development mode with live reload
CMD ["python3","main.py"]
