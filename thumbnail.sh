#!/bin/bash -e
if [ $# == 1 ]; then
	dim=128
	php=index.php
	file="$@"
	# Skip if already exists
	if php $php check "$file"; then
		exit 0
	fi
	# Update thumbnail
	echo "Update: $file"
	# -interpolate nearest
	dim=$(identify -format "%[fx:min($dim,max(w,h))]\n" "$file")
	dim=$(echo "$dim" | sort -rn | head -n1)
	if [ $dim == 0 ]; then
		echo "Failed: $file"
		exit 1
	fi
	convert "$@" -auto-orient -strip -resize ${dim}x${dim} JPEG:- | php $php update "$file"
	exit
elif [ $# != 0 ]; then
	exit 1
fi

find -L . -type f \( -iname '*.jpg' -o -iname '*.png' -o -iname '*.gif' -o -iname '*.tif' -o -iname '*.heic' \) | while read f; do
	echo $0 "\"${f#./}\""
done | parallel
