#!/usr/bin/env bash
set -e

if [ -z "$5" ]; then
    echo "ERROR: Job ID, start time, target, logfile (or NA), and GitHub URL (or NA) must be supplied in arguments."
    exit 1
fi

id=$1
start_time=$2
target=$3
log_file=$4
github_url=$5

# To get the return code, we can run tsp -t, which exits with this value
(tsp -t $id)
rc=$?

# Copy contents of output file into the permanent log file (if specified) for the job
if [ "$log_file" != "NA" ]; then
    output_file=$(tsp -o $id)
    echo -e "\n\n##### CONSOLE OUTPUT #####\n" >> $log_file
    cat $output_file >> $log_file
fi

# Save summary of job in the CSV file
echo "$start_time,$target,$rc,$log_file,$github_url" >> jobs.csv
