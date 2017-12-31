# Richmond Sunlight Video Processor
The video OCR processor for [Richmond Sunlight](/openva/richmondsunlight.com/).

## Purpose
This downloads video from the Virginia General Assembly's floor-session video archive and subjects it to various types of analysis. At this writing, that includes [OCRing the on-screen chyrons](https://waldo.jaquith.org/blog/2011/02/ocr-video/), facial recognition, and closed-caption extraction. To come: voice pitch analysis and improved facial recognition.

## History
The video processor was put together, piece by piece, over a decade, as a series of Bash and PHP scripts. This is an effort to consolidate those, and turn them into their own project. At the moment, it's _still_ a series of Bash and PHP scripts, lashed together with twine, but isolating them as their own project will make it easier to standardize them and improve ment.

## Infrastructure
It lives on a compute-optimized EC2 instance. Source updates are delivered via Travis CI -> S3, which the instance pulls updates from on boot. The instance is stopped by default, and only started once [rs-machine](https://github.com/openva/rs-machine/) identifies a new video's availability. rs-machine communicates this information via SQS, though it fires up the rs-video-processor EC2 instance directly. rs-video-processor grabs the first entry from SQS to run through its processing pipeline, and continues to loop over available SQS entries so long as they exist. When the queue is finished, it shuts itself down.

[![Code Climate](https://codeclimate.com/github/openva/rs-video-processor/badges/gpa.svg)](https://codeclimate.com/github/openva/rs-video-processor)

[![Build Status](https://travis-ci.org/openva/rs-video-processor.svg?branch=master)](https://travis-ci.org/openva/rs-video-processor)
