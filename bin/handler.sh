#!/bin/bash

# Get this video's S3 path from SQS.
aws sqs receive-message --queue-url https://sqs.us-east-1.amazonaws.com/947603853016/rs-video-harvester.fifo

# Here's a sample of what this returns:
# 
# MESSAGES	http://s3.amazon.com/video.richmondsunlight.com/senate/floor/20180110.mp4	098f6bcd4621d373cade4e832627b4f6	d9a2404a-7b81-4210-9ab8-8695fd0ff4f5	AQEB0JNnw3KMOruX2+oEZB1UZxZSvsoxgdKUSC6u9wjKKPvYiSsScbEayGJkKBdaWJhKi1Pbhe/4scTsccMK3FyvEjIf+FjAsGQtIBJzcamIstxAtuv9qR63gFs06Xk1zN4jkyhDJ+oF55m8zFFnYDzL/a0zlV/xZyyWVB1sUj1QwwcR5QXgw7T72szFJg4WYMrFIv5Q0768bvI5uviejUeSff+yCusdip0
#
# We just want the URL component, as $S3_URL

# Using the S3 path, figure out the chamber.
if [[ "$S3_URL" == *house* ]]; then
	CHAMBER="house"
elif [[ "$S3_URL" == *senate* ]]; then
	CHAMBER="senate"
fi

# Download the file from S3.
if ! curl "$S3_URL"
then
	echo "Could not retrieve file."
	exit 1
fi

# Start the video processor.
./process-video "$VIDEO_FILE" "$CHAMBER"
