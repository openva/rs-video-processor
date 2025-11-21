#!/bin/bash

set -euo pipefail

cd /home/ubuntu/video-processor/

python3 -m pip install --upgrade pip
python3 -m pip install -r requirements.txt

sudo apt-get update
sudo apt-get install -y ffmpeg
