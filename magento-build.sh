#!/bin/bash

#
# Allow to cp dot files easily
#
shopt -s dotglob

#
# Read read-write directories (Unix)
#
readarray -t  dirs < .platform-read-write-dirs

#
# Read read-write directories (OS X)
#
#while IFS=: read -r dir; do
#    dirs+=($dir)
#done < <(grep "" .platform-read-write-dirs)


#
# Move directories away
#
for dir in "${dirs[@]}"
do
    mkdir -p ../init/$dir
    cp -R $dir/* ../init/$dir/
    rm -rf $dir
    mkdir $dir
done