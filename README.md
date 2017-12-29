# rs-video-processor
The video OCR processor for Richmond Sunlight.

It lives on a compute-optimized EC2 instance. Source updates are delivered via Travis CI -> S3, which the instance pulls updates from on boot. The instance is stopped by default, and only started once [rs-machine](https://github.com/openva/rs-machine/) identifies a new video's availability. rs-machine communicates this information via SQS, though it fires up the rs-video-processor EC2 instance directly. rs-video-processor grabs the first entry from SQS to run through its processing pipeline, and continues to loop over available SQS entries so long as they exist. When the queue is finished, it shuts itself down.

[![Code Climate](https://codeclimate.com/github/openva/rs-video-processor/badges/gpa.svg)](https://codeclimate.com/github/openva/rs-video-processor)

[![Build Status](https://travis-ci.org/openva/rs-video-processor.svg?branch=master)](https://travis-ci.org/openva/rs-video-processor)
