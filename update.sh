#!/bin/bash -e
php=index.php

if [ $# == 1 ]; then
	dim=128
	file="$@"
	# Skip if already exists
	if php $php thumb-check "$file"; then
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
	convert "$@" -auto-orient -strip -resize ${dim}x${dim} JPEG:- | php $php thumb-update "$file"
	exit
elif [ $# != 0 ]; then
	exit 1
fi

# Update database
php $php update

find -L . -xtype f \( -iname '*.jp*g' -o -iname '*.png' -o -iname '*.gif' -o -iname '*.tif' -o -iname '*.heic' \) | while read f; do
	echo $0 "\"${f#./}\""
done | parallel
