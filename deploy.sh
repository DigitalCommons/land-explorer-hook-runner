#!/bin/bash
set -e
set -x

# This script deploys the latest code for the hook runner

# Pull latest files
git pull

# Copy the output into the directory for the hook-runner app
cp -T -r ./www /var/www/hook-runner
