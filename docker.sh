#!/bin/bash

docker build -t rs-video-processor ./deploy/
docker run --rm --interactive --tty \
  --volume $PWD:/app \
  --user $(id -u):$(id -g) \
  composer install
