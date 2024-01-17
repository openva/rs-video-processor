#!/usr/bin/env bash

# If the system hasn't been up for 15 minutes, don't go any farther
UPTIME=$(cut -d " " -f 1 /proc/uptime |awk '{print int($1+0.5)}')
if [ "$UPTIME" -le 900 ]; then
    exit
fi

# Don't shut down if somebody is logged in
USER_COUNT=$(who |wc -l)
if [ "$USER_COUNT" -gt 0 ]; then
    exit
fi

# Get the load average over the past 15 minutes
LOAD_AVERAGE="$(uptime | sed 's/.*load average: //' |cut -d " " -f 1 |grep -oE "([0-9]+\.[0-9]+)")"

# Multiply it by 100 and round it
LOAD_AVERAGE=$(echo "$LOAD_AVERAGE * 100" | bc | awk '{print int($1+0.5)}')

# If the load average is less than 0.05, shut down
if [ "$LOAD_AVERAGE" -le 5 ]; then
    shutdown -h now
fi
