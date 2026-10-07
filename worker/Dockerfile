FROM nvidia/cuda:12.8.1-cudnn-runtime-ubuntu22.04

RUN apt-get update \
    && apt-get install -y --no-install-recommends python3 python3-pip ffmpeg \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /worker
COPY requirements.txt ./
RUN python3 -m pip install --no-cache-dir --upgrade pip setuptools wheel \
    && python3 -m pip install --no-cache-dir -r requirements.txt
# Large temporary CPU tensors (pyannote's batches) otherwise land in glibc's heap and are never returned:
# diarizing a 3 h stream grew to 6+ GB and was OOM-killed. With a fixed threshold they are mmapped and freed.
ENV MALLOC_MMAP_THRESHOLD_=1048576
COPY . .

CMD ["python3", "entrypoint.py"]
